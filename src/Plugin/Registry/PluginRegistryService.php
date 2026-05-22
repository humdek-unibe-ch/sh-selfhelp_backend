<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Registry;

use App\Entity\Plugin\Plugin;
use App\Repository\Plugin\PluginRepository;
use App\Service\Cache\Core\CacheService;

/**
 * Centralized read-side view of installed plugins.
 *
 * The service caches the list under `CacheService::CATEGORY_PLUGINS`
 * so the hot path (every API request that touches plugin-aware code)
 * never re-queries the DB. Mutations from
 * `PluginInstaller`/`PluginUninstaller`/feature-flag toggles invalidate
 * the cache, and a Mercure event is published so the admin UI updates
 * without polling.
 */
final class PluginRegistryService
{
    private const CACHE_KEY_ALL = 'plugins:all';
    private const CACHE_KEY_ENABLED = 'plugins:enabled';

    public function __construct(
        private readonly PluginRepository $plugins,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * @return list<Plugin>
     */
    public function getAll(): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_PLUGINS)
            ->getList(self::CACHE_KEY_ALL, function (): array {
                return $this->plugins->findAllOrderedByName();
            });
    }

    /**
     * @return list<Plugin>
     */
    public function getEnabled(): array
    {
        return $this->cache
            ->withCategory(CacheService::CATEGORY_PLUGINS)
            ->getList(self::CACHE_KEY_ENABLED, function (): array {
                return $this->plugins->findEnabled();
            });
    }

    public function findByPluginId(string $pluginId): ?Plugin
    {
        // The full list is cached, so a linear scan is cheap and
        // avoids a second cache key for per-plugin lookups (which
        // would need finer-grained invalidation).
        foreach ($this->getAll() as $plugin) {
            if ($plugin->getPluginId() === $pluginId) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * Drop every plugin cache entry. Called by install/update/uninstall
     * orchestrators after committing their transactions.
     */
    public function invalidate(): void
    {
        $this->cache->withCategory(CacheService::CATEGORY_PLUGINS)->invalidateCategory();
    }
}
