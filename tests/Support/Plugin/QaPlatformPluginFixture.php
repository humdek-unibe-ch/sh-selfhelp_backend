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
 * The QA plugin of the platform contracts of part 7: the job types an action may plan (C12), jobs
 * started at once and queued by the plugin (C13), settings and secrets (C14), parallel jobs with a
 * timeout (C22), recurring jobs (C25) and a topic for one user (C18). It is the QA items plugin
 * ({@see QaItemPluginFixture}: resource type `qa_item`, its permissions) with a larger manifest,
 * installed the same way: manifest, catalogue sync, cache reset.
 *
 * The handlers of the job types are {@see QaPlatformJobHandler} services, one per type, wired in
 * `config/packages/test/plugin_platform_services.yaml`.
 *
 * | Thing | Name |
 * | --- | --- |
 * | plugin id | `qa-items-plugin` |
 * | action job type | `qa_note_job`: `item_id` (resource `qa_item`), `note` (`variable-text`), `priority` (1 to 5) |
 * | other job types | `qa_sleep_job`, `qa_timeout_job` (`timeoutSeconds` 10), `qa_sweep_job` (recurring) |
 * | recurring entry | `qa_sweep`, `qa_sweep_job`, every 5 minutes |
 * | settings | `api_url` (url, env `QA_PLATFORM_API_URL`), `api_key` (secret, required), `batch_size` (number, default 10), `verbose` (boolean), `label` (string) |
 * | realtime topics | `qa-progress` (for one user at a time), `qa-restricted` (needs `admin.action.read`) |
 */
final class QaPlatformPluginFixture
{
    public const PLUGIN_ID = QaItemPluginFixture::PLUGIN_ID;

    public const NOTE_JOB = 'qa_note_job';
    public const SLEEP_JOB = 'qa_sleep_job';
    public const TIMEOUT_JOB = 'qa_timeout_job';
    public const SWEEP_JOB = 'qa_sweep_job';
    public const TIMEOUT_JOB_SECONDS = 10;

    public const RECURRING_NAME = 'qa_sweep';
    public const RECURRING_CRON = '*/5 * * * *';

    public const SETTING_URL = 'api_url';
    public const SETTING_URL_ENV = 'QA_PLATFORM_API_URL';
    public const SETTING_KEY = 'api_key';
    public const SETTING_BATCH = 'batch_size';
    public const SETTING_VERBOSE = 'verbose';
    public const SETTING_LABEL = 'label';

    public const TOPIC = 'qa-progress';
    public const RESTRICTED_TOPIC = 'qa-restricted';
    public const RESTRICTED_TOPIC_PERMISSION = 'admin.action.read';

    /** The job types the fixture declares, each with the {@see QaPlatformJobHandler} that serves it. */
    public const JOB_TYPES = [self::NOTE_JOB, self::SLEEP_JOB, self::TIMEOUT_JOB, self::SWEEP_JOB];

    /**
     * The manifest as the installer stores it.
     *
     * @return array<string, mixed>
     */
    public static function manifestData(): array
    {
        $data = QaItemPluginFixture::manifestData();
        $data['name'] = 'QA platform plugin';
        $data['pluginApiVersion'] = '0.2.0';

        $job = static fn (string $type, string $label, array $extra = []): array => array_merge([
            'type' => $type,
            'description' => $label,
            'capability' => 'scheduledJobs',
            'label' => $label,
        ], $extra);

        $data['scheduledJobs'] = [
            $job(self::NOTE_JOB, 'QA note job', [
                'actionPlannable' => true,
                'configSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'item_id' => ['type' => 'integer', 'title' => 'Item'],
                        'note' => ['type' => 'string', 'title' => 'Note', 'maxLength' => 200, 'format' => 'variable-text'],
                        'priority' => ['type' => 'integer', 'title' => 'Priority', 'minimum' => 1, 'maximum' => 5],
                    ],
                    'required' => ['item_id'],
                ],
                'resourceFields' => ['item_id' => QaItemPluginFixture::TYPE_CODE],
            ]),
            $job(self::SLEEP_JOB, 'QA sleep job'),
            $job(self::TIMEOUT_JOB, 'QA timeout job', ['timeoutSeconds' => self::TIMEOUT_JOB_SECONDS]),
            $job(self::SWEEP_JOB, 'QA sweep job'),
        ];

        $data['recurringJobs'] = [[
            'name' => self::RECURRING_NAME,
            'type' => self::SWEEP_JOB,
            'cron' => self::RECURRING_CRON,
            'description' => 'QA recurring sweep',
        ]];

        $data['settings'] = [
            ['key' => self::SETTING_URL, 'label' => 'API URL', 'type' => 'url', 'envOverride' => self::SETTING_URL_ENV],
            ['key' => self::SETTING_KEY, 'label' => 'API key', 'type' => 'string', 'secret' => true, 'required' => true],
            ['key' => self::SETTING_BATCH, 'label' => 'Batch size', 'type' => 'number', 'default' => 10],
            ['key' => self::SETTING_VERBOSE, 'label' => 'Verbose', 'type' => 'boolean', 'default' => false],
            ['key' => self::SETTING_LABEL, 'label' => 'Label', 'type' => 'string'],
        ];

        $data['realtimeTopics'] = [
            ['key' => self::TOPIC, 'description' => 'QA progress, one user at a time'],
            [
                'key' => self::RESTRICTED_TOPIC,
                'description' => 'QA updates for readers of actions',
                'requiredPermission' => self::RESTRICTED_TOPIC_PERMISSION,
            ],
        ];

        $lookups = $data['lookups'] ?? [];
        $extends = is_array($lookups) && is_array($lookups['extends'] ?? null) ? $lookups['extends'] : [];
        $extends[] = [
            'typeCode' => LookupService::JOB_TYPES,
            'ownership' => 'plugin_extendable',
            'entries' => array_map(
                static fn (string $type): array => ['code' => $type, 'value' => $type],
                self::JOB_TYPES,
            ),
        ];
        $data['lookups'] = ['extends' => $extends];

        return $data;
    }

    /**
     * Install and enable the plugin: the plugin row (with its manifest), the catalogue rows and a
     * fresh plugin state. Everything runs inside the test's DAMA transaction and is rolled back.
     */
    public static function install(
        EntityManagerInterface $em,
        PluginCatalogueSynchronizer $synchronizer,
        PluginCacheInvalidator $invalidator,
        bool $enabled = true,
    ): Plugin {
        $data = self::manifestData();

        $plugin = new Plugin(self::PLUGIN_ID, 'QA platform plugin', '1.0.0', '0.2.0');
        $plugin->setEnabled($enabled);
        $plugin->setManifestJson($data);
        $em->persist($plugin);
        $em->flush();

        $synchronizer->sync($plugin, new PluginManifest($data));
        $invalidator->invalidatePluginSurfaceCaches();

        return $plugin;
    }

    /**
     * Drop what {@see install()} left in the cache pool (it outlives the database rollback). Call it
     * from `tearDown()`.
     */
    public static function forgetCaches(CacheService $cache, PluginCacheInvalidator $invalidator): void
    {
        QaItemPluginFixture::forgetCaches($cache, $invalidator);
    }
}
