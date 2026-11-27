<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Exception\ServiceException;
use App\Plugin\Resource\ResourceBindingGuard;
use App\Plugin\ScheduledJob\PluginActionJobTypes;
use App\Plugin\ScheduledJob\PluginJobConfigValidator;
use App\Service\Action\ActionConfig;
use App\Service\Core\BaseService;
use App\Service\Core\UserContextAwareService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Author-time check of the plugin jobs in an action config (contract C12).
 *
 * A job whose `job_type` is not one of the core values must name a job type
 * that an enabled plugin offers to actions ({@see PluginActionJobTypes}), and
 * its `plugin_config` must follow that type's `configSchema`. The properties
 * the type lists in `resourceFields` hold ids of plugin items; the person
 * saving the action must hold the view grant on each of them (C8, the same
 * check as for a section: {@see ResourceBindingGuard}).
 *
 * The check runs when the action is saved, against the person saving it. The
 * action runs later with system rights, so a config that reached the database
 * another way is not protected by this class (the planner only plans types it
 * finds registered).
 *
 * Refusals:
 *  - 400 `unknown_job_type`: no enabled plugin offers the type;
 *  - 400 `invalid_plugin_job_config`: `plugin_config` breaks the schema; the
 *    data names the failing `path` (for example `blocks.0.jobs.1.plugin_config.item_id`);
 *  - 403 `resource_not_bindable`: an item the person may not view.
 *
 * Reminders of a job are core notifications; a plugin type is read only on the
 * jobs of a block.
 */
class ActionPluginJobValidator extends BaseService
{
    public const ERROR_UNKNOWN_JOB_TYPE = 'unknown_job_type';
    public const ERROR_INVALID_CONFIG = 'invalid_plugin_job_config';

    public function __construct(
        private readonly PluginActionJobTypes $jobTypes,
        private readonly PluginJobConfigValidator $configValidator,
        private readonly ResourceBindingGuard $bindingGuard,
        private readonly UserContextAwareService $userContext,
    ) {
    }

    /**
     * @param mixed $config the action config as sent: an array or JSON text
     *
     * @throws ServiceException 400 `unknown_job_type`, 400 `invalid_plugin_job_config`, 403 `resource_not_bindable`
     */
    public function assertValid(mixed $config): void
    {
        $decoded = $this->decode($config);
        $userId = (int) ($this->userContext->getCurrentUser()?->getId() ?? 0);

        foreach ($this->asArray($decoded[ActionConfig::BLOCKS] ?? null) as $blockIndex => $block) {
            foreach ($this->asArray($this->asArray($block)[ActionConfig::JOBS] ?? null) as $jobIndex => $job) {
                $job = $this->asArray($job);
                $jobType = $job[ActionConfig::JOB_TYPE] ?? null;
                if (!is_string($jobType) || PluginActionJobTypes::isCoreType($jobType)) {
                    continue;
                }

                $this->assertJob($jobType, $job[ActionConfig::PLUGIN_CONFIG] ?? null, sprintf('blocks.%s.jobs.%s', $blockIndex, $jobIndex), $userId);
            }
        }
    }

    private function assertJob(string $jobType, mixed $pluginConfig, string $jobPath, int $userId): void
    {
        $type = $this->jobTypes->find($jobType);
        if ($type === null) {
            throw new ServiceException(self::ERROR_UNKNOWN_JOB_TYPE, Response::HTTP_BAD_REQUEST, [
                'job_type' => $jobType,
                'path' => $jobPath . '.' . ActionConfig::JOB_TYPE,
            ]);
        }

        $configPath = $jobPath . '.' . ActionConfig::PLUGIN_CONFIG;
        $failures = $this->configValidator->validate($type['config_schema'], $pluginConfig);
        if ($failures !== []) {
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = [
                    'path' => $failure['path'] === '' ? $configPath : $configPath . '.' . $failure['path'],
                    'message' => $failure['message'],
                ];
            }

            throw new ServiceException(self::ERROR_INVALID_CONFIG, Response::HTTP_BAD_REQUEST, [
                'job_type' => $jobType,
                'path' => $errors[0]['path'],
                'errors' => $errors,
            ]);
        }

        $values = is_array($pluginConfig) ? $pluginConfig : [];
        foreach ($type['resource_fields'] as $property => $resourceType) {
            if (array_key_exists($property, $values)) {
                $this->bindingGuard->assertBindable($resourceType, $values[$property], $userId);
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private function decode(mixed $config): array
    {
        $decoded = $config;
        while (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
