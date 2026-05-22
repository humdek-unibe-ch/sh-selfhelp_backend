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
use App\Plugin\Event\Lifecycle\PluginUninstalledEvent;
use App\Plugin\Registry\PluginRegistryService;
use App\Repository\Plugin\PluginRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removes plugin packages while preserving plugin-owned data.
 *
 * After uninstall:
 *   - the plugin row is deleted from `plugins`,
 *   - `config/selfhelp_plugin_bundles.php` is regenerated,
 *   - `selfhelp.plugins.lock.json` is updated,
 *   - plugin-owned tables stay in place,
 *   - rows tagged with `id_plugins` keep the FK NULL (ON DELETE SET NULL),
 *   - caches are invalidated,
 *   - `PluginUninstalledEvent` fires.
 *
 * Re-installing the plugin reconnects the existing data via the
 * manifest's `dataAccess.ownedTables` declaration.
 */
final class PluginUninstaller
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationLock $lock,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginRegistryService $registry,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly InstallModeResolver $installModeResolver,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly CacheService $cache,
    ) {
    }

    public function uninstall(string $pluginId): void
    {
        $this->lock->assertCanStart($pluginId);

        try {
            $plugin = $this->plugins->findOneByPluginId($pluginId);
            if (!$plugin instanceof Plugin) {
                throw new ServiceException(sprintf('Plugin "%s" is not installed.', $pluginId), Response::HTTP_NOT_FOUND);
            }

            $installMode = $this->installModeResolver->resolve();
            $operation = $this->recorder->start(
                $pluginId,
                PluginOperation::TYPE_UNINSTALL,
                $installMode,
                null,
                $plugin->getVersion(),
            );
            $this->recorder->markRunning($operation, 'Uninstalling plugin');

            $this->em->beginTransaction();
            try {
                $pluginRecord = $plugin;
                $this->em->remove($pluginRecord);
                $this->em->flush();
                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                $this->recorder->fail($operation, $e, 'uninstall');
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
                sprintf('Plugin uninstalled: %s', $pluginId),
            );

            $this->recorder->succeed($operation, 'Plugin uninstalled', null, $plugin->getVersion());
            $this->events->dispatch(new PluginUninstalledEvent($plugin, $operation));
        } finally {
            $this->lock->release($pluginId);
        }
    }
}
