<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use App\Exception\ServiceException;
use App\Plugin\Backup\PluginBackupHookInterface;
use App\Plugin\Bundle\PluginBundlesFileWriter;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Event\Lifecycle\PluginUpdatedEvent;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\Messenger\UpdatePluginMessage;
use App\Plugin\Migration\PluginMigrationsRunner;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Security\PluginMigrationScanner;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Plugin\Versioning\SemverHelper;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Updates an installed plugin to a new manifest version.
 *
 * Mirrors `PluginInstaller`:
 *   - `request()` validates compatibility/version-diff/capabilities and
 *     dispatches `UpdatePluginMessage` so the Messenger worker can run
 *     `composer require <package>:<newVersion>` and promote any new
 *     `.shplugin` artifacts.
 *   - `finalize()` persists the updated plugin row, regenerates the
 *     bundles file, refreshes the lock file, and dispatches
 *     `PluginUpdatedEvent`.
 *
 * Signature verification of the incoming source happens upstream in
 * `ManifestResolver` / `PluginArchiveValidator`.
 */
final class PluginUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationLock $lock,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginCapabilityValidator $capabilityValidator,
        private readonly PluginCompatibilityValidator $compatibilityValidator,
        private readonly PluginBackupHookInterface $backupHook,
        private readonly InstallModeResolver $installModeResolver,
        private readonly PluginLockFileReader $lockFileReader,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginRegistryService $registry,
        private readonly PluginMigrationScanner $migrationScanner,
        private readonly PluginMigrationsRunner $migrationsRunner,
        private readonly PluginApiRouteSynchronizer $apiRouteSynchronizer,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly MessageBusInterface $messageBus,
        private readonly PluginCacheInvalidator $cacheInvalidator,
    ) {
    }

    public function request(
        PluginManifest $newManifest,
        ResolvedSource $resolved,
        bool $forceMajor = false,
        bool $backupBefore = false,
    ): PluginOperation {
        $pluginId = $newManifest->getPluginId();
        $this->lock->assertCanStart($pluginId);

        try {
            $existing = $this->plugins->findOneByPluginId($pluginId);
            if (!$existing instanceof Plugin) {
                throw new ServiceException(sprintf(
                    'Cannot update plugin "%s": not installed. Use install instead.',
                    $pluginId
                ), Response::HTTP_NOT_FOUND);
            }

            $diff = SemverHelper::diffKind($existing->getVersion(), $newManifest->getVersion());
            if ($diff === 'same') {
                throw new ServiceException(sprintf(
                    'Plugin "%s" is already at version %s.',
                    $pluginId,
                    $newManifest->getVersion()
                ), Response::HTTP_CONFLICT);
            }
            if ($diff === 'downgrade') {
                throw new ServiceException(sprintf(
                    'Cannot update plugin "%s" to an older version (%s → %s). Use rollback instead.',
                    $pluginId,
                    $existing->getVersion(),
                    $newManifest->getVersion()
                ), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($diff === 'major' && !$forceMajor) {
                throw new ServiceException(sprintf(
                    'Plugin "%s" major update requested (%s → %s). Pass --force-major to acknowledge breaking changes.',
                    $pluginId,
                    $existing->getVersion(),
                    $newManifest->getVersion()
                ), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $compat = $this->compatibilityValidator->check($newManifest);
            if ($compat['severity'] === 'blocking') {
                throw new ServiceException(
                    'Plugin compatibility check failed: ' . implode('; ', $compat['reasons']),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['compatibility' => $compat]
                );
            }

            $capabilities = $this->capabilityValidator->validate($newManifest, $resolved);
            $migrationScan = $this->migrationScanner->scan($newManifest);
            $installMode = $this->installModeResolver->resolve();

            $operation = $this->recorder->start(
                $pluginId,
                PluginOperation::TYPE_UPDATE,
                $installMode,
                $newManifest->getVersion(),
                $existing->getVersion(),
            );

            // Backup is requested either by `--backup-before` (CLI) or
            // whenever the diff implies destructive schema work.
            $shouldBackup = $backupBefore || $diff === 'major' || $diff === 'minor';
            $backup = $shouldBackup
                ? $this->backupHook->beforeDestructive(
                    $pluginId,
                    PluginOperation::TYPE_UPDATE,
                    $this->affectedTables($existing),
                )
                : null;

            $this->recorder->snapshot($operation, [
                'currentManifest' => $existing->getManifestJson(),
                'newManifest' => $newManifest->toArray(),
                'capabilities' => $capabilities,
                'compatibility' => $compat,
                'lockFileBefore' => $this->lockFileReader->readRaw(),
                'diffKind' => $diff,
                'backup' => $backup,
                'resolvedSource' => [
                    'kind' => $resolved->kind,
                    'sourceName' => $resolved->sourceName,
                    'manifestUrl' => $resolved->manifestUrl,
                    'keyId' => $resolved->keyId,
                    'signature' => $resolved->signature,
                    'expectedChecksums' => $resolved->expectedChecksums,
                    'composer' => $resolved->composer,
                    'archiveStagingDir' => $resolved->archiveStagingDir,
                    // Same rationale as PluginInstaller — the runbook
                    // for managed-mode updates of standalone archives
                    // needs the backend dir + archive mode so the
                    // operator can wire the path repo correctly.
                    'archiveMode' => $resolved->archiveMode,
                    'archiveBackendDir' => $resolved->archiveBackendDir,
                ],
                'migrationScan' => $migrationScan,
            ]);

            $this->recorder->setRollbackPlan($operation, [
                'restoreLockFile' => true,
                'regenerateBundles' => true,
                'restorePackages' => true,
            ]);

            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'plugins',
                $existing->getId(),
                false,
                sprintf('Plugin update requested: %s %s → %s (mode=%s, source=%s)', $pluginId, $existing->getVersion(), $newManifest->getVersion(), $installMode, $resolved->kind)
            );

            $opId = $operation->getId();
            if (!is_int($opId)) {
                throw new \LogicException('PluginOperation id was not generated.');
            }
            $this->messageBus->dispatch(new UpdatePluginMessage(
                operationId: $opId,
                manifestArray: $newManifest->toArray(),
                resolvedSource: $resolved,
            ));

            return $operation;
        } catch (\Throwable $e) {
            $this->lock->release($pluginId);
            throw $e;
        }
    }

    public function finalize(PluginOperation $operation, PluginManifest $newManifest): Plugin
    {
        $plugin = $this->plugins->findOneByPluginId($newManifest->getPluginId());
        if (!$plugin instanceof Plugin) {
            throw new \LogicException('Plugin disappeared between request() and finalize().');
        }

        $this->recorder->markRunning($operation, 'Finalizing plugin update');

        $bundleClass = $newManifest->getBackendBundleClass();
        if ($bundleClass !== null && $bundleClass !== '' && !class_exists($bundleClass)) {
            $packageHint = $newManifest->getBackendPackage() ?? $newManifest->getPluginId();
            $error = new ServiceException(sprintf(
                'Backend bundle class "%s" is not autoloadable after composer require. The Messenger worker reported success but the bundle did not register; check composer.json + autoload-dump for package "%s" at version "%s".',
                $bundleClass,
                $packageHint,
                $newManifest->getVersion(),
            ), Response::HTTP_PRECONDITION_FAILED);
            $this->recorder->fail($operation, $error, 'finalize:bundle-missing');
            $this->lock->release($newManifest->getPluginId());
            throw $error;
        }

        try {
            $this->em->beginTransaction();
            try {
                $plugin->setVersion($newManifest->getVersion());
                $plugin->setPluginApiVersion($newManifest->getPluginApiVersion());
                $plugin->setTrustLevel($newManifest->getTrustLevel());
                $plugin->setBackendPackage($newManifest->getBackendPackage());
                $plugin->setBackendBundleClass($newManifest->getBackendBundleClass());
                $plugin->setFrontendRuntimeUrl($this->resolveFrontendRuntimeUrl($newManifest, $operation));
                $plugin->setFrontendRuntimeStylesheetUrl($newManifest->getFrontendRuntimeStylesheet());
                $plugin->setFrontendRuntimeIntegrity($newManifest->getFrontendRuntimeIntegrity());
                $plugin->setFrontendRuntimeFormat($newManifest->getFrontendRuntimeFormat());
                $plugin->setMobilePackage($newManifest->getMobilePackage());
                $plugin->setMobilePackageVersion($newManifest->getMobilePackageVersion());
                $plugin->setManifestJson($newManifest->toArray());
                $plugin->setCapabilitiesJson($this->capabilityValidator->validate($newManifest));
                $plugin->setDescription($newManifest->getDescription());
                $plugin->setName($newManifest->getName());
                $this->applySigningMetadata($plugin, $operation);
                $plugin->touchUpdatedAt();
                $this->em->flush();
                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                $this->recorder->fail($operation, $e, 'finalize');
                throw $e;
            }

            $this->registry->invalidate();
            $this->bundlesWriter->regenerate();

            // Architecture §7: minor + major plugin updates always
            // carry a Doctrine migration. Run any pending plugin
            // migrations between bundles-file regeneration and lock-
            // file upsert so the new version's schema/style/permission
            // changes land before the lock file claims the new version
            // is fully installed.
            $migrationResult = $this->migrationsRunner->migrate($newManifest);
            $this->recorder->appendLog($operation, 'plugin-migrations', $migrationResult, 80);

            // Reconcile plugin-owned `api_routes` rows with the new
            // manifest: rows whose name/version still appear are
            // updated in place, rows the new manifest no longer
            // declares are deleted, and any new rows are inserted.
            // Runs AFTER the migration so any newly declared
            // permissions are resolvable by name.
            $this->apiRouteSynchronizer->sync($plugin, $newManifest);
            $this->recorder->appendLog($operation, 'plugin-api-routes-sync', [
                'routes' => count($newManifest->getApiRoutes()),
            ], 85);

            // The new migration may have added/changed rows in
            // `styles`, `permissions`, `rel_permissions_roles`,
            // `lookups`, … and the route sync above re-shaped
            // `api_routes` / `rel_api_routes_permissions`. Invalidate
            // every impacted category so the next request sees the
            // upgraded CMS surface without an operator flushing Redis.
            $this->cacheInvalidator->invalidatePluginSurfaceCaches();

            $this->lockFileWriter->upsertPlugin($plugin, $newManifest);

            $this->recorder->succeed($operation, 'Plugin updated', $plugin, $plugin->getVersion());
            $this->events->dispatch(new PluginUpdatedEvent(
                $plugin,
                $operation,
                $operation->getFromVersion(),
                $plugin->getVersion(),
            ));

            return $plugin;
        } finally {
            $this->lock->release($newManifest->getPluginId());
        }
    }

    /**
     * Lift the signing keyId + signature out of the operation's
     * `resolvedSource` snapshot (written by `request()`) and pin them
     * on the `Plugin` entity so the lock file can render them.
     */
    private function applySigningMetadata(Plugin $plugin, PluginOperation $operation): void
    {
        $snapshots = $operation->getSnapshotsJson() ?? [];
        $resolved = $snapshots['resolvedSource'] ?? null;
        if (!is_array($resolved)) {
            return;
        }
        $keyId = $resolved['keyId'] ?? null;
        $signature = $resolved['signature'] ?? null;
        $plugin->setSigningKeyId(is_string($keyId) && $keyId !== '' ? $keyId : null);
        $plugin->setSignatureEd25519(is_string($signature) && $signature !== '' ? $signature : null);
    }

    /**
     * @return list<string>
     */
    private function affectedTables(Plugin $plugin): array
    {
        $data = $plugin->getManifestJson();
        $owned = $data['dataAccess']['ownedTables'] ?? [];
        return is_array($owned) ? array_values(array_filter($owned, 'is_string')) : [];
    }

    private function resolveFrontendRuntimeUrl(PluginManifest $manifest, PluginOperation $operation): ?string
    {
        if ($operation->getInstallMode() === Plugin::INSTALL_MODE_DEVELOPMENT) {
            return $manifest->getFrontendDevEntrypointUrl() ?? $manifest->getFrontendRuntimeEntrypoint();
        }

        return $manifest->getFrontendRuntimeEntrypoint();
    }
}
