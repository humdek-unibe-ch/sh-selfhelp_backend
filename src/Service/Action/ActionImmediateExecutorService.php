<?php

namespace App\Service\Action;

use App\Entity\ScheduledJob;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;

/**
 * Executes scheduled jobs that are already due at the time they are created.
 *
 * This keeps legacy "immediate" action behavior intact while still using the
 * scheduled-jobs table as the single persistence model.
 */
class ActionImmediateExecutorService
{
    public function __construct(
        private readonly JobSchedulerService $jobSchedulerService
    ) {
    }

    /**
     * @param ScheduledJob[] $jobs
     *   The jobs just created by the action scheduler.
     * @param string $transactionBy
     *   The transaction origin recorded for any immediate execution.
     */
    public function executeDueNow(array $jobs, string $transactionBy = LookupService::TRANSACTION_BY_BY_SYSTEM): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($jobs as $job) {
            if ($job->getDateToBeExecuted() > $now) {
                continue;
            }

            $this->jobSchedulerService->executeJob($job->getId(), $transactionBy);
        }
    }
}
