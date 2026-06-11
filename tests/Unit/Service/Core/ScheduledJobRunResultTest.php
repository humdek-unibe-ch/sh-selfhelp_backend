<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Core;

use App\Entity\ScheduledJobRunnerRun;
use App\Service\Core\ScheduledJobRunResult;
use PHPUnit\Framework\TestCase;

/**
 * Slice B: the runner run-result summarises one due-jobs sweep. Individual job
 * failures/skips are not infrastructure failures; only a runner-level error is.
 */
final class ScheduledJobRunResultTest extends TestCase
{
    public function testSucceededRunWithFailedJobsIsStillInfrastructureSuccess(): void
    {
        $result = new ScheduledJobRunResult(
            ScheduledJobRunnerRun::STATUS_SUCCEEDED,
            dueCount: 5,
            attemptedCount: 5,
            doneCount: 3,
            failedCount: 1,
            skippedCount: 1,
            lockAcquired: true,
            runId: 99,
        );

        self::assertTrue($result->isInfrastructureSuccess());
        self::assertSame('succeeded', $result->status);
        self::assertSame(5, $result->dueCount);
        self::assertSame(1, $result->failedCount);
        self::assertSame(1, $result->skippedCount);
    }

    public function testLockedRunIsInfrastructureSuccess(): void
    {
        $result = new ScheduledJobRunResult(ScheduledJobRunnerRun::STATUS_SKIPPED_LOCKED, lockAcquired: false);

        self::assertTrue($result->isInfrastructureSuccess());
        self::assertFalse($result->lockAcquired);
    }

    public function testFailedRunIsInfrastructureFailure(): void
    {
        $result = new ScheduledJobRunResult(
            ScheduledJobRunnerRun::STATUS_FAILED,
            errorMessage: 'database unreachable',
        );

        self::assertFalse($result->isInfrastructureSuccess());
        self::assertSame('database unreachable', $result->errorMessage);
    }

    public function testToArrayExposesAllCounters(): void
    {
        $result = new ScheduledJobRunResult(
            ScheduledJobRunnerRun::STATUS_SUCCEEDED,
            dueCount: 2,
            attemptedCount: 2,
            doneCount: 2,
            runId: 7,
        );

        $array = $result->toArray();

        self::assertSame('succeeded', $array['status']);
        self::assertSame(2, $array['due_count']);
        self::assertSame(2, $array['done_count']);
        self::assertSame(0, $array['failed_count']);
        self::assertSame(7, $array['run_id']);
        self::assertArrayHasKey('lock_acquired', $array);
    }
}
