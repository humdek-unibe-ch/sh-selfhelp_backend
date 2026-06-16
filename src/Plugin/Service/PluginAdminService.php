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
use App\Plugin\Registry\Unified\MalformedRegistryException;
use App\Plugin\Registry\Unified\PluginRelease;
use App\Plugin\Registry\Unified\PluginReleaseResolver;
use App\Plugin\Registry\Unified\PluginResolution;
use App\Plugin\Registry\Unified\UnifiedRegistryClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Plugin\Versioning\SemverHelper;
use App\Repository\Plugin\PluginFeatureFlagRepository;
use App\Repository\Plugin\PluginOperationRepository;
use App\Repository\Plugin\PluginRepository;
use App\Repository\Plugin\PluginSourceRepository;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin admin-facing facade over the plugin lifecycle orchestrators.
 *
 * Controllers call into this service and never reach for orchestrators
 * directly, so the API layer stays consistent. The facade also
 * implements the read-side queries (list plugins, list operations,
 * list sources) and emits structured DTO arrays for the response
 * formatter.
 *
 * @phpstan-type PluginOperationData array{
 *     id: int|null,
 *     pluginId: string,
 *     type: string,
 *     status: string,
 *     requestedVersion: string|null,
 *     fromVersion: string|null,
 *     toVersion: string|null,
 *     installMode: string,
 *     errorSummary: string|null,
 *     startedAt: string|null,
 *     finishedAt: string|null,
 *     createdAt: string,
 *     logs: array<int,array<string,mixed>>|null
 * }
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
        private readonly UnifiedRegistryClient $unifiedRegistryClient,
        private readonly PluginReleaseResolver $releaseResolver,
        private readonly PluginSourceUrlResolver $sourceUrlResolver,
        private readonly ManifestResolver $manifestResolver,
        private readonly PluginArchiveInspectionService $archiveInspectionService,
        private readonly HttpClientInterface $httpClient,
        private readonly string $cmsVersion,
        private readonly string $sdkApiVersion,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Browse every enabled `PluginSource` and return a flat list of
     * available plugin entries. Used by the admin UI's "Available"
     * tab so admins can install registry-listed plugins with a single
     * click (the entry already contains a `manifest` field).
     *
     * Unified registries (`registry.json` = index of release refs ->
     * signed `PluginRelease` docs) are consumed via {@see UnifiedRegistryClient}
     * + {@see PluginReleaseResolver}: every entry carries the full
     * multi-version picture (`versions[]` with per-version compatibility +
     * the standardized {@see \App\Plugin\Registry\Unified\CompatibilityError}),
     * the newest-compatible `selectedVersion`, and a ready-to-install
     * `registryEntry` (carrying `releaseUrl`) for each version. Sources still
     * publishing the legacy single-version inline format fall back to the
     * legacy {@see RegistryClient} so pre-migration registries keep working.
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

        $aggregated = $this->aggregateRegistrySources();
        $available = [];

        foreach ($aggregated['unified'] as $pluginId => $items) {
            if (isset($installedIds[$pluginId])) {
                continue;
            }
            $available[] = $this->buildUnifiedAvailableEntry((string) $pluginId, $items);
        }

        foreach ($aggregated['legacy'] as $pluginId => $entriesBySource) {
            if (isset($installedIds[$pluginId])) {
                continue;
            }
            foreach ($entriesBySource as $sourceName => $entry) {
                $available[] = $this->buildLegacyAvailableEntry((string) $pluginId, (string) $sourceName, $entry);
            }
        }

        return $available;
    }

    /**
     * Aggregate every enabled registry source into the unified multi-version
     * model. Each registry source's `registry.json` is read with the
     * {@see UnifiedRegistryClient} (index of release refs -> signed
     * `PluginRelease` docs, Ed25519-verified). A source whose `registry.json`
     * is still the legacy single-version inline format (no unified index shape)
     * falls back to the legacy {@see RegistryClient} so existing registries keep
     * working during migration. Transport failures skip the source (best-effort,
     * matching the previous behaviour). Per plugin id the unified releases are
     * sorted newest-first.
     *
     * @return array{
     *     unified: array<string, list<array{release: PluginRelease, releaseUrl: string, sourceName: string}>>,
     *     legacy: array<string, array<string, array<string,mixed>>>
     * }
     */
    private function aggregateRegistrySources(): array
    {
        $unified = [];
        $legacy = [];

        foreach ($this->sources->findEnabled() as $source) {
            if (!in_array($source->getKind(), [PluginSource::KIND_PUBLIC_REGISTRY, PluginSource::KIND_PRIVATE_REGISTRY], true)) {
                // git/local sources point directly at a single manifest, not a
                // unified index — they never contributed to the catalogue.
                continue;
            }

            $registryJsonUrl = rtrim($this->sourceUrlResolver->resolve($source), '/') . '/registry.json';
            $headers = $this->registrySourceHeaders($source);

            try {
                $index = $this->unifiedRegistryClient->fetchIndex($registryJsonUrl, $headers);
            } catch (MalformedRegistryException) {
                // Not a unified index -> try the legacy inline format.
                $this->collectLegacySource($source, $legacy);
                continue;
            } catch (\Throwable) {
                // Transport/DNS/5xx -> skip this source (best-effort).
                continue;
            }

            foreach ($index->pluginRefsById() as $pluginId => $refs) {
                foreach ($refs as $ref) {
                    $absUrl = $index->resolveUrl($ref->releaseUrl);
                    try {
                        $release = $this->unifiedRegistryClient->fetchPluginRelease($absUrl, $headers, $ref);
                    } catch (\Throwable) {
                        // Skip one unreadable/unverifiable release; keep the rest
                        // of the catalogue visible.
                        continue;
                    }
                    $unified[(string) $pluginId][] = [
                        'release' => $release,
                        'releaseUrl' => $absUrl,
                        'sourceName' => $source->getName(),
                    ];
                }
            }
        }

        foreach ($unified as $pid => $items) {
            usort(
                $items,
                static fn (array $a, array $b): int => SemverHelper::compare($b['release']->version, $a['release']->version),
            );
            $unified[$pid] = $items;
        }

        return ['unified' => $unified, 'legacy' => $legacy];
    }

    /**
     * Read ONE source's legacy single-version inline registry index and merge
     * its entries (with manifestUrl resolved to absolute) into the aggregate.
     *
     * @param array<string, array<string, array<string,mixed>>> $legacy
     */
    private function collectLegacySource(PluginSource $source, array &$legacy): void
    {
        try {
            $index = $this->registryClient->fetchIndex($source);
        } catch (\Throwable) {
            return;
        }
        $plugins = $index['plugins'] ?? null;
        if (!is_array($plugins)) {
            return;
        }
        $base = rtrim($this->sourceUrlResolver->resolve($source), '/') . '/';
        foreach ($plugins as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_scalar($entry['id'])) {
                continue;
            }
            $assoc = [];
            foreach ($entry as $k => $v) {
                $assoc[(string) $k] = $v;
            }
            $resolvedManifestUrl = $this->resolveManifestUrl($assoc['manifestUrl'] ?? null, $base);
            if ($resolvedManifestUrl !== null && $resolvedManifestUrl !== '') {
                $assoc['manifestUrl'] = $resolvedManifestUrl;
            }
            $legacy[(string) $entry['id']][$source->getName()] = $assoc;
        }
    }

    /**
     * Optional registry auth header for a private source, read from the env var
     * named by the source (the secret never lives in the DB). Mirrors
     * {@see RegistryClient}.
     *
     * @return array<string,string>
     */
    private function registrySourceHeaders(PluginSource $source): array
    {
        $headers = [];
        $authHeader = $source->getAuthHeaderName();
        $envVar = $source->getAuthSecretEnvVar();
        if ($authHeader !== null && $authHeader !== '' && $envVar !== null && $envVar !== '') {
            $secret = $_ENV[$envVar] ?? $_SERVER[$envVar] ?? getenv($envVar);
            if (is_string($secret) && $secret !== '') {
                $headers[$authHeader] = $secret;
            }
        }
        return $headers;
    }

    /**
     * Build a multi-version "Available" entry from a plugin's unified releases.
     *
     * @param list<array{release: PluginRelease, releaseUrl: string, sourceName: string}> $items newest-first
     * @return array<string,mixed>
     */
    private function buildUnifiedAvailableEntry(string $pluginId, array $items): array
    {
        $releases = array_map(static fn (array $i): PluginRelease => $i['release'], $items);
        $urlByVersion = [];
        $sourceByVersion = [];
        foreach ($items as $i) {
            $urlByVersion[$i['release']->version] = $i['releaseUrl'];
            $sourceByVersion[$i['release']->version] = $i['sourceName'];
        }
        $sourceName = $items[0]['sourceName'];

        $resolution = $this->releaseResolver->resolveLatestCompatible($releases, $this->cmsVersion, $this->sdkApiVersion);
        $display = $resolution->selected ?? $resolution->latestOverall;

        $manifest = null;
        $displayVersion = '0.0.0';
        $displayManifestUrl = null;
        $displayReleaseUrl = null;
        if ($display !== null) {
            $manifest = $this->tryFetchManifest($display->manifestUrl);
            $displayVersion = $display->version;
            $displayManifestUrl = $display->manifestUrl;
            $displayReleaseUrl = $urlByVersion[$display->version] ?? null;
        }

        return [
            'sourceName' => $sourceName,
            'pluginId' => $pluginId,
            'name' => $this->manifestStringValue($manifest, 'name') ?? $pluginId,
            'description' => $this->manifestStringValue($manifest, 'description'),
            'version' => $displayVersion,
            'trustLevel' => $this->resolveTrustLevel($manifest, $display),
            'homepage' => $this->manifestStringValue($manifest, 'homepage'),
            'manifest' => $manifest,
            'manifestUrl' => $displayManifestUrl,
            'registryEntry' => ($display !== null && is_string($displayReleaseUrl))
                ? $this->unifiedRegistryEntry($pluginId, $display, $displayReleaseUrl, $sourceName)
                : null,
            'installed' => false,
            'pinned' => false,
            'latestVersion' => $resolution->latestOverall?->version,
            'latestCompatibleVersion' => $resolution->latestCompatible?->version,
            'selectedVersion' => $resolution->selected?->version,
            'hasCompatibleVersion' => $resolution->hasCompatibleVersion(),
            'newerExistsButIncompatible' => $resolution->newerExistsButIncompatible(),
            'compatibilityError' => $resolution->error?->toArray(),
            'versions' => $this->buildVersionList($resolution, $urlByVersion, $sourceByVersion, $pluginId),
        ];
    }

    /**
     * Build the per-version list (newest-first) the Available-UI picker renders:
     * each version carries its channel, compatibility state, the required core
     * range, a human reason when incompatible, whether it is the default
     * selection / latest-compatible, and a ready-to-install `registryEntry`.
     *
     * @param array<string,string> $urlByVersion
     * @param array<string,string> $sourceByVersion
     * @return list<array<string,mixed>>
     */
    private function buildVersionList(PluginResolution $resolution, array $urlByVersion, array $sourceByVersion, string $pluginId): array
    {
        $selectedVersion = $resolution->selected?->version;
        $latestCompatibleVersion = $resolution->latestCompatible?->version;

        $rows = [];
        foreach ($resolution->compatible as $r) {
            $rows[] = $this->versionRow($r, true, $selectedVersion, $latestCompatibleVersion, $urlByVersion, $sourceByVersion, $pluginId);
        }
        foreach ($resolution->incompatible as $r) {
            $rows[] = $this->versionRow($r, false, $selectedVersion, $latestCompatibleVersion, $urlByVersion, $sourceByVersion, $pluginId);
        }
        usort($rows, static function (array $a, array $b): int {
            $av = is_string($a['version']) ? $a['version'] : '';
            $bv = is_string($b['version']) ? $b['version'] : '';
            return SemverHelper::compare($bv, $av);
        });
        return $rows;
    }

    /**
     * @param array<string,string> $urlByVersion
     * @param array<string,string> $sourceByVersion
     * @return array<string,mixed>
     */
    private function versionRow(
        PluginRelease $r,
        bool $compatible,
        ?string $selectedVersion,
        ?string $latestCompatibleVersion,
        array $urlByVersion,
        array $sourceByVersion,
        string $pluginId,
    ): array {
        $reason = null;
        if (!$compatible) {
            $reason = $this->releaseResolver->compatibilityErrorFor($r, $this->cmsVersion, $this->sdkApiVersion)?->message;
        }
        $state = match (true) {
            $r->version === $latestCompatibleVersion => 'latest-compatible',
            $compatible => 'compatible',
            default => 'incompatible',
        };
        $releaseUrl = $urlByVersion[$r->version] ?? null;
        $sourceName = $sourceByVersion[$r->version] ?? 'registry';

        return [
            'version' => $r->version,
            'channel' => $r->channel,
            'official' => $r->official,
            'compatible' => $compatible,
            'blocking' => !$compatible,
            'selected' => $r->version === $selectedVersion,
            'requiredRange' => $r->compatibilityCore,
            'requiredPluginApiRange' => $r->compatibilityPluginApi,
            'reason' => $reason,
            'releaseUrl' => $releaseUrl,
            'state' => $state,
            'registryEntry' => is_string($releaseUrl)
                ? $this->unifiedRegistryEntry($pluginId, $r, $releaseUrl, $sourceName)
                : null,
        ];
    }

    /**
     * The install payload for ONE unified release version: carries the
     * `releaseUrl` so {@see resolveSource()} resolves the signed
     * `PluginRelease` -> `.shplugin` and installs through the archive path.
     *
     * @return array<string,mixed>
     */
    private function unifiedRegistryEntry(string $pluginId, PluginRelease $r, string $releaseUrl, string $sourceName): array
    {
        return [
            'id' => $pluginId,
            'pluginId' => $pluginId,
            'version' => $r->version,
            'channel' => $r->channel,
            'releaseUrl' => $releaseUrl,
            'sourceName' => $sourceName,
            'manifestUrl' => $r->manifestUrl,
            'archiveUrl' => $r->archiveUrl,
            'trustLevel' => $r->official ? 'official' : 'reviewed',
        ];
    }

    /**
     * Build an "Available" entry for a legacy single-version inline registry
     * source. Mirrors the historical shape and adds the multi-version fields as
     * a single-version list so the frontend renders both formats uniformly.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function buildLegacyAvailableEntry(string $pluginId, string $sourceName, array $entry): array
    {
        $manifestUrl = isset($entry['manifestUrl']) && is_string($entry['manifestUrl']) ? $entry['manifestUrl'] : null;
        $manifest = isset($entry['manifest']) && is_array($entry['manifest']) ? $this->asAssocArray($entry['manifest']) : null;
        if ($manifest === null && is_string($manifestUrl) && $manifestUrl !== '') {
            $manifest = $this->tryFetchManifest($manifestUrl);
        }
        $registryEntry = $entry;
        if ($manifest !== null) {
            $registryEntry['manifest'] = $manifest;
        }
        $version = isset($entry['version']) && is_string($entry['version']) ? $entry['version'] : '0.0.0';
        $channel = isset($entry['channel']) && is_string($entry['channel']) ? $entry['channel'] : 'stable';
        $trustLevel = isset($entry['trustLevel']) && is_string($entry['trustLevel']) ? $entry['trustLevel'] : 'untrusted';

        return [
            'sourceName' => $sourceName,
            'pluginId' => $pluginId,
            'name' => isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : $pluginId,
            'description' => isset($entry['description']) && is_string($entry['description']) ? $entry['description'] : null,
            'version' => $version,
            'trustLevel' => $trustLevel,
            'homepage' => isset($entry['homepage']) && is_string($entry['homepage']) ? $entry['homepage'] : null,
            'manifest' => $manifest,
            'manifestUrl' => $manifestUrl,
            'registryEntry' => $registryEntry,
            'installed' => false,
            'pinned' => false,
            'latestVersion' => $version,
            'latestCompatibleVersion' => $version,
            'selectedVersion' => $version,
            'hasCompatibleVersion' => true,
            'newerExistsButIncompatible' => false,
            'compatibilityError' => null,
            'versions' => [[
                'version' => $version,
                'channel' => $channel,
                'official' => $trustLevel === 'official',
                'compatible' => true,
                'blocking' => false,
                'selected' => true,
                'requiredRange' => '*',
                'requiredPluginApiRange' => '*',
                'reason' => null,
                'releaseUrl' => null,
                'state' => 'selected',
                'registryEntry' => $registryEntry,
            ]],
        ];
    }

    /**
     * @param array<string,mixed>|null $manifest
     */
    private function manifestStringValue(?array $manifest, string $key): ?string
    {
        if ($manifest !== null && isset($manifest[$key]) && is_string($manifest[$key]) && $manifest[$key] !== '') {
            return $manifest[$key];
        }
        return null;
    }

    /**
     * Resolve the trust level for an Available entry, preferring the manifest's
     * declared `security.trustLevel`, then the release's `official` flag.
     *
     * @param array<string,mixed>|null $manifest
     */
    private function resolveTrustLevel(?array $manifest, ?PluginRelease $release): string
    {
        if ($manifest !== null && isset($manifest['security']) && is_array($manifest['security'])) {
            $tl = $manifest['security']['trustLevel'] ?? null;
            if (is_string($tl) && $tl !== '') {
                return $tl;
            }
        }
        if ($release !== null) {
            return $release->official ? 'official' : 'untrusted';
        }
        return 'untrusted';
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
            return is_array($decoded) ? $this->asAssocArray($decoded) : null;
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

        // Format each row defensively: one inconsistent plugin (e.g. a
        // half-removed bundle whose `composer remove` ran but whose row /
        // manifest is briefly out of sync during a manager-driven restart)
        // must NOT 500 the entire list and strand the operator on a dead
        // "Failed to load plugins" screen. Skip the bad row, log it, and let
        // the operator see (and repair / uninstall) everything else.
        $rows = [];
        foreach ($this->plugins->findAllOrderedByName() as $p) {
            try {
                $row = $this->formatPlugin($p);
                $row['availableUpdate'] = $updatesByPluginId[$p->getPluginId()] ?? null;
                $rows[] = $row;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to format plugin row for the admin list; skipping it', [
                    'pluginId' => $p->getPluginId(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $rows;
    }

    /**
     * Cross-reference installed plugins against the registry to surface
     * available updates. For unified sources the newest-COMPATIBLE release is
     * selected via {@see PluginReleaseResolver} (never "latest overall"); a row
     * is returned only when that release is strictly newer than the installed
     * version. Pinned plugins are skipped (audit finding #52). Legacy
     * single-version sources keep their historical newest-entry behaviour.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listAvailableUpdates(): array
    {
        $aggregated = $this->aggregateRegistrySources();
        $rows = [];
        foreach ($this->plugins->findAllOrderedByName() as $installed) {
            // Pinned plugins are intentionally frozen at their installed version
            // (audit finding #52): never surface an auto-update for them. The
            // installed-plugins list still exposes `pinned: true` so the UI can
            // show the pinned state and a manual "unpin to update" affordance.
            if ($installed->isPinned()) {
                continue;
            }
            $pluginId = $installed->getPluginId();

            if (isset($aggregated['unified'][$pluginId])) {
                $row = $this->buildUnifiedUpdateRow($installed, $aggregated['unified'][$pluginId]);
                if ($row !== null) {
                    $rows[] = $row;
                }
                continue;
            }
            if (isset($aggregated['legacy'][$pluginId])) {
                $row = $this->buildLegacyUpdateRow($installed, $aggregated['legacy'][$pluginId]);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

    /**
     * Build a unified update row for an installed plugin: select the newest
     * COMPATIBLE release; return null when nothing compatible or it is not
     * strictly newer than installed.
     *
     * @param list<array{release: PluginRelease, releaseUrl: string, sourceName: string}> $items
     * @return array<string,mixed>|null
     */
    private function buildUnifiedUpdateRow(Plugin $installed, array $items): ?array
    {
        $releases = array_map(static fn (array $i): PluginRelease => $i['release'], $items);
        $urlByVersion = [];
        $sourceByVersion = [];
        foreach ($items as $i) {
            $urlByVersion[$i['release']->version] = $i['releaseUrl'];
            $sourceByVersion[$i['release']->version] = $i['sourceName'];
        }

        $resolution = $this->releaseResolver->resolveLatestCompatible(
            $releases,
            $this->cmsVersion,
            $this->sdkApiVersion,
            $installed->getVersion(),
        );
        $selected = $resolution->selected;
        if ($selected === null) {
            return null;
        }
        if (SemverHelper::compare($selected->version, $installed->getVersion()) <= 0) {
            return null;
        }

        $releaseUrl = $urlByVersion[$selected->version] ?? null;
        $sourceName = $sourceByVersion[$selected->version] ?? 'registry';
        $manifest = $this->tryFetchManifest($selected->manifestUrl);

        return [
            'pluginId' => $installed->getPluginId(),
            'name' => $installed->getName(),
            'installedVersion' => $installed->getVersion(),
            'availableVersion' => $selected->version,
            'diffKind' => SemverHelper::diffKind($installed->getVersion(), $selected->version),
            'sourceName' => $sourceName,
            'trustLevel' => $selected->official ? 'official' : $installed->getTrustLevel(),
            'manifestUrl' => $selected->manifestUrl,
            'manifest' => $manifest,
            'registryEntry' => is_string($releaseUrl)
                ? $this->unifiedRegistryEntry($installed->getPluginId(), $selected, $releaseUrl, $sourceName)
                : null,
            'latestVersion' => $resolution->latestOverall?->version,
            'latestCompatibleVersion' => $resolution->latestCompatible?->version,
            'newerExistsButIncompatible' => $resolution->newerExistsButIncompatible(),
        ];
    }

    /**
     * Build a legacy update row (single-version inline registry source). Mirrors
     * the historical newest-entry behaviour.
     *
     * @param array<string, array<string,mixed>> $entriesBySource
     * @return array<string,mixed>|null
     */
    private function buildLegacyUpdateRow(Plugin $installed, array $entriesBySource): ?array
    {
        $best = null;
        $bestSource = null;
        foreach ($entriesBySource as $sourceName => $entry) {
            $candidateVersion = is_string($entry['version'] ?? null) ? (string) $entry['version'] : null;
            if ($candidateVersion === null || $candidateVersion === '') {
                continue;
            }
            if (SemverHelper::compare($candidateVersion, $installed->getVersion()) <= 0) {
                continue;
            }
            if ($best === null || SemverHelper::compare($candidateVersion, $this->asString($best['version'] ?? '0.0.0')) > 0) {
                $best = $entry;
                $bestSource = $sourceName;
            }
        }
        if ($best === null || $bestSource === null) {
            return null;
        }

        $manifestUrl = isset($best['manifestUrl']) && is_string($best['manifestUrl']) ? $best['manifestUrl'] : null;
        $manifest = is_array($best['manifest'] ?? null) ? $this->asAssocArray($best['manifest']) : null;
        if ($manifest === null && is_string($manifestUrl) && $manifestUrl !== '') {
            $manifest = $this->tryFetchManifest($manifestUrl);
        }
        $registryEntry = $best;
        if ($manifest !== null) {
            $registryEntry['manifest'] = $manifest;
        }

        return [
            'pluginId' => $installed->getPluginId(),
            'name' => $installed->getName(),
            'installedVersion' => $installed->getVersion(),
            'availableVersion' => $this->asString($best['version']),
            'diffKind' => SemverHelper::diffKind($installed->getVersion(), $this->asString($best['version'])),
            'sourceName' => $bestSource,
            'trustLevel' => is_string($best['trustLevel'] ?? null) ? $best['trustLevel'] : $installed->getTrustLevel(),
            'manifestUrl' => $manifestUrl,
            'manifest' => $manifest,
            'registryEntry' => $registryEntry,
            'latestVersion' => $this->asString($best['version']),
            'latestCompatibleVersion' => $this->asString($best['version']),
            'newerExistsButIncompatible' => false,
        ];
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
     * If the resolved plugin is already installed, transparently routes
     * the request through the updater instead of failing with a 409.
     * This lets admins drop a `.shplugin` (or pick a registry entry) of
     * a newer version without having to switch tabs between "Install"
     * and "Update".
     *
     * Returns the freshly-created `plugin_operations` row so the UI
     * can subscribe to its Mercure topic.
     *
     * Recognised `$input` keys (validated at runtime by resolveSource()):
     *   - source: 'registry'|'url'|'paste'|'archive'
     *   - registryEntry?: array<string,mixed>
     *   - sourceName?: string
     *   - manifestUrl?: string
     *   - manifest?: array<string,mixed>
     *   - forceMajor?: bool
     *   - backupBefore?: bool
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function install(array $input, ?UploadedFile $archive = null): array
    {
        $resolved = $this->resolveSource($input, $archive);
        $pluginId = $resolved['manifest']->getPluginId();

        $existing = $this->plugins->findOneByPluginId($pluginId);
        if ($existing !== null) {
            // Plugin already installed → decide between three cases up
            // front so the admin UI can show actionable copy without
            // catching exceptions:
            //
            //   - same version    → no-op (200 OK with reason=already_installed)
            //   - newer requested → auto-route to update (avoid making
            //                       the admin click Update separately)
            //   - older requested → reject with a downgrade hint
            //
            // The updater also enforces these rules at request time, so
            // this only optimises the UX path; the underlying contract
            // (no downgrade, no double-install) is unchanged.
            $existingVersion = $existing->getVersion();
            $requestedVersion = $resolved['manifest']->getVersion();
            $diff = SemverHelper::diffKind($existingVersion, $requestedVersion);

            if ($diff === 'same') {
                return [
                    'installAction' => 'already_installed',
                    'redirectedToUpdate' => false,
                    'pluginId' => $pluginId,
                    'existingVersion' => $existingVersion,
                    'requestedVersion' => $requestedVersion,
                    'message' => sprintf(
                        'Plugin "%s" is already installed at version %s. No action taken.',
                        $pluginId,
                        $existingVersion,
                    ),
                ];
            }

            if ($diff === 'downgrade') {
                $this->throwValidationError(sprintf(
                    'Plugin "%s" is installed at version %s. Refusing to install older version %s — use rollback if you really want to downgrade.',
                    $pluginId,
                    $existingVersion,
                    $requestedVersion,
                ));
            }

            $operation = $this->updater->request(
                $resolved['manifest'],
                $resolved['resolved'],
                (bool) ($input['forceMajor'] ?? false),
                (bool) ($input['backupBefore'] ?? false),
            );

            $payload = $this->formatOperation($operation);
            $payload['installAction'] = 'update_dispatched';
            $payload['redirectedToUpdate'] = true;
            $payload['existingVersion'] = $existingVersion;
            $payload['requestedVersion'] = $requestedVersion;
            $payload['diffKind'] = $diff;
            $payload['message'] = sprintf(
                'Plugin "%s" is installed at %s. Updating to %s (diff: %s).',
                $pluginId,
                $existingVersion,
                $requestedVersion,
                $diff,
            );
            return $payload;
        }

        $operation = $this->installer->request($resolved['manifest'], $resolved['resolved']);
        $payload = $this->formatOperation($operation);
        $payload['installAction'] = 'install_dispatched';
        $payload['redirectedToUpdate'] = false;
        return $payload;
    }

    /**
     * Single update entrypoint. Mirrors `install()`. The
     * `expectedPluginId` field is set by `AdminPluginController::update()`
     * from the URL-pinned plugin id; if the resolved manifest declares
     * a different id we reject the operation. This stops an admin from
     * accidentally (or maliciously) updating plugin A with the manifest
     * of plugin B by changing the URL path while keeping the body.
     *
     * Recognised `$input` keys (validated at runtime by resolveSource()):
     *   - source: 'registry'|'url'|'paste'|'archive'
     *   - registryEntry?: array<string,mixed>
     *   - sourceName?: string
     *   - manifestUrl?: string
     *   - manifest?: array<string,mixed>
     *   - forceMajor?: bool
     *   - backupBefore?: bool
     *   - expectedPluginId?: string
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(array $input, ?UploadedFile $archive = null): array
    {
        $resolved = $this->resolveSource($input, $archive);
        $expectedPluginId = isset($input['expectedPluginId']) ? $this->asString($input['expectedPluginId']) : null;
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

    /**
     * Pre-install inspection for `.shplugin` uploads. Extracts +
     * validates the archive without dispatching any operation so the
     * frontend can show a preview card (manifest, compatibility,
     * capabilities, signature, errors).
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
        $source = $this->asString($input['source'] ?? '');
        switch ($source) {
            case ResolvedSource::KIND_REGISTRY:
                if (!isset($input['registryEntry']) || !is_array($input['registryEntry'])) {
                    $this->throwValidationError('install.source=registry requires a `registryEntry` object.');
                }
                $entry = $this->asAssocArray($input['registryEntry']);
                $sourceName = isset($input['sourceName']) ? $this->asString($input['sourceName']) : 'registry';
                // Unified registry: the entry carries a `releaseUrl` -> follow the
                // signed PluginRelease, download + checksum-verify the .shplugin,
                // and install it through the archive trust path.
                $releaseUrl = isset($entry['releaseUrl']) && is_string($entry['releaseUrl']) ? $entry['releaseUrl'] : '';
                if ($releaseUrl !== '') {
                    return $this->manifestResolver->resolveRegistryRelease(
                        $releaseUrl,
                        $sourceName,
                        $this->headersForSourceName($sourceName),
                    );
                }
                // Legacy inline registry entry (single-version, connected install).
                return $this->manifestResolver->resolveRegistry($entry, $sourceName);

            case ResolvedSource::KIND_URL:
                if (!isset($input['manifestUrl']) || !is_string($input['manifestUrl']) || $input['manifestUrl'] === '') {
                    $this->throwValidationError('install.source=url requires a `manifestUrl`.');
                }
                $registryEntry = isset($input['registryEntry']) && is_array($input['registryEntry']) ? $this->asAssocArray($input['registryEntry']) : null;
                return $this->manifestResolver->resolveUrl($input['manifestUrl'], $registryEntry);

            case ResolvedSource::KIND_PASTE:
                if (!isset($input['manifest']) || !is_array($input['manifest'])) {
                    $this->throwValidationError('install.source=paste requires a `manifest` object.');
                }
                $registryEntry = isset($input['registryEntry']) && is_array($input['registryEntry']) ? $this->asAssocArray($input['registryEntry']) : null;
                return $this->manifestResolver->resolvePaste($this->asAssocArray($input['manifest']), $registryEntry);

            case ResolvedSource::KIND_ARCHIVE:
                if (!$archive instanceof UploadedFile) {
                    $this->throwValidationError('install.source=archive requires a multipart `archive` file part.');
                }
                return $this->manifestResolver->resolveArchive($archive);

            default:
                $this->throwValidationError(sprintf('Unknown install source "%s". Expected registry|url|paste|archive.', $source));
        }
    }

    /**
     * Resolve the optional registry auth headers for the named source so a
     * unified-registry install can fetch a private release document + archive.
     *
     * @return array<string,string>
     */
    private function headersForSourceName(string $sourceName): array
    {
        foreach ($this->sources->findEnabled() as $source) {
            if ($source->getName() === $sourceName) {
                return $this->registrySourceHeaders($source);
            }
        }
        return [];
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
     * Pin an installed plugin: the unified resolver will never auto-update it
     * and the core update preflight treats it as a hard block (with an
     * "unpin first" reason) until it is explicitly unpinned. Audit finding #52.
     *
     * @return array<string,mixed>
     */
    public function pin(string $pluginId): array
    {
        return $this->setPinned($pluginId, true);
    }

    /** @return array<string,mixed> */
    public function unpin(string $pluginId): array
    {
        return $this->setPinned($pluginId, false);
    }

    /** @return array<string,mixed> */
    private function setPinned(string $pluginId, bool $pinned): array
    {
        $plugin = $this->mustFindPlugin($pluginId);
        $plugin->setPinned($pinned);
        $plugin->touchUpdatedAt();
        $this->em->flush();
        return $this->formatPlugin($plugin, deep: true);
    }

    /**
     * Single uninstall entrypoint. Creates the `plugin_operations` row
     * and dispatches the asynchronous `UninstallPluginMessage`; the
     * Messenger worker handles `composer remove` and the lock-file +
     * bundles regeneration via `PluginUninstaller::finalize()`.
     *
     * @return PluginOperationData
     */
    public function uninstall(string $pluginId): array
    {
        return $this->formatOperation($this->uninstaller->request($pluginId));
    }

    public function purge(string $pluginId, string $confirmedPluginId, bool $backupBefore = false): void
    {
        $this->purger->purge($pluginId, $confirmedPluginId, $backupBefore);
    }

    /** @return PluginOperationData */
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

    /** @return array<int, PluginOperationData> */
    public function listOperations(?string $pluginId = null, int $limit = 100): array
    {
        $operations = $pluginId !== null
            ? $this->operations->findByPluginId($pluginId, $limit)
            : $this->operations->findBy([], ['createdAt' => 'DESC'], $limit);

        return array_map(fn(PluginOperation $op) => $this->formatOperation($op), $operations);
    }

    /** @return PluginOperationData */
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
     * @return PluginOperationData
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

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createSource(array $data): array
    {
        $source = new PluginSource(
            $this->asString($data['name'] ?? ''),
            $this->asString($data['kind'] ?? ''),
            $this->asString($data['url'] ?? ''),
        );
        if (isset($data['authHeaderName'])) {
            $source->setAuthHeaderName($this->asString($data['authHeaderName']));
        }
        if (isset($data['authSecretEnvVar'])) {
            $source->setAuthSecretEnvVar($this->asString($data['authSecretEnvVar']));
        }
        if (isset($data['channel'])) {
            $source->setChannel($this->asString($data['channel']));
        }
        if (isset($data['trustLevel'])) {
            $source->setTrustLevel($this->asString($data['trustLevel']));
        }
        if (isset($data['enabled'])) {
            $source->setEnabled((bool) $data['enabled']);
        }
        $this->em->persist($source);
        $this->em->flush();
        return $this->formatSource($source);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
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
            $source->setName($this->asString($data['name']));
        }
        if (array_key_exists('kind', $data)) {
            $source->setKind($this->asString($data['kind']));
        }
        if (array_key_exists('url', $data)) {
            $source->setUrl($this->asString($data['url']));
        }
        if (array_key_exists('authHeaderName', $data)) {
            $source->setAuthHeaderName($this->asStringOrNull($data['authHeaderName']));
        }
        if (array_key_exists('authSecretEnvVar', $data)) {
            $source->setAuthSecretEnvVar($this->asStringOrNull($data['authSecretEnvVar']));
        }
        if (array_key_exists('channel', $data)) {
            $source->setChannel($this->asString($data['channel']));
        }
        if (array_key_exists('trustLevel', $data)) {
            $source->setTrustLevel($this->asString($data['trustLevel']));
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

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function setFeatureFlag(string $pluginId, array $data): array
    {
        $plugin = $this->mustFindPlugin($pluginId);
        $flagKey = $this->asString($data['flagKey'] ?? '');
        $scope = isset($data['scope']) ? $this->asString($data['scope']) : PluginFeatureFlag::SCOPE_GLOBAL;
        $scopeValue = isset($data['scopeValue']) ? $this->asString($data['scopeValue']) : '';
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

    /** @return array<string,mixed>|null */
    public function getLockFileSnapshot(): ?array
    {
        return $this->lockFileReader->readRaw();
    }

    /**
     * SemVer of the host CMS. Sourced from the `selfhelp.cms_version`
     * Symfony parameter, which itself reads `SELFHELP_CMS_VERSION`
     * (default `0.1.0`). Used by the plugin compatibility check
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
            'pinned' => $plugin->isPinned(),
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
     * @return PluginOperationData
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
