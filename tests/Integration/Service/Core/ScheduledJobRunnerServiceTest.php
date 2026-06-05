<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\Core;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ScheduledJobRunnerRun;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Service\Core\ScheduledJobRunnerService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Operational coverage for the Docker scheduled-job runner orchestration
 * ({@see ScheduledJobRunnerService}, plan Slice B4).
 *
 * Asserts the gates the Docker tick relies on: a disabled runner skips, the
 * interval gate skips a too-soon tick, `--force` bypasses the interval, and a
 * dry run reports due work without executing it. Per-job execution itself is
 * covered by {@see JobSchedulerPreferenceTest}; DAMA rolls back settings/run
 * rows after each test.
 */
final class ScheduledJobRunnerServiceTest extends QaKernelTestCase
{
    private ScheduledJobRunnerService $runner;
    private ScheduledJobFactory $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = $this->service(ScheduledJobRunnerService::class);
        $this->jobs = new ScheduledJobFactory($this->em);
    }

    public function testDisabledRunnerSkipsWithoutExecuting(): void
    {
        $this->runner->setEnabled(false, null);
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_runner_disabled');

        $result = $this->runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER);

        self::assertSame(ScheduledJobRunnerRun::STATUS_SKIPPED_DISABLED, $result->status);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $job->getStatus()->getLookupCode(),
            'A disabled runner must not execute due jobs.'
        );
    }

    public function testIntervalNotElapsedSkips(): void
    {
        // A forced run finishes "now", so a default 60s interval has not elapsed.
        $this->runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER, null, true);
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_runner_interval');

        $result = $this->runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER);

        self::assertSame(ScheduledJobRunnerRun::STATUS_SKIPPED_INTERVAL, $result->status);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $job->getStatus()->getLookupCode(),
            'A tick within the interval window must not execute due jobs.'
        );
    }

    public function testForceBypassesIntervalAndExecutes(): void
    {
        $this->runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER, null, true);
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_runner_force');

        $result = $this->runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER, null, true);

        self::assertSame(ScheduledJobRunnerRun::STATUS_SUCCEEDED, $result->status);
        self::assertGreaterThanOrEqual(1, $result->doneCount, 'Force must execute the due job despite the interval.');
        $this->em->refresh($job);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_DONE, $job->getStatus()->getLookupCode());
    }

    public function testDryRunReportsDueWithoutExecuting(): void
    {
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_runner_dryrun');

        $result = $this->runner->runDueJobs(ScheduledJobRunnerRun::TRIGGER_SCHEDULER, null, true, true);

        self::assertSame(ScheduledJobRunnerRun::STATUS_SUCCEEDED, $result->status);
        self::assertGreaterThanOrEqual(1, $result->dueCount, 'A dry run must still count due jobs.');
        self::assertSame(0, $result->attemptedCount, 'A dry run must not attempt any job.');
        $this->em->refresh($job);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $job->getStatus()->getLookupCode());
    }

    /**
     * Regression: the admin "update settings" / "disable runner" endpoints pass
     * the security-context user as `updatedBy`. That user can be detached for the
     * service's EntityManager (loaded in a prior request / after an em clear), so
     * assigning it straight to {@see ScheduledJobRunnerSetting} made flush() raise
     * "A new entity was found through the relationship
     * ScheduledJobRunnerSetting#updatedBy ... not configured to cascade persist".
     * The fix associates a managed reference; both update and disable must work.
     */
    public function testUpdateAndDisableAcceptDetachedCurrentUser(): void
    {
        $user = $this->qaUser();
        // Reproduce the controller's detached current user.
        $this->em->detach($user);

        $updated = $this->runner->updateSettings(['enabled' => true, 'interval_seconds' => 90], $user);
        self::assertTrue($updated->isEnabled());
        self::assertSame(90, $updated->getIntervalSeconds());
        self::assertSame((int) $user->getId(), $updated->getUpdatedBy()?->getId());

        // The disable path delegates to updateSettings() with the same detached
        // user and must not throw either.
        $disabled = $this->runner->setEnabled(false, $user);
        self::assertFalse($disabled->isEnabled());
        self::assertSame((int) $user->getId(), $disabled->getUpdatedBy()?->getId());
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
