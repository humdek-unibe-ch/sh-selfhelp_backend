<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Service;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginFeatureFlag;
use App\Entity\Plugin\PluginOperation;
use App\Entity\Plugin\PluginSource;
use App\Exception\ServiceException;
use App\Plugin\Archive\PluginArchiveInspectionService;
use App\Plugin\Lifecycle\InstallModeResolver;
use App\Plugin\Lifecycle\PluginEnabler;
use App\Plugin\Lifecycle\PluginInstaller;
use App\Plugin\Lifecycle\PluginLockFileReader;
use App\Plugin\Lifecycle\PluginPurger;
use App\Plugin\Lifecycle\PluginRepairer;
use App\Plugin\Lifecycle\PluginRollbacker;
use App\Plugin\Lifecycle\PluginSafeMode;
use App\Plugin\Lifecycle\PluginUninstaller;
use App\Plugin\Lifecycle\PluginUpdater;
use App\Plugin\Manifest\ManifestResolver;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\Registry\RegistryClient;
use App\Plugin\Registry\PluginSourceUrlResolver;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Repository\Plugin\PluginFeatureFlagRepository;
use App\Repository\Plugin\PluginOperationRepository;
use App\Repository\Plugin\PluginRepository;
use App\Repository\Plugin\PluginSourceRepository;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin admin-facing facade over the plugin lifecycle orchestrators.
 *
 * Controllers call into this service and never reach for orchestrators
 * directly, so the API layer stays consistent. The facade also
 * implements the read-side queries (list plugins, list operations,
 * list sources) and emits structured DTO arrays for the response
 * formatter.
 */
