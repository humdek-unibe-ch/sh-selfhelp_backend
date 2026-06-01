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
use App\Plugin\Event\Lifecycle\PluginDisabledEvent;
use App\Plugin\Event\Lifecycle\PluginEnabledEvent;
use App\Plugin\Manifest\PluginManifest;
use App\Repository\Plugin\PluginRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enables / disables an installed plugin.
 *
 * Disabling preserves data — only the bundle registration and
 * route/style contributions are removed. Re-enabling restores them.
 *
 * The orchestrator wraps the entity update in a Doctrine transaction
 * and:
 *
 *   - regenerates `config/selfhelp_plugin_bundles.php`,
 *   - rewrites `selfhelp.plugins.lock.json`,
 *   - invalidates plugin / route / style / permission / lookup
 *     caches,
 *   - publishes a Mercure update so the admin UI refreshes,
 *   - logs a `transactions` row.
 */
final class PluginEnabler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PluginRepository $plugins,
        private readonly PluginOperationLock $lock,
        private readonly PluginOperationRecorder $recorder,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly TransactionService $transactions,
        private readonly EventDispatcherInterface $events,
        private readonly InstallModeResolver $installModeResolver,
        private readonly PluginCacheInvalidator $cacheInvalidator,
    ) {
    }

    public function enable(string $pluginId): Plugin
    {
        return $this->toggle($pluginId, true);
    }

    public function disable(string $pluginId): Plugin
    {
        return $this->toggle($pluginId, false);
    }

    private function toggle(string $pluginId, bool $enabled): Plugin
    {
        $this->lock->assertCanStart($pluginId);

        try {
            $plugin = $this->plugins->findOneByPluginId($pluginId);
            if (!$plugin instanceof Plugin) {
                throw new ServiceException(sprintf('Plugin "%s" is not installed.', $pluginId), Response::HTTP_NOT_FOUND);
            }

            if ($plugin->isEnabled() === $enabled) {
                return $plugin;
            }

            $type = $enabled ? PluginOperation::TYPE_ENABLE : PluginOperation::TYPE_DISABLE;
            $operation = $this->recorder->start(
                $pluginId,
                $type,
                $this->installModeResolver->resolve(),
                null,
                $plugin->getVersion(),
            );
            $this->recorder->markRunning($operation, $enabled ? 'Enabling plugin' : 'Disabling plugin');

            $this->em->beginTransaction();
            try {
                $plugin->setEnabled($enabled);
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                if ($enabled) {
                    $plugin->setEnabledAt($now);
                } else {
                    $plugin->setDisabledAt($now);
                }
                $plugin->touchUpdatedAt();
                $this->em->flush();
                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                $this->recorder->fail($operation, $e, $type);
                throw $e;
            }

            // Enabling/disabling flips `plugins.enabled`, which gates
            // every reader that walks `styles`, `permissions`,
            // `rel_permissions_roles`, `lookups`, `api_routes`,
            // contributed admin pages, and the plugin list itself.
            // Clear every impacted Redis category in one shot so the
            // admin shell does not have to be force-refreshed (and so
            // operators no longer have to `redis-cli flushdb` by hand).
            $this->cacheInvalidator->invalidatePluginSurfaceCaches();
            $this->bundlesWriter->regenerate();
            $manifest = new PluginManifest($plugin->getManifestJson());
            $this->lockFileWriter->upsertPlugin($plugin, $manifest);

            $this->transactions->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'plugins',
                $plugin->getId(),
                false,
                sprintf('Plugin %s: %s', $pluginId, $enabled ? 'enabled' : 'disabled'),
            );

            $this->recorder->succeed($operation, $enabled ? 'Plugin enabled' : 'Plugin disabled', $plugin, $plugin->getVersion());

            if ($enabled) {
                $this->events->dispatch(new PluginEnabledEvent($plugin, $operation));
            } else {
                $this->events->dispatch(new PluginDisabledEvent($plugin, $operation));
            }

            return $plugin;
        } finally {
            $this->lock->release($pluginId);
        }
    }
}
