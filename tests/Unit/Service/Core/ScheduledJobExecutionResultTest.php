<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Core;

use App\Service\Core\LookupService;
use App\Service\Core\ScheduledJobExecutionResult;
use PHPUnit\Framework\TestCase;

/**
 * Slice A: the execution result must let the executor tell a domain failure
 * apart from an intentional preference skip, so a skipped delivery never marks
 * the job failed nor fails the due-runner.
 */
final class ScheduledJobExecutionResultTest extends TestCase
{
    public function testDoneResult(): void
    {
        $result = ScheduledJobExecutionResult::done('sent');

        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_DONE, $result->getFinalStatusCode());
        self::assertTrue($result->isDone());
        self::assertFalse($result->isFailed());
        self::assertFalse($result->isSkipped());
        self::assertTrue($result->isSuccessfulForRunnerMetrics());
        self::assertSame('sent', $result->getMessage());
    }

    public function testFailedResult(): void
    {
        $result = ScheduledJobExecutionResult::failed('smtp down');

        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_FAILED, $result->getFinalStatusCode());
        self::assertFalse($result->isDone());
        self::assertTrue($result->isFailed());
        self::assertFalse($result->isSkipped());
        self::assertFalse($result->isSuccessfulForRunnerMetrics());
    }

    public function testSkippedResultIsNotAFailure(): void
    {
        $result = ScheduledJobExecutionResult::skipped(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS,
            'user disabled emails',
        );

        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS,
            $result->getFinalStatusCode(),
        );
        self::assertTrue($result->isSkipped());
        self::assertFalse($result->isDone());
        self::assertFalse($result->isFailed());
        self::assertTrue($result->isSuccessfulForRunnerMetrics());
    }
}
