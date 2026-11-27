<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An enabled QA plugin for the contracts of the users part (C15, C16, C20, C24):
 * a table prefix it may write to for users it manages, a rate limit of 2 per
 * minute, the JSON Logic operations of {@see QaDoubleJsonLogicOperation} and
 * {@see QaFailingJsonLogicOperation}, and the domain events of its choice.
 *
 * Only the plugin row with its manifest is stored: the contracts read the
 * manifest of enabled plugins from the registry, and nothing here needs the
 * catalogue (styles, permissions, lookups) a full install would add. Everything
 * runs inside the test's DAMA transaction and is rolled back; the plugin cache
 * pool is not, so {@see forget()} belongs into `tearDown()`.
 *
 * | Thing | Name |
 * | --- | --- |
 * | plugin id | `qa-users-plugin` |
 * | owned table prefix | `qa_users_` |
 * | rate limits | `qa_burst`: 2 per minute, sliding window; `qa_slow`: 1 per hour, fixed window |
 * | operations | `qa.double` (doubles its argument), `qa.fail` (always throws) |
 */
final class QaUsersPluginFixture
{
    public const PLUGIN_ID = 'qa-users-plugin';
    public const TABLE_PREFIX = 'qa_users_';
    public const LIMIT_NAME = 'qa_burst';
    public const LIMIT_PER_MINUTE = 2;
    public const LIMIT_SLOW = 'qa_slow';

    /**
     * The manifest as the installer stores it.
     *
     * @param list<string> $listens the domain events the plugin lists (C24)
     * @param list<string> $operations the JSON Logic operations the plugin declares (C16)
     * @return array<string, mixed>
     */
    public static function manifestData(array $listens = [], array $operations = [QaDoubleJsonLogicOperation::NAME]): array
    {
        return [
            'id' => self::PLUGIN_ID,
            'name' => 'QA users plugin',
            'version' => '1.0.0',
            'pluginApiVersion' => '0.2.0',
            'dataAccess' => ['ownedDataTablePrefix' => self::TABLE_PREFIX],
            'rateLimits' => [
                [
                    'name' => self::LIMIT_NAME,
                    'policy' => 'sliding_window',
                    'limit' => self::LIMIT_PER_MINUTE,
                    'interval' => '1 minute',
                ],
                [
                    'name' => self::LIMIT_SLOW,
                    'policy' => 'fixed_window',
                    'limit' => 1,
                    'interval' => '1 hour',
                ],
            ],
            'jsonLogicOperations' => array_map(static fn (string $name): array => ['name' => $name], $operations),
            'listens' => $listens,
        ];
    }

    /**
     * Install and enable the plugin (the row with its manifest) and reset the plugin caches.
     *
     * @param list<string> $listens
     * @param list<string> $operations
     */
    public static function install(
        EntityManagerInterface $em,
        PluginCacheInvalidator $invalidator,
        array $listens = [],
        array $operations = [QaDoubleJsonLogicOperation::NAME],
    ): Plugin {
        $data = self::manifestData($listens, $operations);

        $plugin = new Plugin(self::PLUGIN_ID, 'QA users plugin', '1.0.0', '0.2.0');
        $plugin->setEnabled(true);
        $plugin->setManifestJson($data);
        $em->persist($plugin);
        $em->flush();

        $invalidator->invalidatePluginSurfaceCaches();

        return $plugin;
    }

    /** Enable or disable the installed plugin the way the lifecycle does: the row, then the caches. */
    public static function setEnabled(Plugin $plugin, bool $enabled, EntityManagerInterface $em, PluginCacheInvalidator $invalidator): void
    {
        $plugin->setEnabled($enabled);
        $em->flush();
        $invalidator->invalidatePluginSurfaceCaches();
    }

    /**
     * Drop what {@see install()} left in the cache pool. The pool outlives the database
     * rollback, so the next test would otherwise find a plugin that is gone. Call it from
     * `tearDown()`, before the parent's.
     */
    public static function forget(PluginCacheInvalidator $invalidator): void
    {
        $invalidator->invalidatePluginSurfaceCaches();
    }
}
