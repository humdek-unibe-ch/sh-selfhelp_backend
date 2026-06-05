<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Core;

use App\Entity\ScheduledJobRunnerRun;
use App\Entity\ScheduledJobRunnerSetting;
use App\Repository\ScheduledJobRepository;
use App\Repository\ScheduledJobRunnerRunRepository;
use App\Repository\ScheduledJobRunnerSettingRepository;
use App\Service\Cache\Core\CacheService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Docker-safe orchestrator for executing due scheduled jobs.
 *
 * Owns runner settings, the run-history audit, interval gating, and a shared
 * non-blocking lock so overlapping ticks cannot run concurrently. Per-job
 * execution stays in {@see JobSchedulerService::executeJob()}, which also
 * applies the atomic queued->running claim for double-execution safety.
 */
class ScheduledJobRunnerService
{
    private const LOCK_NAME = 'scheduled_jobs_runner';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ScheduledJobRepository $scheduledJobRepository,
        private readonly ScheduledJobRunnerSettingRepository $settingRepository,
        private readonly ScheduledJobRunnerRunRepository $runRepository,
        private readonly JobSchedulerService $jobSchedulerService,
        private readonly TransactionService $transactionService,
        private readonly CacheService $cache,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute due queued jobs subject to runner settings, interval, and lock.
     *
     * @param string $trigger
     *   One of the {@see ScheduledJobRunnerRun} TRIGGER_* values.
     * @param int|null $limit
     *   Per-invocation override for the maximum number of jobs.
     * @param bool $force
     *   Bypass the enabled flag and the interval gate (manual run-now).
     * @param bool $dryRun
     *   Report due counts and policy state without executing any job.
     */
    public function runDueJobs(string $trigger, ?int $limit = null, bool $force = false, bool $dryRun = false): ScheduledJobRunResult
    {
        $settings = $this->getOrCreateSettings();
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        if (!$settings->isEnabled() && !$force) {
            return $this->recordSkippedRun($trigger, ScheduledJobRunnerRun::STATUS_SKIPPED_DISABLED, $settings, $now);
        }

        if (!$force && !$dryRun && !$this->intervalElapsed($settings, $now)) {
            return $this->recordSkippedRun($trigger, ScheduledJobRunnerRun::STATUS_SKIPPED_INTERVAL, $settings, $now);
        }

        $lock = $this->lockFactory->createLock(self::LOCK_NAME, (float) $settings->getLockTtlSeconds());
        if (!$lock->acquire()) {
            return $this->recordSkippedRun($trigger, ScheduledJobRunnerRun::STATUS_SKIPPED_LOCKED, $settings, $now);
        }

        $run = $this->startRun($trigger, $settings, $now);

        try {
            $dueCount = $this->scheduledJobRepository->countDueQueuedJobs($now);
            $run->setDueCount($dueCount);

            if ($dryRun) {
                return $this->finishRun($run, ScheduledJobRunnerRun::STATUS_SUCCEEDED, true);
            }

            $effectiveLimit = $this->resolveLimit($limit, $settings);
            $jobs = $this->scheduledJobRepository->findDueQueuedJobs($now, $effectiveLimit);

            $attempted = 0;
            $done = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($jobs as $job) {
                $attempted++;
                $executed = $this->jobSchedulerService->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_CRON_JOB);

                if ($executed === false) {
                    $failed++;
                    continue;
                }

                $code = $executed->getStatus()->getLookupCode();
                if ($code === LookupService::SCHEDULED_JOBS_STATUS_DONE) {
                    $done++;
                } elseif (str_starts_with((string) $code, 'skipped')) {
                    $skipped++;
                } else {
                    $failed++;
                }
            }

            $run->setAttemptedCount($attempted)
                ->setDoneCount($done)
                ->setFailedCount($failed)
                ->setSkippedCount($skipped);

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_CHECK_SCHEDULEDJOBS,
                LookupService::TRANSACTION_BY_BY_CRON_JOB,
                'scheduled_job_runner_runs',
                $run->getId(),
                false,
                sprintf(
                    'Runner %s: due=%d attempted=%d done=%d failed=%d skipped=%d',
                    $trigger,
                    $dueCount,
                    $attempted,
                    $done,
                    $failed,
                    $skipped
                )
            );

            $this->cache
                ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
                ->invalidateAllListsInCategory();

