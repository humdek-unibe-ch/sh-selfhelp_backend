<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\ScheduledJob;

use App\Entity\ScheduledJob;

/**
 * Indexes every tagged {@see PluginScheduledJobHandlerInterface} by
 * the job-type code it claims.
 *
 * Wired with `!tagged_iterator selfhelp.plugin.scheduled_job_handler`
 * in `config/services.yaml`. Used by
 * {@see \App\Service\Core\JobSchedulerService::executeByType()} to
 * dispatch plugin-contributed scheduled jobs at runtime — without it
 * the `ScheduledJobTypeEvent.handlerServiceId` field was admin-UI-
 * only data that the scheduler silently ignored.
 *
 * Plugins that contribute a job type MUST register the matching
 * handler with the right tag, otherwise the scheduler falls through
 * to its default (return false → job marked `failed`) for that type.
 */
final class PluginScheduledJobRegistry
{
    /** @var array<string, PluginScheduledJobHandlerInterface> */
    private array $handlersByType = [];

    /**
     * @param iterable<PluginScheduledJobHandlerInterface> $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $type = $handler->getSupportedJobType();
            if ($type === '') {
                continue;
            }
            // Last-registered-wins on duplicate job-type codes. Two
            // plugins claiming the same `jobTypes` code is a packaging
            // bug; the admin doctor command flags it on its own pass.
            $this->handlersByType[$type] = $handler;
        }
    }

    public function has(string $jobType): bool
    {
        return isset($this->handlersByType[$jobType]);
    }

    public function get(string $jobType): ?PluginScheduledJobHandlerInterface
    {
        return $this->handlersByType[$jobType] ?? null;
    }

    /**
     * Execute the plugin-registered handler for `$jobType`. Returns
     * `null` when no handler claims the type (caller can fall through
     * to core dispatch), otherwise the bool result of the handler.
     */
    public function execute(string $jobType, ScheduledJob $job, string $transactionBy): ?bool
    {
        $handler = $this->get($jobType);
        if ($handler === null) {
            return null;
        }
        return $handler->execute($job, $transactionBy);
    }
}
