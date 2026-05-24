<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Cache;

use App\Plugin\Registry\PluginRegistryService;
use App\Service\Cache\Core\CacheService;

/**
 * Centralizes the Redis cache invalidation that has to fire whenever a
 * plugin's CMS surface changes (install / enable / disable / uninstall /
 * purge).
 *
 * A plugin migration may insert into any of the following shared core
 * tables on install and the matching `id_plugins`-tagged rows are
 * removed again on purge:
 *
 *   - `styles`, `style_groups`, `fields`, `field_types`,
 *     `rel_fields_styles`              → `CATEGORY_STYLES`
 *   - `permissions`                    → `CATEGORY_PERMISSIONS`
 *   - `rel_permissions_roles`          → `CATEGORY_ROLES`,
 *                                        `CATEGORY_USERS` (user
 *                                        permission projection)
 *   - `lookups`                        → `CATEGORY_LOOKUPS`
 *   - `api_routes`,
 *     `rel_api_routes_permissions`     → `CATEGORY_API_ROUTES`
 *   - admin pages contributed by the   → `CATEGORY_PAGES`
 *     plugin's frontend `register()`
 *   - the `plugins` table itself       → `CATEGORY_PLUGINS`
 *
 * Enable / disable do not touch the rows themselves but they DO flip
 * the `plugins.enabled` flag every reader gates on; the cached list
 * snapshots therefore have to be dropped too.
 *
 * The four lifecycle orchestrators (`PluginInstaller`, `PluginEnabler`,
 * `PluginUninstaller`, `PluginPurger`) call {@see invalidatePluginSurfaceCaches()}
 * after their DB commit so the very next request sees fresh data
 * without forcing an operator to flush Redis by hand.
 *
 * The call is idempotent and cheap (it issues one
 * `invalidateCategory()` per category against the same Redis pool the
 * host already uses), so the orchestrators can keep their existing
 * narrower invalidations in place — duplicate clears are harmless.
 */
final class PluginCacheInvalidator
{
    /**
     * Cache categories impacted by a plugin lifecycle change.
     *
     * Keep this list aligned with the column groups in the class
     * docblock above. The order does not matter; every category is
     * invalidated independently.
     */
    private const IMPACTED_CATEGORIES = [
        CacheService::CATEGORY_PLUGINS,
        CacheService::CATEGORY_API_ROUTES,
        CacheService::CATEGORY_STYLES,
        CacheService::CATEGORY_PERMISSIONS,
        CacheService::CATEGORY_ROLES,
        CacheService::CATEGORY_USERS,
        CacheService::CATEGORY_LOOKUPS,
        CacheService::CATEGORY_PAGES,
    ];

    public function __construct(
        private readonly CacheService $cache,
        private readonly PluginRegistryService $registry,
    ) {
    }

    /**
     * Invalidate every Redis category a plugin's CMS surface can land
     * rows in. Called by the four plugin lifecycle orchestrators after
     * their DB transaction commits.
     */
    public function invalidatePluginSurfaceCaches(): void
    {
        // The registry caches the plugin list under CATEGORY_PLUGINS;
        // calling its dedicated invalidator first keeps the existing
        // single-entry-point behaviour for code that only depends on
        // PluginRegistryService (no behavioural change for that path).
        $this->registry->invalidate();

        foreach (self::IMPACTED_CATEGORIES as $category) {
            $this->cache->withCategory($category)->invalidateCategory();
        }
    }
}