            return $this->finishRun($run, ScheduledJobRunnerRun::STATUS_SUCCEEDED, true);
        } catch (\Throwable $e) {
            $this->logger->error('Scheduled-job runner failed', ['error' => $e->getMessage()]);

            return $this->finishRun($run, ScheduledJobRunnerRun::STATUS_FAILED, true, $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * Load the singleton settings row, creating defaults when missing.
     */
    public function getOrCreateSettings(): ScheduledJobRunnerSetting
    {
        $settings = $this->settingRepository->findSettings();
        if ($settings instanceof ScheduledJobRunnerSetting) {
            return $settings;
        }

        $settings = new ScheduledJobRunnerSetting();
        $settings->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return $settings;
    }

    public function getLatestRun(): ?ScheduledJobRunnerRun
    {
        return $this->runRepository->findLatestRun();
    }

    /**
     * Build the operational status payload for the admin runner endpoint.
     *
     * @return array<string, mixed>
     */
    public function buildStatusPayload(): array
    {
        $settings = $this->getOrCreateSettings();
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        $lastRun = $this->runRepository->findLatestRun();
        $lastFinished = $this->runRepository->findLatestFinishedRun();

        $nextEligibleRunAt = null;
        $lastFinishedAt = $lastFinished?->getFinishedAt();
        if ($lastFinishedAt !== null) {
            $nextEligibleRunAt = \DateTime::createFromInterface($lastFinishedAt)
                ->modify(sprintf('+%d seconds', $settings->getIntervalSeconds()))
                ->format(\DateTimeInterface::ATOM);
        }

        // Stale if the scheduler has not produced a run within ~3 intervals.
        $staleThresholdSeconds = max($settings->getIntervalSeconds() * 3, 300);
        $schedulerAppearsStale = $settings->isEnabled() && (
            $lastRun?->getStartedAt() === null
            || ($now->getTimestamp() - $lastRun->getStartedAt()->getTimestamp()) > $staleThresholdSeconds
        );

        $dueCount = $this->scheduledJobRepository->countDueQueuedJobs($now);

        return [
            'settings' => [
                'enabled' => $settings->isEnabled(),
                'interval_seconds' => $settings->getIntervalSeconds(),
                'max_jobs_per_run' => $settings->getMaxJobsPerRun(),
                'lock_ttl_seconds' => $settings->getLockTtlSeconds(),
                'stale_running_after_seconds' => $settings->getStaleRunningAfterSeconds(),
            ],
            'last_run' => $lastRun === null ? null : [
                'id' => $lastRun->getId(),
                'trigger_type' => $lastRun->getTriggerType(),
                'status' => $lastRun->getStatus(),
                'started_at' => $lastRun->getStartedAt()->format(\DateTimeInterface::ATOM),
                'finished_at' => $lastRun->getFinishedAt()?->format(\DateTimeInterface::ATOM),
                'duration_ms' => $lastRun->getDurationMs(),
                'due_count' => $lastRun->getDueCount(),
                'attempted_count' => $lastRun->getAttemptedCount(),
                'done_count' => $lastRun->getDoneCount(),
                'failed_count' => $lastRun->getFailedCount(),
                'skipped_count' => $lastRun->getSkippedCount(),
                'error_message' => $lastRun->getErrorMessage(),
            ],
            'queue' => [
                'due_queued_count' => $dueCount,
                'running_count' => $this->countRunningJobs(),
                'stale_running_count' => $this->countStaleRunningJobs($settings),
            ],
            'health' => [
                'next_eligible_run_at' => $nextEligibleRunAt,
                'scheduler_appears_stale' => $schedulerAppearsStale,
                'last_error_message' => $lastRun?->getErrorMessage(),
            ],
        ];
    }

    /**
     * Update runner settings from a validated request payload.
     *
     * @param array<string, mixed> $data
     *   Validated settings fields (enabled, interval_seconds, ...).
     */
    public function updateSettings(array $data, ?\App\Entity\User $updatedBy): ScheduledJobRunnerSetting
    {
        $settings = $this->getOrCreateSettings();

        if (array_key_exists('enabled', $data)) {
            $settings->setEnabled((bool) $data['enabled']);
        }
        if (array_key_exists('interval_seconds', $data) && is_numeric($data['interval_seconds'])) {
            // Scheduler mode requires a >= 60s interval.
            $settings->setIntervalSeconds(max(60, (int) $data['interval_seconds']));
        }
        if (array_key_exists('max_jobs_per_run', $data)) {
            $maxJobs = $data['max_jobs_per_run'];
            if ($maxJobs === null) {
                $settings->setMaxJobsPerRun(null);
            } elseif (is_numeric($maxJobs)) {
                $settings->setMaxJobsPerRun(max(1, (int) $maxJobs));
            }
        }
        if (array_key_exists('lock_ttl_seconds', $data) && is_numeric($data['lock_ttl_seconds'])) {
            $settings->setLockTtlSeconds(max(1, (int) $data['lock_ttl_seconds']));
        }
        if (array_key_exists('stale_running_after_seconds', $data) && is_numeric($data['stale_running_after_seconds'])) {
            $settings->setStaleRunningAfterSeconds(max(1, (int) $data['stale_running_after_seconds']));
        }

        $settings->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
        $settings->setUpdatedBy($updatedBy);
        $this->entityManager->flush();

        return $settings;
    }

    /**
     * Enable or disable the runner.
     */
    public function setEnabled(bool $enabled, ?\App\Entity\User $updatedBy): ScheduledJobRunnerSetting
    {
        return $this->updateSettings(['enabled' => $enabled], $updatedBy);
    }

    /**
     * Count due queued jobs at the current time.
     */
    public function countDueJobs(): int
    {
        return $this->scheduledJobRepository->countDueQueuedJobs(new \DateTime('now', new \DateTimeZone('UTC')));
    }

    public function countRunningJobs(): int
    {
        return $this->scheduledJobRepository->countRunningJobs();
    }

    /**
     * Count running jobs older than the configured stale threshold.
     */
    public function countStaleRunningJobs(ScheduledJobRunnerSetting $settings): int
    {
        $threshold = (new \DateTime('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d seconds', max(1, $settings->getStaleRunningAfterSeconds())));

        return count($this->scheduledJobRepository->findStaleRunningJobs($threshold));
    }

    private function intervalElapsed(ScheduledJobRunnerSetting $settings, \DateTimeInterface $now): bool
    {
        $last = $this->runRepository->findLatestFinishedRun();
        if ($last === null || $last->getFinishedAt() === null) {
            return true;
        }

        $elapsed = $now->getTimestamp() - $last->getFinishedAt()->getTimestamp();

        return $elapsed >= $settings->getIntervalSeconds();
    }

    private function resolveLimit(?int $limit, ScheduledJobRunnerSetting $settings): int
    {
        if ($limit !== null && $limit > 0) {
            return $limit;
        }

        $configured = $settings->getMaxJobsPerRun();

        return ($configured !== null && $configured > 0) ? $configured : 1000;
    }

    private function startRun(string $trigger, ScheduledJobRunnerSetting $settings, \DateTimeInterface $startedAt): ScheduledJobRunnerRun
    {
        $run = new ScheduledJobRunnerRun();
        $run->setTriggerType($trigger)
            ->setStatus(ScheduledJobRunnerRun::STATUS_RUNNING)
            ->setStartedAt($startedAt)
            ->setLockAcquired(true)
            ->setSettingsSnapshot($this->settingsSnapshot($settings));

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function finishRun(ScheduledJobRunnerRun $run, string $status, bool $lockAcquired, ?string $error = null): ScheduledJobRunResult
    {
        $finishedAt = new \DateTime('now', new \DateTimeZone('UTC'));
        $durationMs = (int) (($finishedAt->getTimestamp() - $run->getStartedAt()->getTimestamp()) * 1000);

        $run->setStatus($status)
            ->setFinishedAt($finishedAt)
            ->setDurationMs($durationMs)
            ->setLockAcquired($lockAcquired)
            ->setErrorMessage($error);

        $this->entityManager->flush();

        return new ScheduledJobRunResult(
            status: $status,
            dueCount: $run->getDueCount(),
            attemptedCount: $run->getAttemptedCount(),
            doneCount: $run->getDoneCount(),
            failedCount: $run->getFailedCount(),
            skippedCount: $run->getSkippedCount(),
            lockAcquired: $lockAcquired,
            errorMessage: $error,
            runId: $run->getId(),
        );
    }

    private function recordSkippedRun(string $trigger, string $status, ScheduledJobRunnerSetting $settings, \DateTimeInterface $now): ScheduledJobRunResult
    {
        $run = new ScheduledJobRunnerRun();
        $run->setTriggerType($trigger)
            ->setStatus($status)
            ->setStartedAt($now)
            ->setFinishedAt($now)
            ->setDurationMs(0)
            ->setLockAcquired($status !== ScheduledJobRunnerRun::STATUS_SKIPPED_LOCKED)
            ->setSettingsSnapshot($this->settingsSnapshot($settings));

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return new ScheduledJobRunResult(
            status: $status,
            lockAcquired: $run->isLockAcquired(),
            runId: $run->getId(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsSnapshot(ScheduledJobRunnerSetting $settings): array
    {
        return [
            'enabled' => $settings->isEnabled(),
            'interval_seconds' => $settings->getIntervalSeconds(),
            'max_jobs_per_run' => $settings->getMaxJobsPerRun(),
            'lock_ttl_seconds' => $settings->getLockTtlSeconds(),
            'stale_running_after_seconds' => $settings->getStaleRunningAfterSeconds(),
        ];
    }
}