final class PluginAdminService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationRepository $operations,
        private readonly PluginSourceRepository $sources,
        private readonly PluginFeatureFlagRepository $featureFlags,
        private readonly PluginInstaller $installer,
        private readonly PluginUpdater $updater,
        private readonly PluginEnabler $enabler,
        private readonly PluginUninstaller $uninstaller,
        private readonly PluginPurger $purger,
        private readonly PluginRollbacker $rollbacker,
        private readonly PluginRepairer $repairer,
        private readonly PluginSafeMode $safeMode,
        private readonly PluginLockFileReader $lockFileReader,
        private readonly PluginCompatibilityValidator $compatibility,
        private readonly InstallModeResolver $installModeResolver,
        private readonly RegistryClient $registryClient,
        private readonly PluginSourceUrlResolver $sourceUrlResolver,
        private readonly ManifestResolver $manifestResolver,
        private readonly PluginArchiveInspectionService $archiveInspectionService,
        private readonly HttpClientInterface $httpClient,
        private readonly string $cmsVersion,
        private readonly string $sdkApiVersion,
    ) {
    }

    /**
     * Browse every enabled `PluginSource` and return a flat list of
     * available plugin entries. Used by the admin UI's "Available"
     * tab so admins can install registry-listed plugins with a single
     * click (the entry already contains a `manifest` field).
     *
     * Already-installed plugins are filtered out so admins do not
     * re-install something that is already managed.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listAvailableFromRegistries(): array
    {
        $installedIds = [];
        foreach ($this->plugins->findAll() as $p) {
            $installedIds[$p->getPluginId()] = true;
        }

        $sourceBaseUrls = [];
        foreach ($this->sources->findEnabled() as $source) {
            $sourceBaseUrls[$source->getName()] = rtrim($this->sourceUrlResolver->resolve($source), '/') . '/';
        }

        $aggregated = $this->registryClient->fetchAllIndexes();
        $available = [];
        foreach ($aggregated as $pluginId => $entriesBySource) {
            if (isset($installedIds[$pluginId])) {
                continue;
            }
            foreach ($entriesBySource as $sourceName => $entry) {
                $resolvedManifestUrl = $this->resolveManifestUrl(
                    $entry['manifestUrl'] ?? null,
                    $sourceBaseUrls[$sourceName] ?? null,
                );

                // Resolve the manifest body server-side when the
                // registry entry only points at a `manifestUrl`. This
                // avoids CORS issues for browsers fetching static
                // hosts (GitHub Pages, S3, etc.) and means the UI
                // gets a ready-to-install manifest in one round-trip.
                $manifest = isset($entry['manifest']) && is_array($entry['manifest']) ? $entry['manifest'] : null;
                if ($manifest === null && is_string($resolvedManifestUrl) && $resolvedManifestUrl !== '') {
                    $manifest = $this->tryFetchManifest($resolvedManifestUrl);
                }

                $available[] = [
                    'sourceName' => $sourceName,
                    'pluginId' => (string) $pluginId,
                    'name' => isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : (string) $pluginId,
                    'description' => isset($entry['description']) && is_string($entry['description']) ? $entry['description'] : null,
                    'version' => isset($entry['version']) && is_string($entry['version']) ? $entry['version'] : '0.0.0',
                    'trustLevel' => isset($entry['trustLevel']) && is_string($entry['trustLevel']) ? $entry['trustLevel'] : 'untrusted',
                    'homepage' => isset($entry['homepage']) && is_string($entry['homepage']) ? $entry['homepage'] : null,
                    'manifest' => $manifest,
                    'manifestUrl' => $resolvedManifestUrl,
                ];
            }
        }
        return $available;
    }

    /**
     * Best-effort server-side fetch of a plugin manifest referenced
     * by a registry `manifestUrl`. Returns `null` on any error so the
     * UI degrades gracefully back to its client-side fetch path. Used
     * to bypass static-host CORS issues and to keep the
     * `available_plugins` payload self-contained.
     *
     * @return array<string,mixed>|null
     */
    private function tryFetchManifest(string $url): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'SelfHelp-Plugin-Manager/1.0',
                ],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return null;
            }
            $body = $response->getContent(false);
            $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        } catch (TransportExceptionInterface) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, array<string,mixed>> */
    public function listPlugins(): array
    {
        // Cross-reference installed plugins against the registry index
        // ONCE and merge the result into each row as `availableUpdate`.
        // The admin "Installed" tab uses this to render an inline
        // "Update available" badge + Update button per row, so there is
        // no need for a separate `/admin/plugins/updates` round-trip.
        $updatesByPluginId = [];
        try {
            foreach ($this->listAvailableUpdates() as $update) {
                if (!isset($update['pluginId']) || !is_string($update['pluginId'])) {
                    continue;
                }
                $updatesByPluginId[$update['pluginId']] = $update;
            }
        } catch (\Throwable) {
            // Registry lookup must never break the installed-plugins
            // list. A flaky registry (timeout / DNS / 5xx / parse error)
            // simply means "no updates surfaced this call"; the rest of
            // the admin page renders normally.
            $updatesByPluginId = [];
        }

        return array_map(
            function (Plugin $p) use ($updatesByPluginId): array {
                $row = $this->formatPlugin($p);
                $row['availableUpdate'] = $updatesByPluginId[$p->getPluginId()] ?? null;
                return $row;
            },
            $this->plugins->findAllOrderedByName()
        );
    }

    /**
     * Cross-reference installed plugins against the registry index to
     * surface available updates. Returns one row per installed plugin
     * that has a strictly-newer entry in any enabled registry source.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listAvailableUpdates(): array
    {
        $aggregated = $this->registryClient->fetchAllIndexes();
        $rows = [];
        foreach ($this->plugins->findAllOrderedByName() as $installed) {
            $pluginId = $installed->getPluginId();
            $entriesBySource = $aggregated[$pluginId] ?? [];
            if ($entriesBySource === []) {
                continue;
            }
            $best = null;
            $bestSource = null;
            foreach ($entriesBySource as $sourceName => $entry) {
                $candidateVersion = is_string($entry['version'] ?? null) ? (string) $entry['version'] : null;
                if ($candidateVersion === null || $candidateVersion === '') {
                    continue;
                }
                if (\App\Plugin\Versioning\SemverHelper::compare($candidateVersion, $installed->getVersion()) <= 0) {
                    continue;
                }
                if ($best === null || \App\Plugin\Versioning\SemverHelper::compare($candidateVersion, (string) ($best['version'] ?? '0.0.0')) > 0) {
                    $best = $entry;
                    $bestSource = $sourceName;
                }
            }
            if ($best === null) {
                continue;
            }
            $rows[] = [
                'pluginId' => $pluginId,
                'name' => $installed->getName(),
                'installedVersion' => $installed->getVersion(),
                'availableVersion' => (string) $best['version'],
                'diffKind' => \App\Plugin\Versioning\SemverHelper::diffKind($installed->getVersion(), (string) $best['version']),
                'sourceName' => $bestSource,
                'trustLevel' => is_string($best['trustLevel'] ?? null) ? $best['trustLevel'] : $installed->getTrustLevel(),
                'manifestUrl' => is_string($best['manifestUrl'] ?? null) ? $best['manifestUrl'] : null,
                'manifest' => is_array($best['manifest'] ?? null) ? $best['manifest'] : null,
                'registryEntry' => $best,
            ];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function getPlugin(string $pluginId): array
    {
        $plugin = $this->mustFindPlugin($pluginId);
        return $this->formatPlugin($plugin, deep: true);
    }

    /**
     * Single entrypoint for every install source. Resolves the source
     * (registry / url / paste / archive) into a `PluginManifest` +
     * `ResolvedSource`, performs the synchronous compatibility checks,
     * and dispatches the async `InstallPluginMessage`.
     *
     * Returns the freshly-created `plugin_operations` row so the UI
     * can subscribe to its Mercure topic.
     *
     * @param array{
     *   source: 'registry'|'url'|'paste'|'archive',
     *   registryEntry?: array<string,mixed>,
     *   sourceName?: string,
     *   manifestUrl?: string,
     *   manifest?: array<string,mixed>,
     * } $input
     * @return array<string,mixed>
     */
    public function install(array $input, ?UploadedFile $archive = null): array
    {
        $resolved = $this->resolveSource($input, $archive);
        $operation = $this->installer->request($resolved['manifest'], $resolved['resolved']);
        return $this->formatOperation($operation);
    }

    /**
     * Internal-only — invoked by the `selfhelp:plugin:run-operation`
     * CLI command after a managed-mode operator has executed composer
     * by hand. The Messenger worker calls `PluginInstaller::finalize()`
     * directly without going through this method.
     */
    public function finalizeInstall(int $operationId, array $manifestData): array
    {
        $op = $this->mustFindOperation($operationId);
        $plugin = $this->installer->finalize($op, new PluginManifest($manifestData));
        return $this->formatPlugin($plugin, deep: true);
    }

    /**
     * Single update entrypoint. Mirrors `install()`. The
     * `expectedPluginId` field is set by `AdminPluginController::update()`
     * from the URL-pinned plugin id; if the resolved manifest declares
     * a different id we reject the operation. This stops an admin from
     * accidentally (or maliciously) updating plugin A with the manifest
     * of plugin B by changing the URL path while keeping the body.
     *
     * @param array{
     *   source: 'registry'|'url'|'paste'|'archive',
     *   registryEntry?: array<string,mixed>,
     *   sourceName?: string,
     *   manifestUrl?: string,
     *   manifest?: array<string,mixed>,
     *   forceMajor?: bool,
     *   backupBefore?: bool,
     *   expectedPluginId?: string,
     * } $input
     * @return array<string,mixed>
     */
    public function update(array $input, ?UploadedFile $archive = null): array
    {
        $resolved = $this->resolveSource($input, $archive);
        $expectedPluginId = isset($input['expectedPluginId']) ? (string) $input['expectedPluginId'] : null;
        if ($expectedPluginId !== null && $expectedPluginId !== '') {
            $actual = $resolved['manifest']->getPluginId();
            if ($actual !== $expectedPluginId) {
                $this->throwValidationError(sprintf(
                    'Plugin id mismatch: URL says "%s" but the resolved manifest declares "%s". Refusing to update plugin "%s" with a manifest for "%s".',
                    $expectedPluginId,
                    $actual,
                    $expectedPluginId,
                    $actual,
                ));
            }
        }
        $operation = $this->updater->request(
            $resolved['manifest'],
            $resolved['resolved'],
            (bool) ($input['forceMajor'] ?? false),
            (bool) ($input['backupBefore'] ?? false),
        );
        return $this->formatOperation($operation);
    }

    /** Internal-only finalize for managed-mode workers. */
    public function finalizeUpdate(int $operationId, array $manifestData): array
    {
        $op = $this->mustFindOperation($operationId);
        $plugin = $this->updater->finalize($op, new PluginManifest($manifestData));
        return $this->formatPlugin($plugin, deep: true);
    }

    /**
     * Pre-install inspection for `.shplugin` uploads. Extracts +
     * validates the archive without dispatching any operation so the
     * frontend can show a preview card (manifest, compatibility,
     * capabilities, signatureStatus, errors).
     *
     * Returns structured data even when validation fails so the UI can
     * show a "cannot install — here is why" panel without surfacing a
     * generic 500. Hard, non-recoverable failures (e.g. the upload is
     * not a .shplugin archive at all, or the staging dir cannot be
     * created) propagate as exceptions so the existing API error
     * envelope catches them.
     *
     * @param array{keyId:string,publicKeyBase64:string}|null $trustedKeyOverride
     *      Optional per-request override forwarded from the controller's
     *      `trustedKeyId` + `trustedKeyBase64` multipart fields. Lets
     *      an operator inspect an archive signed by a publisher whose
     *      key isn't in `SELFHELP_PLUGIN_TRUSTED_KEYS` yet, without
     *      mutating env / lock files. Env keys win on duplicate keyIds.
     *
     * @return array{
     *     ok: bool,
     *     signatureStatus: 'verified'|'invalid'|'unsigned'|'unverifiable',
     *     signature: array{
     *         status: 'verified'|'invalid'|'unsigned'|'unverifiable',
     *         keyId: string|null,
     *         unknownKey: array{keyId:string,envSnippet:string}|null,
     *     },
     *     errors: list<string>,
     *     warnings: list<string>,
     *     manifest: array<string,mixed>|null,
     *     compatibility: array<string,mixed>|null,
     *     capabilities: list<string>,
     *     resolvedSource: array<string,mixed>|null,
     * }
     */
    public function inspectArchive(UploadedFile $archive, ?array $trustedKeyOverride = null): array
    {
        return $this->archiveInspectionService->inspect($archive, $trustedKeyOverride);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{manifest: PluginManifest, resolved: ResolvedSource}
     */
    private function resolveSource(array $input, ?UploadedFile $archive): array
    {
        $source = (string) ($input['source'] ?? '');
        switch ($source) {
            case ResolvedSource::KIND_REGISTRY:
                if (!isset($input['registryEntry']) || !is_array($input['registryEntry'])) {
                    $this->throwValidationError('install.source=registry requires a `registryEntry` object.');
                }
                $sourceName = isset($input['sourceName']) ? (string) $input['sourceName'] : 'registry';
                return $this->manifestResolver->resolveRegistry($input['registryEntry'], $sourceName);

            case ResolvedSource::KIND_URL:
                if (!isset($input['manifestUrl']) || !is_string($input['manifestUrl']) || $input['manifestUrl'] === '') {
                    $this->throwValidationError('install.source=url requires a `manifestUrl`.');
                }
                $registryEntry = isset($input['registryEntry']) && is_array($input['registryEntry']) ? $input['registryEntry'] : null;
                return $this->manifestResolver->resolveUrl((string) $input['manifestUrl'], $registryEntry);

            case ResolvedSource::KIND_PASTE:
                if (!isset($input['manifest']) || !is_array($input['manifest'])) {
                    $this->throwValidationError('install.source=paste requires a `manifest` object.');
                }
                $registryEntry = isset($input['registryEntry']) && is_array($input['registryEntry']) ? $input['registryEntry'] : null;
                return $this->manifestResolver->resolvePaste($input['manifest'], $registryEntry);

            case ResolvedSource::KIND_ARCHIVE:
                if (!$archive instanceof UploadedFile) {
                    $this->throwValidationError('install.source=archive requires a multipart `archive` file part.');
                }
                return $this->manifestResolver->resolveArchive($archive);

            default:
                $this->throwValidationError(sprintf('Unknown install source "%s". Expected registry|url|paste|archive.', $source));
        }
        // unreachable
        throw new \LogicException('resolveSource fell through');
    }

    /** @return array<string,mixed> */
    public function enable(string $pluginId): array
    {
        return $this->formatPlugin($this->enabler->enable($pluginId), deep: true);
    }

    /** @return array<string,mixed> */
    public function disable(string $pluginId): array
    {
        return $this->formatPlugin($this->enabler->disable($pluginId), deep: true);
    }

    /**
     * Single uninstall entrypoint. Creates the `plugin_operations` row
     * and dispatches the asynchronous `UninstallPluginMessage`; the
     * Messenger worker handles `composer remove` and the lock-file +
     * bundles regeneration via `PluginUninstaller::finalize()`.
     *
     * @return array<string,mixed>
     */
    public function uninstall(string $pluginId): array
    {
        return $this->formatOperation($this->uninstaller->request($pluginId));
    }

    /**
     * Internal-only — invoked by `selfhelp:plugin:run-operation` after
     * a managed-mode operator has executed `composer remove`.
     */
    public function finalizeUninstall(int $operationId): void
    {
        $op = $this->mustFindOperation($operationId);
        $this->uninstaller->finalize($op);
    }

    public function purge(string $pluginId, string $confirmedPluginId, bool $backupBefore = false): void
    {
        $this->purger->purge($pluginId, $confirmedPluginId, $backupBefore);
    }

    /** @return array<string,mixed> */
    public function rollback(int $operationId): array
    {
        return $this->formatOperation($this->rollbacker->rollback($operationId));
    }

    /** @return array<string,mixed> */
    public function repair(?string $pluginId = null): array
    {
        if ($pluginId !== null) {
            $plugin = $this->repairer->repairSingle($pluginId);
            return $this->formatPlugin($plugin, deep: true);
        }
        return $this->repairer->repair();
    }

    /** @return array<int, array<string,mixed>> */
    public function listOperations(?string $pluginId = null, int $limit = 100): array
    {
        $operations = $pluginId !== null
            ? $this->operations->findByPluginId($pluginId, $limit)
            : $this->operations->findBy([], ['createdAt' => 'DESC'], $limit);

        return array_map(fn(PluginOperation $op) => $this->formatOperation($op), $operations);
    }

    /** @return array<string,mixed> */
    public function getOperation(int $operationId): array
    {
        return $this->formatOperation($this->mustFindOperation($operationId));
    }

    /**
     * Force-cancel a stuck plugin operation. Mirrors
     * `selfhelp:plugin:cancel-operation` so admins do not have to
     * drop to a shell when a Messenger worker died mid-operation.
     *
     * Only operations in REQUESTED or RUNNING are eligible — any
     * other status is a no-op (idempotent for the UI).
     *
     * @return array<string,mixed>
     */
    public function cancelOperation(int $operationId): array
    {
        $op = $this->mustFindOperation($operationId);
        $status = $op->getStatus();
        if (!in_array($status, [PluginOperation::STATUS_REQUESTED, PluginOperation::STATUS_RUNNING], true)) {
            return $this->formatOperation($op);
        }

        $op->setStatus(PluginOperation::STATUS_CANCELLED);
        $op->setFinishedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $op->appendLog([
            'event' => 'cancelled-by-operator',
            'message' => sprintf(
                'Operation #%d (%s/%s) force-cancelled via admin API. Previous status: %s.',
                $operationId,
                $op->getPluginId(),
                $op->getType(),
                $status
            ),
        ]);
        $this->em->persist($op);
        $this->em->flush();

        return $this->formatOperation($op);
    }

    /** @return array<int, array<string,mixed>> */
    public function listSources(): array
    {
        return array_map(fn(PluginSource $s) => $this->formatSource($s), $this->sources->findAll());
    }

    /** @return array<string,mixed> */
    public function createSource(array $data): array
    {
        $source = new PluginSource(
            (string) $data['name'],
            (string) $data['kind'],
            (string) $data['url'],
        );
        if (isset($data['authHeaderName'])) {
            $source->setAuthHeaderName((string) $data['authHeaderName']);
        }
        if (isset($data['authSecretEnvVar'])) {
            $source->setAuthSecretEnvVar((string) $data['authSecretEnvVar']);
        }
        if (isset($data['channel'])) {
            $source->setChannel((string) $data['channel']);
        }
        if (isset($data['trustLevel'])) {
            $source->setTrustLevel((string) $data['trustLevel']);
        }
        if (isset($data['enabled'])) {
            $source->setEnabled((bool) $data['enabled']);
        }
        $this->em->persist($source);
        $this->em->flush();
        return $this->formatSource($source);
    }

    /** @return array<string,mixed> */
    public function updateSource(int $sourceId, array $data): array
    {
        $source = $this->sources->find($sourceId);
        if (!$source instanceof PluginSource) {
            $this->throwNotFound(sprintf('Plugin source #%d not found.', $sourceId));
        }
        /** @var PluginSource $source */

        if ($source->isSystem()) {
            // Host-managed sources are seeded by Doctrine migrations
            // (e.g. the default `humdek-public` registry). Only the
            // `enabled` flag may be toggled; every other field is
            // immutable to prevent admins from accidentally pointing
            // the official channel at an attacker-controlled URL.
            $mutableFields = ['enabled'];
            foreach (array_keys($data) as $field) {
                if (!in_array($field, $mutableFields, true)) {
                    $this->throwForbidden(sprintf(
                        'Plugin source "%s" is system-managed; field "%s" cannot be modified. Only "enabled" may be toggled.',
                        $source->getName(),
                        (string) $field,
                    ));
                }
            }
        }

        if (array_key_exists('name', $data)) {
            $source->setName((string) $data['name']);
        }
        if (array_key_exists('kind', $data)) {
            $source->setKind((string) $data['kind']);
        }
        if (array_key_exists('url', $data)) {
            $source->setUrl((string) $data['url']);
        }
        if (array_key_exists('authHeaderName', $data)) {
            $source->setAuthHeaderName($data['authHeaderName'] === null ? null : (string) $data['authHeaderName']);
        }
        if (array_key_exists('authSecretEnvVar', $data)) {
            $source->setAuthSecretEnvVar($data['authSecretEnvVar'] === null ? null : (string) $data['authSecretEnvVar']);
        }
        if (array_key_exists('channel', $data)) {
            $source->setChannel((string) $data['channel']);
        }
        if (array_key_exists('trustLevel', $data)) {
            $source->setTrustLevel((string) $data['trustLevel']);
        }
        if (array_key_exists('enabled', $data)) {
            $source->setEnabled((bool) $data['enabled']);
        }
        $source->touchUpdatedAt();
        $this->em->flush();
        return $this->formatSource($source);
    }

    public function deleteSource(int $sourceId): void
    {
        $source = $this->sources->find($sourceId);
        if (!$source instanceof PluginSource) {
            $this->throwNotFound(sprintf('Plugin source #%d not found.', $sourceId));
        }
        /** @var PluginSource $source */
        if ($source->isSystem()) {
            $this->throwForbidden(sprintf(
                'Plugin source "%s" is system-managed and cannot be deleted. Disable it instead if you do not want to use it.',
                $source->getName(),
            ));
        }
        $this->em->remove($source);
        $this->em->flush();
    }

    /** @return array<int, array<string,mixed>> */
    public function listFeatureFlags(string $pluginId): array
    {
        $plugin = $this->mustFindPlugin($pluginId);
        return array_map(
            fn(PluginFeatureFlag $f) => $this->formatFeatureFlag($f),
            $this->featureFlags->findByPlugin($plugin)
        );
    }

    /** @return array<string,mixed> */
    public function setFeatureFlag(string $pluginId, array $data): array
    {
        $plugin = $this->mustFindPlugin($pluginId);
        $flagKey = (string) $data['flagKey'];
        $scope = isset($data['scope']) ? (string) $data['scope'] : PluginFeatureFlag::SCOPE_GLOBAL;
        $scopeValue = isset($data['scopeValue']) ? (string) $data['scopeValue'] : '';
        $enabled = (bool) ($data['enabled'] ?? true);

        $flag = $this->featureFlags->findOneByKey($plugin, $flagKey, $scope, $scopeValue);
        if ($flag === null) {
            $flag = new PluginFeatureFlag($plugin, $flagKey, $scope, $scopeValue);
        }
        $flag->setEnabled($enabled);
        $this->em->persist($flag);
        $this->em->flush();
        return $this->formatFeatureFlag($flag);
    }

    public function safeModeEnable(): void
    {
        $this->safeMode->enable();
    }

    public function safeModeDisable(): void
    {
        $this->safeMode->disable();
    }

    public function isSafeModeOn(): bool
    {
        return $this->safeMode->isEnabled();
    }

    public function getInstallMode(): string
    {
        return $this->installModeResolver->resolve();
    }

    public function getLockFileSnapshot(): ?array
    {
        return $this->lockFileReader->readRaw();
    }

    /**
     * SemVer of the host CMS. Sourced from the `selfhelp.cms_version`
     * Symfony parameter, which itself reads `SELFHELP_CMS_VERSION`
     * (default `8.0.0-dev`). Used by the plugin compatibility check
     * and exposed on the public manifest endpoint so the frontend
     * runtime can show drift warnings.
     */
    public function getCmsVersion(): string
    {
        return $this->cmsVersion;
    }

    /**
     * Host plugin API version. Counterpart to a plugin's
     * `pluginApiVersion` in `plugin.json`. Bumped in lock step with
     * the shared `@selfhelp/shared/plugin-sdk#PLUGIN_API_VERSION`
     * constant whenever the SDK ships a breaking change.
     */
    public function getSdkApiVersion(): string
    {
        return $this->sdkApiVersion;
    }

    private function mustFindPlugin(string $pluginId): Plugin
    {
        $plugin = $this->plugins->findOneByPluginId($pluginId);
        if (!$plugin instanceof Plugin) {
            throw new ServiceException(sprintf('Plugin "%s" is not installed.', $pluginId), Response::HTTP_NOT_FOUND);
        }
        return $plugin;
    }

    private function mustFindOperation(int $operationId): PluginOperation
    {
        $op = $this->operations->find($operationId);
        if (!$op instanceof PluginOperation) {
            throw new ServiceException(sprintf('Plugin operation #%d not found.', $operationId), Response::HTTP_NOT_FOUND);
        }
        return $op;
    }

    /**
     * @return array<string,mixed>
     */
    private function formatPlugin(Plugin $plugin, bool $deep = false): array
    {
        $data = [
            'id' => $plugin->getId(),
            'pluginId' => $plugin->getPluginId(),
            'name' => $plugin->getName(),
            'description' => $plugin->getDescription(),
            'version' => $plugin->getVersion(),
            'pluginApiVersion' => $plugin->getPluginApiVersion(),
            'trustLevel' => $plugin->getTrustLevel(),
            'enabled' => $plugin->isEnabled(),
            'installMode' => $plugin->getInstallMode(),
            'backendPackage' => $plugin->getBackendPackage(),
            'backendBundleClass' => $plugin->getBackendBundleClass(),
            'frontendRuntimeUrl' => $plugin->getFrontendRuntimeUrl(),
            'frontendRuntimeStylesheetUrl' => $plugin->getFrontendRuntimeStylesheetUrl(),
            'frontendRuntimeIntegrity' => $plugin->getFrontendRuntimeIntegrity(),
            'frontendRuntimeFormat' => $plugin->getFrontendRuntimeFormat(),
            'mobilePackage' => $plugin->getMobilePackage(),
            'mobilePackageVersion' => $plugin->getMobilePackageVersion(),
            'signingKeyId' => $plugin->getSigningKeyId(),
            'signature' => $plugin->getSignatureEd25519(),
            'installedAt' => $plugin->getInstalledAt()->format(DATE_ATOM),
            'updatedAt' => $plugin->getUpdatedAt()->format(DATE_ATOM),
            'enabledAt' => $plugin->getEnabledAt()?->format(DATE_ATOM),
            'disabledAt' => $plugin->getDisabledAt()?->format(DATE_ATOM),
        ];

        if ($deep) {
            $manifest = new PluginManifest($plugin->getManifestJson());
            $data['manifest'] = $plugin->getManifestJson();
            $data['capabilities'] = $plugin->getCapabilitiesJson();
            $data['compatibility'] = $this->compatibility->check($manifest);
            $data['recentOperations'] = array_map(
                fn(PluginOperation $op) => $this->formatOperation($op),
                $this->operations->findByPluginId($plugin->getPluginId(), 10)
            );
            $data['featureFlags'] = array_map(
                fn(PluginFeatureFlag $f) => $this->formatFeatureFlag($f),
                $this->featureFlags->findByPlugin($plugin)
            );
        }

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function formatOperation(PluginOperation $op): array
    {
        return [
            'id' => $op->getId(),
            'pluginId' => $op->getPluginId(),
            'type' => $op->getType(),
            'status' => $op->getStatus(),
            'requestedVersion' => $op->getRequestedVersion(),
            'fromVersion' => $op->getFromVersion(),
            'toVersion' => $op->getToVersion(),
            'installMode' => $op->getInstallMode(),
            'errorSummary' => $op->getErrorSummary(),
            'startedAt' => $op->getStartedAt()?->format(DATE_ATOM),
            'finishedAt' => $op->getFinishedAt()?->format(DATE_ATOM),
            'createdAt' => $op->getCreatedAt()->format(DATE_ATOM),
            'logs' => $op->getLogsJson(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function formatSource(PluginSource $source): array
    {
        return [
            'id' => $source->getId(),
            'name' => $source->getName(),
            'kind' => $source->getKind(),
            'url' => $this->sourceUrlResolver->resolve($source),
            'authHeaderName' => $source->getAuthHeaderName(),
            'authSecretEnvVar' => $source->getAuthSecretEnvVar(),
            'channel' => $source->getChannel(),
            'trustLevel' => $source->getTrustLevel(),
            'enabled' => $source->isEnabled(),
            'isSystem' => $source->isSystem(),
            'lastSyncedAt' => $source->getLastSyncedAt()?->format(DATE_ATOM),
            'createdAt' => $source->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $source->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function formatFeatureFlag(PluginFeatureFlag $flag): array
    {
        return [
            'pluginId' => $flag->getPlugin()->getPluginId(),
            'flagKey' => $flag->getFlagKey(),
            'enabled' => $flag->isEnabled(),
            'scope' => $flag->getScope(),
            'scopeValue' => $flag->getScopeValue(),
            'updatedAt' => $flag->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function resolveManifestUrl(mixed $manifestUrl, ?string $sourceBaseUrl): ?string
    {
        if (!is_string($manifestUrl) || $manifestUrl === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $manifestUrl) === 1) {
            return $manifestUrl;
        }

        if ($sourceBaseUrl === null) {
            return $manifestUrl;
        }

        return $sourceBaseUrl . ltrim($manifestUrl, '/');
    }
}
