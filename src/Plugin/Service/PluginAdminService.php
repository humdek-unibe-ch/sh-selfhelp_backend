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
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\RegistryClient;
use App\Plugin\Registry\PluginSourceUrlResolver;
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
                $available[] = [
                    'sourceName' => $sourceName,
                    'pluginId' => (string) $pluginId,
                    'name' => isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : (string) $pluginId,
                    'description' => isset($entry['description']) && is_string($entry['description']) ? $entry['description'] : null,
                    'version' => isset($entry['version']) && is_string($entry['version']) ? $entry['version'] : '0.0.0',
                    'trustLevel' => isset($entry['trustLevel']) && is_string($entry['trustLevel']) ? $entry['trustLevel'] : 'untrusted',
                    'homepage' => isset($entry['homepage']) && is_string($entry['homepage']) ? $entry['homepage'] : null,
                    'manifest' => isset($entry['manifest']) && is_array($entry['manifest']) ? $entry['manifest'] : null,
                    'manifestUrl' => $this->resolveManifestUrl(
                        $entry['manifestUrl'] ?? null,
                        $sourceBaseUrls[$sourceName] ?? null,
                    ),
                ];
            }
        }
        return $available;
    }

    /** @return array<int, array<string,mixed>> */
    public function listPlugins(): array
    {
        return array_map(
            fn(Plugin $p) => $this->formatPlugin($p),
            $this->plugins->findAllOrderedByName()
        );
    }

    /** @return array<string,mixed> */
    public function getPlugin(string $pluginId): array
    {
        $plugin = $this->mustFindPlugin($pluginId);
        return $this->formatPlugin($plugin, deep: true);
    }

    /** @return array<string,mixed> */
    public function requestInstall(array $manifestData, ?array $registryEntry = null): array
    {
        $manifest = new PluginManifest($manifestData);
        $operation = $this->installer->request($manifest, $registryEntry);
        return $this->formatOperation($operation);
    }

    /** @return array<string,mixed> */
    public function finalizeInstall(int $operationId, array $manifestData): array
    {
        $op = $this->mustFindOperation($operationId);
        $plugin = $this->installer->finalize($op, new PluginManifest($manifestData));
        return $this->formatPlugin($plugin, deep: true);
    }

    /** @return array<string,mixed> */
    public function requestUpdate(array $manifestData, bool $forceMajor = false): array
    {
        $operation = $this->updater->request(new PluginManifest($manifestData), $forceMajor);
        return $this->formatOperation($operation);
    }

    /** @return array<string,mixed> */
    public function finalizeUpdate(int $operationId, array $manifestData): array
    {
        $op = $this->mustFindOperation($operationId);
        $plugin = $this->updater->finalize($op, new PluginManifest($manifestData));
        return $this->formatPlugin($plugin, deep: true);
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

    public function uninstall(string $pluginId): void
    {
        $this->uninstaller->uninstall($pluginId);
    }

    public function purge(string $pluginId, string $confirmedPluginId): void
    {
        $this->purger->purge($pluginId, $confirmedPluginId);
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
            'frontendPackage' => $plugin->getFrontendPackage(),
            'frontendPackageVersion' => $plugin->getFrontendPackageVersion(),
            'mobilePackage' => $plugin->getMobilePackage(),
            'mobilePackageVersion' => $plugin->getMobilePackageVersion(),
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
