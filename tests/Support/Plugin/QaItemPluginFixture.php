<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Entity\Plugin\Plugin;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Manifest\PluginManifest;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An enabled QA plugin that registers one resource type, one style that holds
 * an item of it and the four permissions of the type: everything the plugin
 * resource contracts (C1 to C9, C24) need in a test, installed the way a real
 * plugin's catalogue is (manifest, catalogue sync, cache reset; rule 7.8).
 *
 * Everything runs inside the test's DAMA transaction and is rolled back.
 *
 * | Thing | Name |
 * | --- | --- |
 * | plugin id | `qa-items-plugin` |
 * | resource type | `qa_item` (label "QA item") |
 * | style / field | `qa-item-block` / `qa_item_id` (a property field; `resourceFields` maps it to the type) |
 * | permissions | `qa_plugin.items.read`, `.create`, `.update`, `.delete` (all owned by the plugin) |
 * | cache category | `qa-items-plugin.items`, 120 seconds |
 * | job type / property | `qa_item_job` / `item_id` (an action job whose config holds an item of the type) |
 */
final class QaItemPluginFixture
{
    public const PLUGIN_ID = 'qa-items-plugin';
    public const TYPE_CODE = 'qa_item';
    public const STYLE = 'qa-item-block';
    public const FIELD = 'qa_item_id';
    public const PERMISSION_READ = 'qa_plugin.items.read';
    public const PERMISSION_CREATE = 'qa_plugin.items.create';
    public const PERMISSION_UPDATE = 'qa_plugin.items.update';
    public const PERMISSION_DELETE = 'qa_plugin.items.delete';
    public const CACHE_CATEGORY = self::PLUGIN_ID . '.items';
    public const CACHE_LIFETIME_SECONDS = 120;
    public const JOB_TYPE = 'qa_item_job';
    public const JOB_FIELD = 'item_id';

    /**
     * The manifest as the installer stores it.
     *
     * @param list<string> $listens the domain events the plugin lists (C24)
     * @return array<string, mixed>
     */
    public static function manifestData(array $listens = []): array
    {
        return [
            'id' => self::PLUGIN_ID,
            'name' => 'QA items plugin',
            'version' => '1.0.0',
            'pluginApiVersion' => '0.2.0',
            'styleGroups' => [['name' => 'qa-items-group', 'description' => 'QA items', 'position' => 991]],
            'fieldTypes' => [['name' => 'qa-items-type', 'position' => 991]],
            'fields' => [['name' => self::FIELD, 'type' => 'qa-items-type', 'translatable' => false]],
            'styles' => [[
                'name' => self::STYLE,
                'description' => 'QA item block',
                'canHaveChildren' => false,
                'group' => 'qa-items-group',
                'fields' => [['name' => self::FIELD, 'title' => 'Item']],
                'resourceFields' => [self::FIELD => self::TYPE_CODE],
            ]],
            'permissions' => [
                ['key' => self::PERMISSION_READ, 'description' => 'QA items: read'],
                ['key' => self::PERMISSION_CREATE, 'description' => 'QA items: create'],
                ['key' => self::PERMISSION_UPDATE, 'description' => 'QA items: update'],
                ['key' => self::PERMISSION_DELETE, 'description' => 'QA items: delete'],
            ],
            'lookups' => ['extends' => [[
                'typeCode' => LookupService::RESOURCE_TYPES,
                'ownership' => 'plugin_extendable',
                'entries' => [['code' => self::TYPE_CODE, 'value' => 'QA item']],
            ]]],
            'cacheCategories' => [['name' => 'items', 'ttlSeconds' => self::CACHE_LIFETIME_SECONDS]],
            'scheduledJobs' => [[
                'type' => self::JOB_TYPE,
                'description' => 'QA item job',
                'capability' => 'scheduledJobs',
                'label' => 'QA item job',
                'actionPlannable' => true,
                'configSchema' => [
                    'type' => 'object',
                    'properties' => [self::JOB_FIELD => ['type' => 'integer', 'title' => 'Item']],
                    'required' => [self::JOB_FIELD],
                ],
                'resourceFields' => [self::JOB_FIELD => self::TYPE_CODE],
            ]],
            'listens' => $listens,
        ];
    }

    /**
     * Install and enable the plugin: the plugin row (with its manifest), the catalogue rows and a fresh plugin state.
     *
     * @param list<string> $listens the domain events the plugin lists (C24)
     */
    public static function install(
        EntityManagerInterface $em,
        PluginCatalogueSynchronizer $synchronizer,
        PluginCacheInvalidator $invalidator,
        array $listens = [],
    ): Plugin {
        $data = self::manifestData($listens);

        $plugin = new Plugin(self::PLUGIN_ID, 'QA items plugin', '1.0.0', '0.2.0');
        $plugin->setEnabled(true);
        $plugin->setManifestJson($data);
        $em->persist($plugin);
        $em->flush();

        $synchronizer->sync($plugin, new PluginManifest($data));
        $invalidator->invalidatePluginSurfaceCaches();

        return $plugin;
    }

    /**
     * Drop what {@see install()} left in the cache pool. The pool outlives the database
     * rollback, so the next test would otherwise find a plugin that is gone. Call it from
     * `tearDown()`, before the parent's.
     */
    public static function forgetCaches(CacheService $cache, PluginCacheInvalidator $invalidator): void
    {
        $cache->withCategory(self::CACHE_CATEGORY)->invalidateCategory();
        $invalidator->invalidatePluginSurfaceCaches();
    }
}
