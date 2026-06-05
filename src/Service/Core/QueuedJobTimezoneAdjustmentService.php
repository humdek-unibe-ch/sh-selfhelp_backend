<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Core;

use App\Repository\ScheduledJobRepository;
use App\Service\Cache\Core\CacheService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Recalculates queued, future, wall-clock scheduled jobs when a user changes
 * their timezone so the intended local delivery time (e.g. 07:00) is preserved.
 *
 * Only jobs with `config.schedule.wall_clock = true`, status `queued`, and a
 * future execution time are adjusted. Relative ("after N hours") jobs and
 * already-due/terminal jobs are never recalculated.
 */
class QueuedJobTimezoneAdjustmentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ScheduledJobRepository $scheduledJobRepository,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Adjust a user's queued future wall-clock jobs to a new timezone.
     *
     * @param int $userId
     *   The user whose queued future jobs should be recalculated.
     * @param string $newTimezoneId
     *   The user's new timezone identifier (e.g. `Europe/Zurich`).
     *
     * @return int
     *   The number of jobs whose execution time was recalculated.
     */
    public function adjustForUser(int $userId, string $newTimezoneId): int
    {
        $newTimezone = $this->safeTimezone($newTimezoneId);
        if ($newTimezone === null) {
            return 0;
        }

        $utc = new \DateTimeZone('UTC');
        $now = new \DateTime('now', $utc);
        $jobs = $this->scheduledJobRepository->findQueuedFutureJobsForUser($userId, $now);

        $adjusted = 0;
        foreach ($jobs as $job) {
            $config = $job->getConfig() ?? [];
            $schedule = isset($config['schedule']) && is_array($config['schedule']) ? $config['schedule'] : [];

            if (($schedule['wall_clock'] ?? false) !== true) {
                continue;
            }

            $oldTimezone = $this->safeTimezone(is_string($schedule['timezone'] ?? null) ? $schedule['timezone'] : '')
                ?? $utc;

            // Reinterpret the same local wall-clock time in the new timezone.
            $localWall = \DateTime::createFromInterface($job->getDateToBeExecuted())
                ->setTimezone($oldTimezone)
                ->format('Y-m-d H:i:s');

            $newUtc = (new \DateTime($localWall, $newTimezone))->setTimezone($utc);
            if ($newUtc->getTimestamp() === $job->getDateToBeExecuted()->getTimestamp()
                && ($schedule['timezone'] ?? null) === $newTimezoneId) {
                continue;
            }

            $job->setDateToBeExecuted($newUtc);
            $schedule['timezone'] = $newTimezoneId;
            $schedule['timezone_source'] = 'user';
            $config['schedule'] = $schedule;
            $job->setConfig($config);

            $adjusted++;
        }

        if ($adjusted === 0) {
            return 0;
        }

        $this->entityManager->flush();

        $this->transactionService->logTransaction(
            LookupService::TRANSACTION_TYPES_UPDATE,
            LookupService::TRANSACTION_BY_BY_USER,
            'scheduled_jobs',
            null,
            false,
            sprintf('Recalculated %d queued wall-clock job(s) to timezone %s for user %d', $adjusted, $newTimezoneId, $userId)
        );

        $this->cache
            ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
            ->invalidateAllListsInCategory();

        $this->logger->info('Adjusted queued wall-clock jobs for timezone change', [
            'userId' => $userId,
            'newTimezone' => $newTimezoneId,
            'adjusted' => $adjusted,
        ]);

        return $adjusted;
    }

    private function safeTimezone(string $timezoneId): ?\DateTimeZone
    {
        if ($timezoneId === '') {
            return null;
        }

        try {
            return new \DateTimeZone($timezoneId);
        } catch (\Throwable) {
            return null;
        }
    }
}
