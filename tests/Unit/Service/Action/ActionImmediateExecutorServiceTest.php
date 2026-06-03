<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Entity\ScheduledJob;
use App\Service\Action\ActionImmediateExecutorService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the immediate executor: jobs whose execution time is already in
 * the past must run immediately, future-dated jobs must be left for the
 * scheduler to pick up later.
 */
final class ActionImmediateExecutorServiceTest extends TestCase
{
    public function testOnlyDueJobsAreExecutedImmediately(): void
    {
        $dueJob = $this->createStub(ScheduledJob::class);
        $dueJob->method('getId')->willReturn(10);
        $dueJob->method('getDateToBeExecuted')->willReturn(new \DateTime('-1 minute', new \DateTimeZone('UTC')));

        $futureJob = $this->createStub(ScheduledJob::class);
        $futureJob->method('getId')->willReturn(20);
        $futureJob->method('getDateToBeExecuted')->willReturn(new \DateTime('+1 day', new \DateTimeZone('UTC')));

        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->expects(self::once())
            ->method('executeJob')
            ->with(10, LookupService::TRANSACTION_BY_BY_SYSTEM);

        (new ActionImmediateExecutorService($jobScheduler))
            ->executeDueNow([$dueJob, $futureJob]);
    }

    public function testNoJobsMeansNoExecution(): void
    {
        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->expects(self::never())->method('executeJob');

        (new ActionImmediateExecutorService($jobScheduler))->executeDueNow([]);
    }
}
