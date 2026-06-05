<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Core;

/**
 * Typed outcome of executing one scheduled job.
 *
 * Job handlers (`executeEmailJob`, `executeNotificationJob`, ...) return this
 * instead of a bare bool so the executor can distinguish a domain failure
 * (`failed`) from an intentional non-delivery (`skipped_*`, issue #29) and pick
 * the correct terminal `scheduledJobsStatus` lookup code. A skipped delivery is
 * not a failure: it does not mark the job `failed` and does not fail the
 * due-runner command.
 */
final class ScheduledJobExecutionResult
{
    private function __construct(
        private readonly string $finalStatusCode,
        private readonly bool $successfulForRunnerMetrics,
        private readonly bool $skipped,
        private readonly string $message,
    ) {
    }

    /**
     * The job completed successfully.
     */
    public static function done(string $message = ''): self
    {
        return new self(LookupService::SCHEDULED_JOBS_STATUS_DONE, true, false, $message);
    }

    /**
     * The job failed for a domain/infrastructure reason.
     */
    public static function failed(string $message = ''): self
    {
        return new self(LookupService::SCHEDULED_JOBS_STATUS_FAILED, false, false, $message);
    }

    /**
     * The job was intentionally not delivered (e.g. recipient disabled the
     * channel). Terminal but not a failure.
     *
     * @param string $finalStatusCode
     *   A `scheduledJobsStatus` skipped lookup code.
     */
    public static function skipped(string $finalStatusCode, string $message = ''): self
    {
        return new self($finalStatusCode, true, true, $message);
    }

    /**
     * The terminal `scheduledJobsStatus` lookup code to persist on the job.
     */
    public function getFinalStatusCode(): string
    {
        return $this->finalStatusCode;
    }

    /**
     * Whether the runner should count this job as a non-failure for its
     * success metrics. `true` for both `done` and skipped outcomes.
     */
    public function isSuccessfulForRunnerMetrics(): bool
    {
        return $this->successfulForRunnerMetrics;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function isDone(): bool
    {
        return $this->finalStatusCode === LookupService::SCHEDULED_JOBS_STATUS_DONE;
    }

    public function isFailed(): bool
    {
        return !$this->successfulForRunnerMetrics;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
