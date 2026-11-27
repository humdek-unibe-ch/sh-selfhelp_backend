<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\ScheduledJob;

/**
 * The way a plugin queues one of its own job types outside an action (contract C13).
 *
 * A job is a row of `scheduled_jobs`, the one queue of the system (rule 6.1);
 * the scheduler runs it and hands it to the plugin's
 * {@see PluginScheduledJobHandlerInterface} like any other job. A job that is
 * due at once wakes the scheduler after the commit that created it, so it
 * starts within about two seconds. A listener of a domain event (C24) may use
 * this to start work; it may not write data itself.
 *
 * The config is stored as given and handed back to the handler through
 * `ScheduledJob::getConfig()`. Keep it small and free of secrets (read those
 * through `PluginSettingsInterface` when the job runs). The keys `condition`,
 * `schedule`, `action_job_type` and `recurring` belong to the host.
 */
interface PluginJobQueueInterface
{
    /**
     * Queue a job that is due now.
     *
     * @param string $pluginId the enabled plugin that offers `$type`
     * @param string $type a job type from the plugin's manifest `scheduledJobs`
     * @param array<string, mixed> $config the job's config
     * @param int|null $userId the user the job is for, or null for a job of the system
     *
     * @return int the id of the new scheduled job
     *
     * @throws \InvalidArgumentException when the plugin is not enabled, does not offer the type,
     *                                   the config uses a key of the host, or the user does not exist
     * @throws \RuntimeException when the job could not be stored
     */
    public function queueNow(string $pluginId, string $type, array $config, ?int $userId): int;

    /**
     * Queue a job that is due at the given time.
     *
     * @param string $pluginId the enabled plugin that offers `$type`
     * @param string $type a job type from the plugin's manifest `scheduledJobs`
     * @param array<string, mixed> $config the job's config
     * @param int|null $userId the user the job is for, or null for a job of the system
     * @param \DateTimeImmutable $at when the job is due; any time zone, stored as UTC
     *
     * @return int the id of the new scheduled job
     *
     * @throws \InvalidArgumentException as {@see queueNow()}
     * @throws \RuntimeException when the job could not be stored
     */
    public function queueAt(string $pluginId, string $type, array $config, ?int $userId, \DateTimeImmutable $at): int;
}
