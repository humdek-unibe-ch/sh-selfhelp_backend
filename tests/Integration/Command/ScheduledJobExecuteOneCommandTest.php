<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\Notifier\RecordingNotifier;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration coverage for {@see \App\Command\ScheduledJobExecuteOneCommand}
 * (plan Phase 9: command tests). Asserts the by-id execution entrypoint — the
 * same {@see \App\Service\Core\JobSchedulerService::executeJob()} the immediate
 * and cron executors use — its exit-code contract, output, DB side effect and
 * absence of real outbound (null mailer transport).
 */
final class ScheduledJobExecuteOneCommandTest extends QaKernelTestCase
{
    private const COMMAND = 'app:scheduled-jobs:execute-one';

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = new Application(self::bootedKernel());
        $this->application->setAutoExit(false);
    }

    public function testExecutesQueuedEmailJobAndReportsDone(): void
    {
        $job = (new ScheduledJobFactory($this->em))->createDueQueuedEmailJob($this->qaUser(), 'qa_execute_one_job');
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $job->getStatus()->getLookupCode());

        $tester = $this->runCommand((string) $job->getId());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('executed with status done', $tester->getDisplay());

        // DB side effect: the queued job is now done.
        $this->em->refresh($job);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_DONE, $job->getStatus()->getLookupCode());
        self::assertNotNull($job->getDateExecuted());

        // No real outbound: the configured mailer transport is null.
        $mailerDsn = $_SERVER['MAILER_DSN'] ?? null;
        RecordingNotifier::assertMailerIsNullTransport(is_string($mailerDsn) ? $mailerDsn : null);
    }

    public function testUnknownJobIdReportsFailure(): void
    {
        $tester = $this->runCommand('999999999');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('failed to execute', $tester->getDisplay());
    }

    public function testNonNumericJobIdReportsFailure(): void
    {
        // The command coerces a non-numeric id to 0, which never resolves.
        $tester = $this->runCommand('not-a-number');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    private function runCommand(string $jobId): CommandTester
    {
        $tester = new CommandTester($this->application->find(self::COMMAND));
        $tester->execute(['jobId' => $jobId], ['interactive' => false]);

        return $tester;
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
