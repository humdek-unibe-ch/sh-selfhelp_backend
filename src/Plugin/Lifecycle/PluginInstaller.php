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
use App\Plugin\Bundle\PluginBundlesFileWriter;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Event\Lifecycle\PluginInstalledEvent;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\Messenger\InstallPluginMessage;
use App\Plugin\Migration\PluginMigrationsRunner;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Security\PluginCapabilityViolationException;
use App\Plugin\Security\PluginMigrationScanner;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Orchestrates installation of a plugin.
 *
 * Flow:
 *
 *   1. `request()` validates compatibility + capabilities + dispatches
 *      the `InstallPluginMessage` via the `plugin_ops` Messenger
 *      transport, then returns the queued `PluginOperation`.
 *   2. The Messenger worker (`InstallPluginHandler`) runs Composer,
 *      promotes archive artifacts, and calls `finalize()` below.
 *   3. `finalize()` persists the `plugins` row, regenerates the
 *      bundles file, updates the lock file, and dispatches
 *      `PluginInstalledEvent`.
 *
 * Signature verification happens upstream in `ManifestResolver` /
 * `PluginArchiveValidator` BEFORE the manifest reaches the installer.
 * The installer assumes its inputs are already trusted.
 */
final class PluginInstaller
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginOperationLock $lock,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginCapabilityValidator $capabilityValidator,
        private readonly PluginCompatibilityValidator $compatibilityValidator,
        private readonly PluginRepository $plugins,
        private readonly PluginRegistryService $registry,
        private readonly InstallModeResolver $installModeResolver,
        private readonly PluginLockFileReader $lockFileReader,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginMigrationScanner $migrationScanner,
        private readonly PluginMigrationsRunner $migrationsRunner,
        private readonly PluginApiRouteSynchronizer $apiRouteSynchronizer,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly MessageBusInterface $messageBus,
        private readonly PluginCacheInvalidator $cacheInvalidator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Stage 1 — validate the manifest, persist a `plugin_operations`
     * row, and dispatch the `InstallPluginMessage` to the worker.
     * Returns immediately; the worker streams progress over Mercure.
     */
    public function request(PluginManifest $manifest, ResolvedSource $resolved): PluginOperation
    {
        $this->lock->assertCanStart($manifest->getPluginId());

        try {
            if ($this->plugins->findOneByPluginId($manifest->getPluginId()) !== null) {
                throw new ServiceException(sprintf(
                    'Plugin "%s" is already installed. Use the update command instead.',
                    $manifest->getPluginId()
                ), Response::HTTP_CONFLICT);
            }

            $compatibility = $this->compatibilityValidator->check($manifest);
            if ($compatibility['severity'] === 'blocking') {
                throw new ServiceException(
                    'Plugin compatibility check failed: ' . implode('; ', $compatibility['reasons']),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['compatibility' => $compatibility]
                );
            }

            try {
                $capabilities = $this->capabilityValidator->validate($manifest, $resolved);
            } catch (PluginCapabilityViolationException $e) {
                throw new ServiceException($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, null);
            }

            $migrationScan = $this->migrationScanner->scan($manifest);
            $this->apiRouteSynchronizer->preflightValidate($manifest, $resolved);

            $installMode = $this->installModeResolver->resolve();
            $operation = $this->recorder->start(
                $manifest->getPluginId(),
                PluginOperation::TYPE_INSTALL,
                $installMode,
                $manifest->getVersion()
            );

            $this->recorder->snapshot($operation, [
                'manifest' => $manifest->toArray(),
                'compatibility' => $compatibility,
                'capabilities' => $capabilities,
                'resolvedSource' => [
                    'kind' => $resolved->kind,
                    'sourceName' => $resolved->sourceName,
                    'manifestUrl' => $resolved->manifestUrl,
                    'keyId' => $resolved->keyId,
                    'signature' => $resolved->signature,
                    'expectedChecksums' => $resolved->expectedChecksums,
                    'composer' => $resolved->composer,
                    'runtime' => $resolved->runtime,
                    'archiveStagingDir' => $resolved->archiveStagingDir,
                    // archiveMode + archiveBackendDir are needed by the
                    // managed-mode runbook so a CI operator setting up
                    // a Composer path repo for a standalone .shplugin
                    // knows where to point it. Including both in the
                    // snapshot lets selfhelp:plugin:run-operation
                    // re-render the runbook for the operator after the
                    // initial worker run.
                    'archiveMode' => $resolved->archiveMode,
                    'archiveBackendDir' => $resolved->archiveBackendDir,
                ],
                'lockFileBefore' => $this->lockFileReader->readRaw(),
                'migrationScan' => $migrationScan,
            ]);

            $this->recorder->setRollbackPlan($operation, [
                'restoreLockFile' => true,
                'regenerateBundles' => true,
            ]);

            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                LookupService::TRANSACTION_BY_BY_USER,
                'plugins',
                null,
                false,
                sprintf('Plugin install requested: %s@%s (mode=%s, source=%s)', $manifest->getPluginId(), $manifest->getVersion(), $installMode, $resolved->kind)
            );

            $opId = $operation->getId();
            if (!is_int($opId)) {
                throw new \LogicException('PluginOperation id was not generated.');
            }
            $this->messageBus->dispatch(new InstallPluginMessage(
                operationId: $opId,
                manifestArray: $manifest->toArray(),
                resolvedSource: $resolved,
            ));

            return $operation;
        } catch (\Throwable $e) {
            $this->lock->release($manifest->getPluginId());
            throw $e;
        }
    }

    /**
     * Stage 2 — finalize installation after the Messenger worker has
     * finished its composer + migration work.
     */
    public function finalize(PluginOperation $operation, PluginManifest $manifest): Plugin
    {
        $this->recorder->markRunning($operation, 'Finalizing plugin install');

        // Bundle-class-existence gate. The orchestrator is about to
        // regenerate `config/selfhelp_plugin_bundles.php`. If the bundle
        // class is not autoloadable yet (because composer never ran),
        // `kernel->registerBundles()` will fatal-error on the next
        // request. This is now a defensive assert — the Messenger
        // worker should have run composer require before getting here.
        $bundleClass = $manifest->getBackendBundleClass();
        if ($bundleClass !== null && $bundleClass !== '' && !class_exists($bundleClass)) {
            $packageHint = $manifest->getBackendPackage() ?? $manifest->getPluginId();
            $error = new ServiceException(sprintf(
                'Backend bundle class "%s" is not autoloadable after composer require. The Messenger worker reported success but the bundle did not register; check composer.json + autoload-dump.',
                $bundleClass,
            ), Response::HTTP_PRECONDITION_FAILED, [
                'package' => $packageHint,
                'version' => $manifest->getVersion(),
            ]);
            $this->recorder->fail($operation, $error, 'finalize:bundle-missing');
            $this->lock->release($manifest->getPluginId());
            throw $error;
        }

        try {
            $this->em->beginTransaction();
            try {
                $frontendRuntime = $this->resolveFrontendRuntimeMetadata($manifest, $operation);
                $plugin = new Plugin(
                    $manifest->getPluginId(),
                    $manifest->getName(),
                    $manifest->getVersion(),
                    $manifest->getPluginApiVersion()
                );
                $plugin->setDescription($manifest->getDescription());
                $plugin->setTrustLevel($manifest->getTrustLevel());
                $plugin->setInstallMode($operation->getInstallMode());
                $plugin->setBackendPackage($manifest->getBackendPackage());
                $plugin->setBackendBundleClass($manifest->getBackendBundleClass());
                $plugin->setFrontendRuntimeUrl($frontendRuntime['entrypointUrl']);
                $plugin->setFrontendRuntimeStylesheetUrl($frontendRuntime['stylesheetUrl']);
                $plugin->setFrontendRuntimeIntegrity($frontendRuntime['integrity']);
                $plugin->setFrontendRuntimeFormat($frontendRuntime['format']);
                $plugin->setMobilePackage($manifest->getMobilePackage());
                $plugin->setMobilePackageVersion($manifest->getMobilePackageVersion());
                $plugin->setManifestJson($manifest->toArray());
                $plugin->setCapabilitiesJson($this->capabilityValidator->validate($manifest));
                // Install never auto-enables. The new plugin row lands
                // disabled so an admin can review trust level, capabilities,
                // signing key id, requested external hosts, and the declared
                // dataAccess lists on the Installed tab before flipping
                // `enabled = 1`. The admin UI's "Install plugin" modal
                // chains a `selfhelp:plugin:enable` call when its "Enable
                // plugin after install" switch is on (default ON);
                // development fast-paths (e.g. SurveyJS's
                // `install-local.mjs --symlink`) call the enable command
                // themselves. Documented at
                // `docs/plugins/installation.md` §6.1.
                $plugin->setEnabled(false);
                $this->applySigningMetadata($plugin, $operation);

                $this->em->persist($plugin);
                $this->em->flush();
                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                $this->recorder->fail($operation, $e, 'finalize');
                throw $e;
            }

            $this->registry->invalidate();
            $this->bundlesWriter->regenerate();

            // Architecture §7 puts "run plugin Doctrine migrations"
            // between the bundles-file regeneration and the lock-file
            // upsert. Without this step the plugin's tables, styles,
            // permissions, lookups, and fields never get created — the
            // plugin row exists but the CMS surface registration the
            // plugin's migration is responsible for is missing, so the
            // plugin cannot function even after enabling it.
            $migrationResult = $this->migrationsRunner->migrate($manifest);
            $this->recorder->appendLog($operation, 'plugin-migrations', $migrationResult, 80);

            // Persist plugin-declared API routes into `api_routes`
            // (tagged with `id_plugins`) and link them to permissions
            // through `rel_api_routes_permissions`. Plugin migrations
            // own the permission rows; the host owns the route rows
            // so disable / uninstall / purge stay symmetric across
            // every plugin. See {@see PluginApiRouteSynchronizer}.
            $this->apiRouteSynchronizer->sync($plugin, $manifest);
            $this->recorder->appendLog($operation, 'plugin-api-routes-sync', [
                'routes' => count($manifest->getApiRoutes()),
            ], 85);

            // The plugin migration just inserted into `styles`,
            // `permissions`, `rel_permissions_roles`, `lookups`,
            // etc. Invalidate the cached lists for every impacted
            // Redis category here, AFTER the migration and route sync
            // have run, so the very next request sees the new rows.
            // The earlier `registry->invalidate()` only dropped
            // `CATEGORY_PLUGINS`, which is not enough for the admin
            // sidebar / page editor / permission resolver to refresh
            // on their own.
            $this->cacheInvalidator->invalidatePluginSurfaceCaches();

            $this->lockFileWriter->upsertPlugin($plugin, $manifest);

            $this->recorder->succeed($operation, 'Plugin installed', $plugin, $manifest->getVersion());
            $this->events->dispatch(new PluginInstalledEvent($plugin, $operation));

            $this->logger->info('Plugin installed', [
                'plugin_id' => $plugin->getPluginId(),
                'version' => $plugin->getVersion(),
                'install_mode' => $plugin->getInstallMode(),
            ]);

            return $plugin;
        } finally {
            $this->lock->release($manifest->getPluginId());
        }
    }

    /**
     * Lift the signing keyId + signature out of the operation's
     * `resolvedSource` snapshot (written by `request()`) and pin them
     * on the new `Plugin` entity so the lock file can render them and
     * doctor queries can spot drift.
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
     * @return array{
     *     entrypointUrl: string|null,
     *     stylesheetUrl: string|null,
     *     integrity: string|null,
     *     format: string
     * }
     */
    private function resolveFrontendRuntimeMetadata(PluginManifest $manifest, PluginOperation $operation): array
    {
        $runtime = [
            'entrypointUrl' => $manifest->getFrontendRuntimeEntrypoint(),
            'stylesheetUrl' => $manifest->getFrontendRuntimeStylesheet(),
            'integrity' => $manifest->getFrontendRuntimeIntegrity(),
            'format' => $manifest->getFrontendRuntimeFormat(),
        ];

        $resolved = $this->getResolvedSourceSnapshot($operation);
        $kind = is_array($resolved) && is_string($resolved['kind'] ?? null) ? $resolved['kind'] : null;

        // Registry / URL installs: the InstallPluginHandler downloaded
        // the published runtime bundle into public/plugin-artifacts/
        // and rewrote the manifest's frontend.runtime.entrypoint to the
        // host-relative path. Trust that rewrite — DO NOT fall through
        // to the snapshot's absolute URL below or we would store the
        // cross-origin GitHub Pages URL that browsers cannot use for
        // this bundle (its imports resolve against the same origin).
        if ($this->isPromotedRuntimeWebPath($runtime['entrypointUrl'])) {
            return $runtime;
        }

        // Archive installs rewrite the manifest in the worker after
        // promoting runtime files into public/plugin-artifacts/.
        // Be defensive here too: if the worker hands finalize() an
        // archive manifest that still contains archive-relative paths
        // like `dist/plugin.esm.js`, persist the promoted public path
        // instead of leaking the raw manifest value into the DB.
        if ($kind === ResolvedSource::KIND_ARCHIVE) {
            $pluginVersionDir = rawurlencode($manifest->getPluginId() . '-' . $manifest->getVersion());
            if ($this->isArchiveRelativeRuntimePath($runtime['entrypointUrl'])) {
                $runtime['entrypointUrl'] = '/plugin-artifacts/' . $pluginVersionDir . '/plugin.esm.js';
            }
            if ($this->isArchiveRelativeRuntimePath($runtime['stylesheetUrl'])) {
                $runtime['stylesheetUrl'] = '/plugin-artifacts/' . $pluginVersionDir . '/plugin.css';
            }
            return $runtime;
        }

        // Development fast-paths (`selfhelp:plugin:install <local plugin.json>`
        // and SurveyJS' `install-local.mjs --symlink`) come through the
        // paste resolver and should keep using the Vite dev server.
        if ($operation->getInstallMode() === Plugin::INSTALL_MODE_DEVELOPMENT
            && $kind === ResolvedSource::KIND_PASTE
        ) {
            $runtime['entrypointUrl'] = $manifest->getFrontendDevEntrypointUrl() ?? $runtime['entrypointUrl'];
            return $runtime;
        }

        $resolvedRuntime = $this->normaliseStringKeyArray($resolved['runtime'] ?? null);
        if ($resolvedRuntime !== null) {
            $runtime['entrypointUrl'] = $this->stringOrFallback($resolvedRuntime, 'entrypointUrl', $runtime['entrypointUrl']);
            $runtime['stylesheetUrl'] = $this->stringOrFallback($resolvedRuntime, 'stylesheetUrl', $runtime['stylesheetUrl']);
            $runtime['integrity'] = $this->stringOrFallback($resolvedRuntime, 'integrity', $runtime['integrity']);
            $runtime['format'] = $this->stringOrFallback($resolvedRuntime, 'format', $runtime['format']) ?? 'esm';
        }

        return $runtime;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getResolvedSourceSnapshot(PluginOperation $operation): ?array
    {
        $snapshots = $operation->getSnapshotsJson() ?? [];
        return $this->normaliseStringKeyArray($snapshots['resolvedSource'] ?? null);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function normaliseStringKeyArray(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $out = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $out[$key] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function stringOrFallback(array $data, string $key, ?string $fallback): ?string
    {
        $value = $data[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private function isArchiveRelativeRuntimePath(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return false;
        }

        return !preg_match('#^[a-z][a-z0-9+.-]*://#i', $value);
    }

    private function isPromotedRuntimeWebPath(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, '/plugin-artifacts/');
    }

}
