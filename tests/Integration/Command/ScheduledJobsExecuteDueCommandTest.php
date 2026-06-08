<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\Notifier\RecordingNotifier;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration coverage for {@see \App\Command\ScheduledJobsExecuteDueCommand}
 * — the Docker scheduler / cron entrypoint (plan Phase 11: command tests).
 *
 * Asserts the bulk due-executor contract through the real
 * {@see \App\Service\Core\JobSchedulerService::executeJob()} path the immediate
 * and by-id executors also use: a due queued job runs to `done`, a future-dated
 * job is left untouched, a domain failure flips the exit code, and `--limit`
 * caps how many due jobs run in one tick. No real outbound (null mailer).
 *
 * The scenarios assert per-job status (not global counts) so the seeded baseline
 * — which contains no due jobs — cannot make them brittle; DAMA rolls every job
 * back at tearDown.
 */
final class ScheduledJobsExecuteDueCommandTest extends QaKernelTestCase
{
    private const COMMAND = 'app:scheduled-jobs:execute-due';

    private Application $application;
    private ScheduledJobFactory $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = new Application(self::bootedKernel());
        $this->application->setAutoExit(false);
        $this->jobs = new ScheduledJobFactory($this->em);
    }

    public function testExecutesDueQueuedJobAndReportsSuccess(): void
    {
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_due_cmd_job');
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $job->getStatus()->getLookupCode());

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Execute Due Scheduled Jobs', $tester->getDisplay());

        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            'A due queued job must be executed by execute-due.'
        );
        self::assertNotNull($job->getDateExecuted(), 'Executed job must record an execution time.');

        $mailerDsn = $_SERVER['MAILER_DSN'] ?? null;
        RecordingNotifier::assertMailerIsNullTransport(is_string($mailerDsn) ? $mailerDsn : null);
    }

    public function testFutureDatedJobIsNotPickedUp(): void
    {
        $future = new \DateTime('+1 hour', new \DateTimeZone('UTC'));
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_future_cmd_job', $future);

        $this->runCommand();

        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $job->getStatus()->getLookupCode(),
            'A job scheduled in the future must NOT be executed yet.'
        );
        self::assertNull($job->getDateExecuted(), 'A not-yet-due job must not record an execution time.');
    }

    public function testDomainFailureIsMarkedFailedWithoutCrashingTheBatch(): void
    {
        // A job whose handler returns false is "processed but failed": the
        // executor records FAILED and returns the entity (truthy), so the batch
        // command still exits SUCCESS — it only fails on the exception path
        // (job-not-found / unhandled throwable). This is the same contract
        // FormActionJobChainTest relies on (a failed job is not auto-retried).
        $job = $this->jobs->createFailingEmailJob($this->qaUser(), 'qa_failing_cmd_job');

        $tester = $this->runCommand();

        self::assertSame(
            Command::SUCCESS,
            $tester->getStatusCode(),
            'A processed-but-failed job must not crash the batch; only the exception path fails the command.'
        );

        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_FAILED,
            $job->getStatus()->getLookupCode(),
            'A job whose handler reports failure must end up FAILED.'
        );
    }

    public function testLimitOptionCapsHowManyDueJobsRun(): void
    {
        $first = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_limit_cmd_job_1');
        $second = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_limit_cmd_job_2');

        // Only one of the two due jobs may run this tick.
        $tester = $this->runCommand(['--limit' => '1']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $this->em->refresh($first);
        $this->em->refresh($second);
        $statuses = [
            $first->getStatus()->getLookupCode(),
            $second->getStatus()->getLookupCode(),
        ];

        self::assertSame(
            1,
            count(array_filter($statuses, static fn (?string $s): bool => $s === LookupService::SCHEDULED_JOBS_STATUS_DONE)),
            'Exactly one of the two due jobs must run under --limit 1.'
        );
        self::assertSame(
            1,
            count(array_filter($statuses, static fn (?string $s): bool => $s === LookupService::SCHEDULED_JOBS_STATUS_QUEUED)),
            'The capped job must remain queued for the next tick.'
        );
    }

    public function testDryRunReportsDueWithoutExecuting(): void
    {
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_dryrun_cmd_job');

        $tester = $this->runCommand(['--dry-run' => true, '--force' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $job->getStatus()->getLookupCode(),
            'A dry run must not execute due jobs.'
        );
        self::assertNull($job->getDateExecuted(), 'A dry-run job must not record an execution time.');
    }

    public function testJsonOptionEmitsMachineReadableSummary(): void
    {
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_json_cmd_job');

        $tester = $this->runCommand(['--json' => true, '--force' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $decoded = json_decode(trim($tester->getDisplay()), true);
        self::assertIsArray($decoded, 'The --json output must be valid JSON: ' . $tester->getDisplay());
        foreach (['status', 'due_count', 'attempted_count', 'done_count', 'failed_count', 'skipped_count', 'lock_acquired'] as $key) {
            self::assertArrayHasKey($key, $decoded, "JSON summary must expose '{$key}'.");
        }

        // The job actually ran (not a dry run), so it must be done.
        $this->em->refresh($job);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_DONE, $job->getStatus()->getLookupCode());
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function runCommand(array $options = []): CommandTester
    {
        $tester = new CommandTester($this->application->find(self::COMMAND));
        $tester->execute($options, ['interactive' => false]);

        return $tester;
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
