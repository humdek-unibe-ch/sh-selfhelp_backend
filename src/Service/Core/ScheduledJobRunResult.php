<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Core;

/**
 * Outcome summary of one {@see ScheduledJobRunnerService::runDueJobs()} call.
 */
final class ScheduledJobRunResult
{
    public function __construct(
        public readonly string $status,
        public readonly int $dueCount = 0,
        public readonly int $attemptedCount = 0,
        public readonly int $doneCount = 0,
        public readonly int $failedCount = 0,
        public readonly int $skippedCount = 0,
        public readonly bool $lockAcquired = false,
        public readonly ?string $errorMessage = null,
        public readonly ?int $runId = null,
    ) {
    }

    /**
     * Whether the runner finished without an infrastructure-level failure.
     *
     * Individual jobs failing or being skipped does NOT make the run a failure;
     * only runner/infrastructure errors do.
     */
    public function isInfrastructureSuccess(): bool
    {
        return $this->status !== \App\Entity\ScheduledJobRunnerRun::STATUS_FAILED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'due_count' => $this->dueCount,
            'attempted_count' => $this->attemptedCount,
            'done_count' => $this->doneCount,
            'failed_count' => $this->failedCount,
            'skipped_count' => $this->skippedCount,
            'lock_acquired' => $this->lockAcquired,
            'error_message' => $this->errorMessage,
            'run_id' => $this->runId,
        ];
    }
}
