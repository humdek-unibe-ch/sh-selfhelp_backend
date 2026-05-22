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

        $touched = [];
        foreach ($this->plugins->findAllOrderedByName() as $plugin) {
            $manifest = new PluginManifest($plugin->getManifestJson());
            $this->lockFileWriter->upsertPlugin($plugin, $manifest);
            $touched[] = $plugin->getPluginId();
        }

        $this->registry->invalidate();
        $this->cache->withCategory(CacheService::CATEGORY_API_ROUTES)->invalidateCategory();

        return [
            'plugins' => $touched,
            'bundlesFile' => $bundlesPath,
            'lockFileUpdated' => true,
        ];
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
