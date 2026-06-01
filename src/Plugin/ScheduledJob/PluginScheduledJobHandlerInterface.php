<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\ScheduledJob;

use App\Entity\ScheduledJob;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('selfhelp.plugin.scheduled_job_handler')]

/**
 * Contract for plugin-contributed scheduled-job executors.
 *
 * A plugin that wants to register a new scheduled-job type does two
 * things:
 *
 *   1. Declares the type via {@see \App\Plugin\Event\ScheduledJobTypeEvent}
 *      so it shows up in the admin job-type picker (with the human
 *      `description`, optional `configSchemaPath`, and the handler's
 *      service id).
 *   2. Registers a service implementing this interface and tags it
 *      with `selfhelp.plugin.scheduled_job_handler`. The host wires
 *      the tagged iterator into {@see \App\Service\Core\JobSchedulerService}
 *      which dispatches by `getSupportedJobType()`.
 *
 * The handler MUST be idempotent on retries and MUST NOT throw on
 * domain failures — return `false` instead so the scheduler updates
 * the job status to `failed` cleanly. Throwing is reserved for hard
 * infrastructure errors that should also surface in the scheduler's
 * outer try/catch.
 */
interface PluginScheduledJobHandlerInterface
{
    /**
     * Lookup code of the `jobTypes` lookup row this handler is bound
     * to. Must match the value registered via
     * {@see \App\Plugin\Event\ScheduledJobTypeEvent::addJobType()} so
     * the admin catalog and the runtime dispatcher agree.
     */
    public function getSupportedJobType(): string;

    /**
     * Execute the job. Return `true` on success, `false` on domain
     * failure. The scheduler updates `scheduled_jobs.status` and the
     * audit trail based on the return value. Throwing is reserved
     * for unrecoverable infrastructure errors.
     *
     * @param string $transactionBy `transactionBy` value forwarded
     *                              by the scheduler so the plugin can
     *                              write its own transaction rows
     *                              with the same origin tag.
     */
    public function execute(ScheduledJob $job, string $transactionBy): bool;
}
