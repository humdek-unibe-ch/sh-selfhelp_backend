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
use App\Plugin\Event\Lifecycle\PluginPurgedEvent;
use App\Plugin\Registry\PluginRegistryService;
use App\Plugin\Security\ProtectedTablesPolicy;
use App\Repository\Plugin\PluginRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only destructive plugin operation.
 *
 * Purge removes:
 *   - plugin-owned tables declared in `dataAccess.ownedTables`,
 *   - rows in shared core tables tagged with `id_plugins`,
 *   - rows in `data_tables` owned by the plugin (tagged with `id_plugins`),
 *   - the plugin's row from `plugins`,
 *   - its operation history is preserved for audit.
 *
 * The purger never touches protected tables (`ProtectedTablesPolicy`)
 * — even if the manifest claims to own one (manifests are validated
 * against the protected list at install time, but this is the runtime
 * safety net).
 *
 * Double confirmation is enforced by the controller and the CLI; the
 * orchestrator itself requires a `confirmedPluginId` argument that
 * must match the plugin id exactly.
 */
final class PluginPurger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationLock $lock,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginRegistryService $registry,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly PluginBackupHookInterface $backupHook,
        private readonly InstallModeResolver $installModeResolver,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly CacheService $cache,
    ) {
    }

    public function purge(string $pluginId, string $confirmedPluginId, bool $backupBefore = false): void
    {
        if ($pluginId !== $confirmedPluginId) {
            throw new ServiceException(
                'Purge confirmation does not match the plugin id. Aborting.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->lock->assertCanStart($pluginId);

        try {
            $plugin = $this->plugins->findOneByPluginId($pluginId);
            if (!$plugin instanceof Plugin) {
                throw new ServiceException(sprintf('Plugin "%s" is not installed.', $pluginId), Response::HTTP_NOT_FOUND);
            }

            $manifestData = $plugin->getManifestJson();
            $ownedTables = $this->collectOwnedTables($manifestData);

            $installMode = $this->installModeResolver->resolve();
            $operation = $this->recorder->start(
                $pluginId,
                PluginOperation::TYPE_PURGE,
                $installMode,
                null,
                $plugin->getVersion(),
            );

            $backup = $this->backupHook->beforeDestructive($pluginId, PluginOperation::TYPE_PURGE, $ownedTables);
            $this->recorder->snapshot($operation, [
                'ownedTables' => $ownedTables,
                'manifestAtPurge' => $manifestData,
                'backup' => $backup,
                'backupRequested' => $backupBefore,
            ]);
            $this->recorder->markRunning($operation, 'Purging plugin');

            $this->em->beginTransaction();
            try {
                foreach ($ownedTables as $table) {
                    $this->dropOwnedTable($table);
                    $this->recorder->appendLog($operation, 'Dropped plugin-owned table', ['table' => $table]);
                }

                // Plugin-tagged rows on shared tables are removed via the
                // FK ON DELETE SET NULL on `id_plugins` when the plugin row
                // is removed. We additionally hard-delete rows whose
                // existence only makes sense for this plugin (styles /
                // api_routes / fields / permissions / lookups created by
                // the plugin and tagged with id_plugins).
                $this->deletePluginTaggedRows($plugin->getId());

                $this->em->remove($plugin);
                $this->em->flush();
                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                $this->recorder->fail($operation, $e, 'purge');
                throw $e;
            }

            $this->registry->invalidate();
            $this->cache->withCategory(CacheService::CATEGORY_API_ROUTES)->invalidateCategory();
            $this->bundlesWriter->regenerate();
            $this->lockFileWriter->removePlugin($pluginId, $installMode);

            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'plugins',
                null,
                false,
                sprintf('Plugin purged: %s (irreversible)', $pluginId),
            );

            $this->recorder->succeed($operation, 'Plugin purged', null, $plugin->getVersion());
            $this->events->dispatch(new PluginPurgedEvent($plugin, $operation));
        } finally {
            $this->lock->release($pluginId);
        }
    }

    /**
     * @return list<string>
     */
    private function collectOwnedTables(array $manifestData): array
    {
        $owned = $manifestData['dataAccess']['ownedTables'] ?? [];
        if (!is_array($owned)) {
            return [];
        }
        $valid = [];
        foreach ($owned as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (ProtectedTablesPolicy::isProtected($name)) {
                throw new ServiceException(sprintf(
                    'Refusing to purge protected table "%s" declared by plugin. Manifest validation must reject this at install time.',
                    $name
                ), Response::HTTP_CONFLICT);
            }
            if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
                throw new ServiceException(sprintf(
                    'Plugin-owned table name "%s" is not safe to use in DDL.',
                    $name
                ), Response::HTTP_CONFLICT);
            }
            $valid[] = $name;
        }
        return $valid;
    }

    private function dropOwnedTable(string $table): void
    {
        $sql = sprintf('DROP TABLE IF EXISTS `%s`', $table);
        $this->connection->executeStatement($sql);
    }

    private function deletePluginTaggedRows(?int $idPlugins): void
    {
        if ($idPlugins === null) {
            return;
        }
        foreach (['styles', 'api_routes', 'fields', 'permissions', 'lookups'] as $table) {
            $this->connection->executeStatement(
                sprintf('DELETE FROM `%s` WHERE `id_plugins` = :idp', $table),
                ['idp' => $idPlugins]
            );
        }
    }
}
