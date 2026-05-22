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
use App\Plugin\Event\Lifecycle\PluginUpdatedEvent;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use App\Plugin\Versioning\SemverHelper;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Updates an installed plugin to a new manifest version.
 *
 * Versioning semantics (codified in every host AGENTS.md):
 *
 *   - `patch` → code change without DB change. Migrations skipped.
 *   - `minor` → always carries a DB change.
 *   - `major` → breaking change. Requires `--force-major` from caller.
 *
 * Like `PluginInstaller`, the updater is split into `request()` (set
 * up the operation row + validations) and `finalize()` (commit the
 * new plugin record after the CLI runner has done the package + DB
 * work).
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
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function request(PluginManifest $newManifest, bool $forceMajor = false): PluginOperation
    {
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

            $capabilities = $this->capabilityValidator->validate($newManifest);

            $installMode = $this->installModeResolver->resolve();
            $operation = $this->recorder->start(
                $pluginId,
                PluginOperation::TYPE_UPDATE,
                $installMode,
                $newManifest->getVersion(),
                $existing->getVersion(),
            );

            $backup = $this->backupHook->beforeDestructive(
                $pluginId,
                PluginOperation::TYPE_UPDATE,
                $this->affectedTables($existing),
            );

            $this->recorder->snapshot($operation, [
                'currentManifest' => $existing->getManifestJson(),
                'newManifest' => $newManifest->toArray(),
                'capabilities' => $capabilities,
                'compatibility' => $compat,
                'lockFileBefore' => $this->lockFileReader->readRaw(),
                'diffKind' => $diff,
                'backup' => $backup,
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
                sprintf('Plugin update requested: %s %s → %s (mode=%s)', $pluginId, $existing->getVersion(), $newManifest->getVersion(), $installMode)
            );

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

        try {
            $this->em->beginTransaction();
            try {
                $plugin->setVersion($newManifest->getVersion());
                $plugin->setPluginApiVersion($newManifest->getPluginApiVersion());
                $plugin->setTrustLevel($newManifest->getTrustLevel());
                $plugin->setBackendPackage($newManifest->getBackendPackage());
                $plugin->setBackendBundleClass($newManifest->getBackendBundleClass());
                $plugin->setFrontendPackage($newManifest->getFrontendPackage());
                $plugin->setFrontendPackageVersion($newManifest->getFrontendPackageVersion());
                $plugin->setMobilePackage($newManifest->getMobilePackage());
                $plugin->setMobilePackageVersion($newManifest->getMobilePackageVersion());
                $plugin->setManifestJson($newManifest->toArray());
                $plugin->setCapabilitiesJson($this->capabilityValidator->validate($newManifest));
                $plugin->setDescription($newManifest->getDescription());
                $plugin->setName($newManifest->getName());
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
            $this->lockFileWriter->upsertPlugin($plugin, $newManifest);

            $this->recorder->succeed($operation, 'Plugin updated', $plugin, $plugin->getVersion());
            $this->events->dispatch(new PluginUpdatedEvent($plugin, $operation));

            return $plugin;
        } finally {
            $this->lock->release($newManifest->getPluginId());
        }
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
}
