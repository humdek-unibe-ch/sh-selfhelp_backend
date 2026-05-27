<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when the scheduled-job service enumerates known job types
 * (admin UI dropdown, executor dispatch table). Plugins may register
 * their own job types here without modifying the core service.
 *
 * Each job type must be declared in `plugin.json` under `scheduledJobs`
 * and requires the `scheduledJobs` capability.
 */
final class ScheduledJobTypeEvent extends Event
{
    /**
     * @var array<int, array{
     *   pluginId: string,
     *   type: string,
     *   description: string,
     *   handlerServiceId: string,
     *   configSchemaPath: string|null,
     * }>
     */
    private array $jobTypes = [];

    public function addJobType(
        string $pluginId,
        string $type,
        string $description,
        string $handlerServiceId,
        ?string $configSchemaPath = null,
    ): void {
        $this->jobTypes[] = [
            'pluginId' => $pluginId,
            'type' => $type,
            'description' => $description,
            'handlerServiceId' => $handlerServiceId,
            'configSchemaPath' => $configSchemaPath,
        ];
    }

    /**
     * @return array<int, array{
     *   pluginId: string,
     *   type: string,
     *   description: string,
     *   handlerServiceId: string,
     *   configSchemaPath: string|null,
     * }>
     */
    public function getJobTypes(): array
    {
        return $this->jobTypes;
    }
}
