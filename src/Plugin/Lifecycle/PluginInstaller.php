<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use App\Plugin\Bundle\PluginBundlesFileWriter;
use App\Plugin\Event\Lifecycle\PluginInstalledEvent;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Security\PluginCapabilityViolationException;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use App\Exception\ServiceException;

/**
 * Orchestrates installation of a plugin.
 *
 * The installer:
 *
 *   1. Acquires the global + per-plugin operation lock.
 *   2. Records a `plugin_operations` row in `requested` state.
 *   3. Validates capability matrix + compatibility against the host
 *      CMS version.
 *   4. Snapshots the relevant files (`composer.lock`,
 *      `package.json`, generated bundles file, lock file) into the
 *      operation row.
 *   5. In `managed` install mode it returns the CLI command and ends
 *      the operation in `requested` state — the actual install runs
 *      via `selfhelp:plugin:run-operation`.
 *   6. In `development` / `trusted` modes the orchestrator may run
 *      composer / npm via the `PackageManagerRunner`; that path is
 *      implemented in the CLI runner so the same code path applies
 *      everywhere.
 *
 * Once the package work is done by the CLI runner, the installer
 * persists the `plugins` row, regenerates the bundles file, and
 * dispatches `PluginInstalledEvent`.
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
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Stage 1 — request installation. Returns the `PluginOperation`
     * row that orchestrates the rest of the work.
     *
     * @param array<string,mixed>|null $registryEntry Optional registry
     *                                                payload (checksum,
     *                                                signature, source).
     */
    public function request(PluginManifest $manifest, ?array $registryEntry = null): PluginOperation
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
                $capabilities = $this->capabilityValidator->validate($manifest);
            } catch (PluginCapabilityViolationException $e) {
                throw new ServiceException($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, null);
            }

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
                'registryEntry' => $registryEntry,
                'lockFileBefore' => $this->lockFileReader->readRaw(),
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
                sprintf('Plugin install requested: %s@%s (mode=%s)', $manifest->getPluginId(), $manifest->getVersion(), $installMode)
            );

            // Lock stays held until `finalize()` (success or failure)
            // so a concurrent finalize for the same plugin id cannot
            // start.
            return $operation;
        } catch (\Throwable $e) {
            $this->lock->release($manifest->getPluginId());
            throw $e;
        }
    }

    /**
     * Stage 2 — finalize installation after package + migration work
     * has finished (called by `selfhelp:plugin:run-operation` or the
     * direct-execution worker).
     */
    public function finalize(PluginOperation $operation, PluginManifest $manifest): Plugin
    {
        $this->recorder->markRunning($operation, 'Finalizing plugin install');

        try {
            $this->em->beginTransaction();
            try {
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
                $plugin->setFrontendPackage($manifest->getFrontendPackage());
                $plugin->setFrontendPackageVersion($manifest->getFrontendPackageVersion());
                $plugin->setMobilePackage($manifest->getMobilePackage());
                $plugin->setMobilePackageVersion($manifest->getMobilePackageVersion());
                $plugin->setManifestJson($manifest->toArray());
                $plugin->setCapabilitiesJson($this->capabilityValidator->validate($manifest));
                $plugin->setEnabled(false);

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
}
