<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Core;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;

/**
 * How long one run of a job type may take before the runner stops it (contract C22).
 *
 * A plugin sets `scheduledJobs[].timeoutSeconds` (10 to 900) in its manifest; every other
 * job type, the core ones included, gets {@see DEFAULT_SECONDS}. The limit applies to jobs
 * the runner starts in child processes (`execute-due --concurrency`); a job that is run
 * inside the process of the caller, for example by `execute-one` typed by hand, is not
 * interrupted.
 */
class ScheduledJobTimeouts
{
    /** The limit of a job type that does not declare one: the core job types and plugin types without `timeoutSeconds`. */
    public const DEFAULT_SECONDS = PluginManifest::DEFAULT_JOB_TIMEOUT_SECONDS;

    public function __construct(private readonly PluginRegistryService $plugins)
    {
    }

    /**
     * @param string $jobType the code of the `jobTypes` lookup of the job
     *
     * @return int seconds, 120 for a type no enabled plugin declares
     */
    public function secondsFor(string $jobType): int
    {
        foreach ($this->plugins->getEnabled() as $plugin) {
            $manifest = new PluginManifest($plugin->getManifestJson());
            foreach ($manifest->getScheduledJobs() as $job) {
                if (($job['type'] ?? null) === $jobType) {
                    return $manifest->getJobTimeoutSeconds($jobType);
                }
            }
        }

        return self::DEFAULT_SECONDS;
    }
}
