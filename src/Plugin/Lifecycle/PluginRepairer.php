<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Exception\ServiceException;
use App\Plugin\Bundle\PluginBundlesFileWriter;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use App\Repository\Plugin\PluginRepository;
use App\Service\Cache\Core\CacheService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-syncs the host's generated artifacts to the current state of the
 * `plugins` table:
 *
 *   - regenerates `config/selfhelp_plugin_bundles.php`,
 *   - rewrites `selfhelp.plugins.lock.json` for every enabled plugin,
 *   - invalidates plugin / route / style / permission / lookup caches.
 *
 * Used by `selfhelp:plugin:repair` and called automatically when the
 * doctor command detects drift.
 */
final class PluginRepairer
{
    public function __construct(
        private readonly PluginRepository $plugins,
        private readonly PluginBundlesFileWriter $bundlesWriter,
        private readonly PluginLockFileWriter $lockFileWriter,
        private readonly PluginLockFileReader $lockFileReader,
        private readonly PluginRegistryService $registry,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * @return array{
     *   plugins: list<string>,
     *   bundlesFile: string,
     *   lockFileUpdated: bool,
     * }
     */
    public function repair(): array
    {
        $bundlesPath = $this->bundlesWriter->regenerate();

        // The lock file is rebuilt as a full replacement of the
        // current state — `upsertPlugin()` alone cannot drop stale
        // entries left behind by direct DB cleanup, so a repair call
        // after manual recovery would otherwise keep the broken
        // entries forever.
        $dbPluginIds = [];
        $touched = [];
        foreach ($this->plugins->findAllOrderedByName() as $plugin) {
            $manifest = new PluginManifest($plugin->getManifestJson());
            $this->lockFileWriter->upsertPlugin($plugin, $manifest);
            $dbPluginIds[$plugin->getPluginId()] = true;
            $touched[] = $plugin->getPluginId();
        }
        $this->dropStaleLockEntries($dbPluginIds);

        $this->registry->invalidate();
        $this->cache->withCategory(CacheService::CATEGORY_API_ROUTES)->invalidateCategory();

        return [
            'plugins' => $touched,
            'bundlesFile' => $bundlesPath,
            'lockFileUpdated' => true,
        ];
    }

    /**
     * Remove any lock-file entry whose plugin id is no longer present
     * in the `plugins` table. Intentionally minimal: we do not rewrite
     * surviving entries here (those are kept fresh by `upsertPlugin`
     * above).
     *
     * @param array<string,bool> $dbPluginIds
     */
    private function dropStaleLockEntries(array $dbPluginIds): void
    {
        $existing = $this->lockFileReader->read();
        if ($existing === null) {
            return;
        }
        foreach ($existing->plugins as $entry) {
            $rawId = $entry['id'] ?? null;
            if (!is_string($rawId) || $rawId === '' || isset($dbPluginIds[$rawId])) {
                continue;
            }
            $rawMode = $entry['installMode'] ?? null;
            $installMode = is_string($rawMode) && $rawMode !== '' ? $rawMode : Plugin::INSTALL_MODE_MANAGED;
            $this->lockFileWriter->removePlugin($rawId, $installMode);
        }
    }

    public function repairSingle(string $pluginId): Plugin
    {
        $plugin = $this->plugins->findOneByPluginId($pluginId);
        if (!$plugin instanceof Plugin) {
            throw new ServiceException(sprintf('Plugin "%s" is not installed.', $pluginId), Response::HTTP_NOT_FOUND);
        }
        $manifest = new PluginManifest($plugin->getManifestJson());
        $this->lockFileWriter->upsertPlugin($plugin, $manifest);
        $this->bundlesWriter->regenerate();
        $this->registry->invalidate();
        $this->cache->withCategory(CacheService::CATEGORY_API_ROUTES)->invalidateCategory();
        return $plugin;
    }
}
