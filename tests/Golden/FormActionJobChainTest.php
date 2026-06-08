<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\CMS\DataService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\MercureTestRecorder;
use App\Tests\Support\Notifier\RecordingNotifier;
use App\Tests\Support\PerfBudget;
use App\Tests\Support\QaCleanupVerifier;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Timing;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Reference golden workflow for the SelfHelp backend: a form submission that
 * triggers an action, which schedules a job, which executes and produces a
 * domain-visible side effect — all without any real outbound traffic.
 *
 * This is the canonical pattern future workflow tests copy (plan §6.2 / §16).
 * It uses the REAL services end to end (no domain mocking, plan §1 wording):
 *
 *   DataService::saveData()  (the exact method FormController::submitForm calls)
 *     -> ActionOrchestratorService::handle()  (resolves the "finished" action)
 *       -> ActionSchedulerService::schedule()  (creates the ScheduledJob)
 *         -> ActionImmediateExecutorService::executeDueNow()  (runs due jobs)
 *           -> JobSchedulerService::executeJob()  (email via null transport)
 *
 * Assertions follow "public/domain effects first" (plan §5): the saved record
 * id, the scheduled job visible through the ORM with status=done, the
 * `send_mail_ok` audit transaction, and the captured (never delivered) email.
 *
 * Honest scope note: this chain publishes NO Mercure event (the backend only
 * publishes realtime updates for ACL/plugin changes today — verified, not
 * assumed), so the test asserts zero realtime publishes via the recorder rather
 * than a non-existent "scheduled-job.executed" event. When a future change adds
 * such an event, assert it here with $this->mercure->assertTopicPublished(...).
 */
