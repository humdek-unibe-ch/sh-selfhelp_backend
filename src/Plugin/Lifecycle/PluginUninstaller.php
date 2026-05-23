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
use App\Plugin\Messenger\UninstallPluginMessage;
use App\Plugin\Registry\PluginRegistryService;
use App\Repository\Plugin\PluginRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Removes plugin packages while preserving plugin-owned data.
 *
 * Mirrors {@see PluginInstaller}/{@see PluginUpdater}:
 *
 *   1. `request()` creates the `plugin_operations` row, takes the
 *      per-plugin lock, and dispatches `UninstallPluginMessage` onto
 *      the `plugin_ops` Messenger transport. Returns immediately so
 *      the UI can subscribe to the Mercure progress topic.
 *   2. The worker (`UninstallPluginHandler`) executes
 *      `composer remove`, streams its output into
 *      `plugin_operations.logs_json`, then calls `finalize()` below.
 *   3. `finalize()` deletes the `plugins` row, regenerates the
 *      bundles file, updates the lock file, dispatches
 *      `PluginUninstalledEvent`, and releases the lock.
 *
 * Plugin-owned tables stay in place and rows tagged with `id_plugins`
 * keep their FK NULL (ON DELETE SET NULL). Re-installing the plugin
 * reconnects the existing data via the manifest's
 * `dataAccess.ownedTables` declaration.
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
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Stage 1 — record + dispatch. The Messenger worker takes it from
     * here and calls {@see finalize()}.
     */
    public function request(string $pluginId): PluginOperation
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

            $opId = $operation->getId();
            if (!is_int($opId)) {
                throw new \LogicException('PluginOperation id was not generated.');
            }
            $this->messageBus->dispatch(new UninstallPluginMessage(
                operationId: $opId,
                pluginId: $pluginId,
            ));

            return $operation;
        } catch (\Throwable $e) {
            $this->lock->release($pluginId);
            throw $e;
        }
    }

    /**
     * Stage 2 — invoked by the Messenger worker after composer remove
     * succeeded. Deletes the plugin row, regenerates artefacts, fires
     * the lifecycle event, releases the lock.
     */
    public function finalize(PluginOperation $operation): void
    {
        $pluginId = $operation->getPluginId();
        try {
            $plugin = $this->plugins->findOneByPluginId($pluginId);
            if (!$plugin instanceof Plugin) {
                // Idempotent: already deleted by a previous failed attempt.
                $this->recorder->succeed($operation, 'Plugin already uninstalled');
                return;
            }

            $this->recorder->markRunning($operation, 'Finalizing plugin uninstall');

            $this->em->beginTransaction();
            try {
                $this->em->remove($plugin);
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
            $this->lockFileWriter->removePlugin($pluginId, $operation->getInstallMode());

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