#[Group('golden')]
final class FormActionJobChainTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private DataService $dataService;
    private JobSchedulerService $jobScheduler;
    private MercureTestRecorder $mercure;
    private QaCleanupVerifier $cleanup;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $this->em->getConnection();

        $dataService = $container->get(DataService::class);
        self::assertInstanceOf(DataService::class, $dataService);
        $this->dataService = $dataService;

        $jobScheduler = $container->get(JobSchedulerService::class);
        self::assertInstanceOf(JobSchedulerService::class, $jobScheduler);
        $this->jobScheduler = $jobScheduler;

        $mercure = $container->get(MercureTestRecorder::class);
        self::assertInstanceOf(MercureTestRecorder::class, $mercure);
        $this->mercure = $mercure;

        $this->cleanup = new QaCleanupVerifier($this->connection);
        $this->cleanup->capture();
    }

    public function testFinishedFormSubmissionSchedulesAndExecutesActionEmailJob(): void
    {
        $qaUser = $this->qaUser();
        $qaUser->setReceivesEmails(true);
        $this->em->flush();
        $qaUserId = (int) $qaUser->getId();

        // A "finished"-trigger action on a qa data table that emails the
        // submitting user immediately. Built through the real action config
        // contract so the runtime resolves it exactly like an admin action.
        $action = (new ActionFactory($this->em))
            ->createImmediateEmailAction('qa_form_action', 'QA welcome', 'Welcome to QA.');
        $tableName = (string) $action->getDataTable()?->getName();

        // Act: submit form data exactly as FormController::submitForm does.
        $start = microtime(true);
        $recordId = $this->dataService->saveData(
            $tableName,
            ['id_users' => $qaUserId, 'qa_answer' => 'qa value'],
            LookupService::TRANSACTION_BY_BY_USER,
        );
        $elapsedMs = (microtime(true) - $start) * 1000;

        // Public effect: a record was created.
        self::assertIsInt($recordId);
        self::assertGreaterThan(0, $recordId, 'Form submission must return a record id.');

        // Domain effect: exactly one scheduled job for the action, executed.
        $jobs = $this->em->getRepository(ScheduledJob::class)->findBy(['action' => $action]);
        self::assertCount(1, $jobs, 'The finished trigger must schedule exactly one job.');
        $job = $jobs[0];
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            'An immediate job must be executed during submission (status=done).'
        );
        self::assertSame($qaUserId, (int) $job->getUser()?->getId(), 'Job recipient must be the submitting user.');
        self::assertNotNull($job->getDateExecuted(), 'Executed job must record an execution time.');

        // Domain effect: the email send was recorded in the audit trail.
        self::assertSame(
            1,
            $this->sendMailOkCount((int) $job->getId()),
            'Executing the email job must log a send_mail_ok transaction.'
        );

        // No real outbound: the email was captured but the transport is null.
        $mailerDsn = $_SERVER['MAILER_DSN'] ?? null;
        RecordingNotifier::assertMailerIsNullTransport(is_string($mailerDsn) ? $mailerDsn : null);
        $notifier = RecordingNotifier::fromMailerMessages(self::getMailerMessages());
        $notifier->assertEmailSentTo(QaBaselineFixture::QA_USER_EMAIL, 'QA welcome');

        // No realtime side effect in this chain (verified behaviour, not assumed).
        $this->cleanup->assertNoRealOutbound($this->mercure, expectedMercurePublishes: 0);

        // Cleanup proof: every row created is qa-prefixed (DAMA rolls it back).
        $this->cleanup->verifyNoNonQaLeaks();

        // Performance budget (plan §28): the whole chain is fast.
        self::assertLessThan(
            Timing::BUDGET_GOLDEN_CHAIN_MS,
            $elapsedMs,
            sprintf('Form-action-job chain took %.0f ms, budget %d ms.', $elapsedMs, Timing::BUDGET_GOLDEN_CHAIN_MS)
        );
    }

    public function testExecuteDueCommandExecutesQueuedActionJob(): void
    {
        $qaUser = $this->qaUser();

        // A queued, due email job (the state a future-scheduled action job is in
        // when the cron picks it up).
        $job = (new ScheduledJobFactory($this->em))
            ->createDueQueuedEmailJob($qaUser, 'qa_due_email_job');
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $job->getStatus()->getLookupCode(),
            'Job must start queued.'
        );

        // Act: run the real cron command in-process against the booted kernel.
        $application = new Application($this->client->getKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('app:scheduled-jobs:execute-due'));
        $executeStartedAt = microtime(true);
        $exitCode = $tester->execute(['--limit' => '999']);
        $executeMs = (microtime(true) - $executeStartedAt) * 1000;

        self::assertSame(0, $exitCode, 'execute-due must exit 0: ' . $tester->getDisplay());

        // Performance budget (plan §28): executing a due job is fast.
        PerfBudget::assertWithinBudget($executeMs, PerfBudget::SCHEDULED_JOB_EXECUTE_MS, 'scheduled job execute (execute-due)');

        // Domain effect: the queued job is now done.
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            'execute-due must execute the due queued job (status=done).'
        );

        // No real outbound; cleanup proof.
        $mailerDsn = $_SERVER['MAILER_DSN'] ?? null;
        RecordingNotifier::assertMailerIsNullTransport(is_string($mailerDsn) ? $mailerDsn : null);
        $this->cleanup->assertNoRealOutbound($this->mercure, expectedMercurePublishes: 0);
        $this->cleanup->verifyNoNonQaLeaks();
    }

    public function testScheduledEmailJobWithNoRecipientsFailsAndLogsSendMailFailWithoutOutbound(): void
    {
        $job = (new ScheduledJobFactory($this->em))
            ->createFailingEmailJob($this->qaUser(), 'qa_failing_email_job');
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $job->getStatus()->getLookupCode(),
            'Job must start queued.'
        );

        // Act: execute the job directly (same entrypoint the immediate executor uses).
        $this->jobScheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_SYSTEM);

        // Domain effect: the job is marked failed (no recipients could be resolved).
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_FAILED,
            $job->getStatus()->getLookupCode(),
            'A job with no resolvable recipients must end up failed.'
        );

        // Audit: exactly one send_mail_fail and zero send_mail_ok for this job.
        self::assertSame(1, $this->mailTransactionCount(LookupService::TRANSACTION_TYPES_SEND_MAIL_FAIL, (int) $job->getId()));
        self::assertSame(0, $this->sendMailOkCount((int) $job->getId()));

        // No real outbound: no email captured, no realtime publish.
        RecordingNotifier::fromMailerMessages(self::getMailerMessages())->assertNoEmails();
        $this->cleanup->assertNoRealOutbound($this->mercure, expectedMercurePublishes: 0);
        $this->cleanup->verifyNoNonQaLeaks();
    }

    public function testFailedScheduledJobIsNotAutomaticallyRetriedByExecuteDue(): void
    {
        $job = (new ScheduledJobFactory($this->em))
            ->createFailingEmailJob($this->qaUser(), 'qa_failing_retry_job');

        $application = new Application($this->client->getKernel());
        $application->setAutoExit(false);

        // First run: the due queued job is executed and fails.
        $tester = new CommandTester($application->find('app:scheduled-jobs:execute-due'));
        self::assertSame(0, $tester->execute(['--limit' => '999']), $tester->getDisplay());

        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_FAILED,
            $job->getStatus()->getLookupCode(),
            'execute-due must execute the due queued job, which fails.'
        );
        self::assertSame(1, $this->mailTransactionCount(LookupService::TRANSACTION_TYPES_SEND_MAIL_FAIL, (int) $job->getId()));

        // Second run: a failed job is no longer queued, so it must NOT be retried.
        // (The current backend has no auto-retry — this asserts that real behaviour.)
        $retryTester = new CommandTester($application->find('app:scheduled-jobs:execute-due'));
        self::assertSame(0, $retryTester->execute(['--limit' => '999']), $retryTester->getDisplay());

        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_FAILED,
            $job->getStatus()->getLookupCode(),
            'A failed job must stay failed — execute-due does not retry it.'
        );
        self::assertSame(
            1,
            $this->mailTransactionCount(LookupService::TRANSACTION_TYPES_SEND_MAIL_FAIL, (int) $job->getId()),
            'No second attempt: there is no retry mechanism, so no second send_mail_fail.'
        );

        $this->cleanup->assertNoRealOutbound($this->mercure, expectedMercurePublishes: 0);
        $this->cleanup->verifyNoNonQaLeaks();
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }

    private function qaUserId(): int
    {
        return (int) $this->qaUser()->getId();
    }

    private function sendMailOkCount(int $jobId): int
    {
        return $this->mailTransactionCount(LookupService::TRANSACTION_TYPES_SEND_MAIL_OK, $jobId);
    }

    /**
     * Count audit transactions of a given type recorded against a scheduled job.
     */
    private function mailTransactionCount(string $transactionCode, int $jobId): int
    {
        return $this->coerceInt($this->connection->fetchOne(
            'SELECT COUNT(*) FROM transactions t '
            . 'JOIN lookups l ON l.id = t.id_transaction_types '
            . 'WHERE l.type_code = :type AND l.lookup_code = :code '
            . "AND t.table_name = 'scheduled_jobs' AND t.id_table_name = :jobId",
            [
                'type' => LookupService::TRANSACTION_TYPES,
                'code' => $transactionCode,
                'jobId' => $jobId,
            ],
        ));
    }
}
