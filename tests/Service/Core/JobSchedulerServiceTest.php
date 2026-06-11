<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Lookup;
use App\Entity\ScheduledJob;
use App\Entity\Transaction;
use App\Entity\User;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for {@see JobSchedulerService} — the single per-job
 * execution entrypoint shared by the admin "run now" action, the by-id CLI
 * executor and the Docker due-runner (plan Phase 9: core services).
 *
 * Asserts the real scheduled-job status machine against the seeded lookups:
 * schedule → QUEUED, execute → DONE (with an execution timestamp), cancel →
 * CANCELLED but only from QUEUED (a finished job is refused), and delete →
 * soft DELETED. Persistence is verified by reloading the entity; DAMA rolls
 * every write back at tearDown.
 */
final class JobSchedulerServiceTest extends QaKernelTestCase
{
    private JobSchedulerService $scheduler;
    private ScheduledJobFactory $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduler = $this->service(JobSchedulerService::class);
        $this->jobs = new ScheduledJobFactory($this->em);
    }

    public function testScheduleDirectEmailJobPersistsAQueuedEmailJob(): void
    {
        $jobId = $this->scheduler->scheduleDirectEmailJob(
            [
                'subject' => 'qa_direct_email_subject',
                'from_email' => 'qa_from@selfhelp.test',
                'from_name' => 'QA',
                'recipient_emails' => ['qa_to@selfhelp.test'],
                'body' => 'qa direct email body',
                'is_html' => false,
                'attachments' => [],
            ],
            new \DateTime('now', new \DateTimeZone('UTC')),
            $this->qaUserId()
        );

        self::assertIsInt($jobId, 'Scheduling a direct email job must return the new job id.');

        $job = $this->em->getRepository(ScheduledJob::class)->find($jobId);
        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $job->getStatus()->getLookupCode(),
            'A freshly scheduled job must start QUEUED.'
        );
        self::assertSame(LookupService::JOB_TYPES_EMAIL, $job->getJobType()->getLookupCode());
        self::assertNull($job->getDateExecuted(), 'A queued job must not yet have an execution timestamp.');

        $config = $job->getConfig() ?? [];
        self::assertArrayHasKey('email', $config);
        self::assertSame('qa_direct_email_subject', $this->emailSubject($config));
    }

    public function testExecuteJobTransitionsQueuedJobToDone(): void
    {
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_scheduler_exec_job');

        $result = $this->scheduler->executeJob($this->jobId($job), LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $result, 'A processed job must return its entity, not false.');

        $this->em->refresh($job);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_DONE, $job->getStatus()->getLookupCode());
        self::assertNotNull($job->getDateExecuted(), 'An executed job must record an execution timestamp.');
    }

    /**
     * Issue #29: an email job whose resolved recipient is a known user that
     * disabled emails must end in the dedicated SKIPPED status (never `failed`)
     * with a terminal timestamp and a `send_mail_skipped` audit transaction.
     */
    public function testEmailJobForUserWithEmailsDisabledIsSkippedAndAudited(): void
    {
        $user = $this->qaUser();
        $user->setReceivesEmails(false);
        $this->em->flush();

        $job = $this->jobs->createDueQueuedEmailJob($user, 'qa_pref_skip_email_job');
        $jobId = $this->jobId($job);

        $result = $this->scheduler->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $result, 'A skipped job is terminal and must return its entity.');
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS,
            $job->getStatus()->getLookupCode(),
            'An email job for a user who disabled emails must end SKIPPED, not failed.'
        );
        self::assertNotNull($job->getDateExecuted(), 'A skipped job still records a terminal execution timestamp.');
        self::assertTrue(
            $this->jobTransactionExists($jobId, LookupService::TRANSACTION_TYPES_SEND_MAIL_SKIPPED),
            'The skip must be audited as a send_mail_skipped transaction.'
        );
    }

    /**
     * Issue #29: `required_system` mail (account/security) must bypass the user
     * email preference and still be delivered (status DONE).
     */
    public function testRequiredSystemEmailIsSentEvenWhenUserDisabledEmails(): void
    {
        $user = $this->qaUser();
        $user->setReceivesEmails(false);
        $this->em->flush();

        $job = $this->jobs->create(
            LookupService::JOB_TYPES_EMAIL,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            new \DateTime('now', new \DateTimeZone('UTC')),
            'qa_pref_required_system_job',
            ['email' => [
                'recipient_emails' => (string) $user->getEmail(),
                'subject' => 'QA required system email',
                'body' => 'QA required system body',
                'from_email' => 'qa-noreply@selfhelp.test',
                'from_name' => 'QA',
                'is_html' => false,
                'delivery_policy' => LookupService::SCHEDULED_JOB_DELIVERY_POLICY_REQUIRED_SYSTEM,
            ]],
        );

        $result = $this->scheduler->executeJob($this->jobId($job), LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $result);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            'required_system mail must ignore the user email preference and be delivered.'
        );
    }

    /**
     * Issue #29: an external recipient with no linked SelfHelp user has no
     * stored preference, so the mail is delivered (status DONE).
     */
    public function testExternalEmailWithNoLinkedUserIsSent(): void
    {
        $job = $this->jobs->create(
            LookupService::JOB_TYPES_EMAIL,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            null,
            new \DateTime('now', new \DateTimeZone('UTC')),
            'qa_pref_external_email_job',
            ['email' => [
                'recipient_emails' => 'qa_external_mailbox@selfhelp.test',
                'subject' => 'QA external email',
                'body' => 'QA external body',
                'from_email' => 'qa-noreply@selfhelp.test',
                'from_name' => 'QA',
                'is_html' => false,
            ]],
        );

        $result = $this->scheduler->executeJob($this->jobId($job), LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $result);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            'An external recipient with no SelfHelp user has no stored preference and must be delivered.'
        );
    }

    /**
     * Issue #29: a notification job for a known user that disabled notifications
     * must end SKIPPED (push has no required_system escape hatch) and be audited
     * as send_notification_skipped, without ever reaching the Firebase path.
     */
    public function testNotificationJobForUserWithNotificationsDisabledIsSkippedAndAudited(): void
    {
        $user = $this->qaUser();
        $user->setReceivesNotifications(false);
        $this->em->flush();

        $job = $this->jobs->create(
            LookupService::JOB_TYPES_NOTIFICATION,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            new \DateTime('now', new \DateTimeZone('UTC')),
            'qa_pref_skip_notification_job',
            ['notification' => [
                'subject' => 'QA notification',
                'body' => 'QA notification body',
            ]],
        );
        $jobId = $this->jobId($job);

        $result = $this->scheduler->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertInstanceOf(ScheduledJob::class, $result);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_NOTIFICATIONS,
            $job->getStatus()->getLookupCode(),
            'A notification job for a user who disabled notifications must end SKIPPED.'
        );
        self::assertNotNull($job->getDateExecuted());
        self::assertTrue(
            $this->jobTransactionExists($jobId, LookupService::TRANSACTION_TYPES_SEND_NOTIFICATION_SKIPPED),
            'The skip must be audited as a send_notification_skipped transaction.'
        );
    }

    /**
     * Slice B1 race guard: once a job reaches a terminal (here: skipped) status
     * the atomic queued->running claim is lost on any further attempt, so a
     * second execution is refused and leaves the status untouched.
     */
    public function testATerminalSkippedJobCannotBeExecutedAgain(): void
    {
        $user = $this->qaUser();
        $user->setReceivesEmails(false);
        $this->em->flush();

        $job = $this->jobs->createDueQueuedEmailJob($user, 'qa_pref_no_reexec_job');
        $jobId = $this->jobId($job);

        $this->scheduler->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS,
            $job->getStatus()->getLookupCode()
        );

        $second = $this->scheduler->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertFalse($second, 'A terminal (skipped) job must not be executable again.');
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS,
            $job->getStatus()->getLookupCode(),
            'A refused re-execution must leave the skipped status untouched.'
        );
    }

    public function testCancelJobTransitionsQueuedJobToCancelled(): void
    {
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_scheduler_cancel_job');

        $cancelled = $this->scheduler->cancelJob($this->jobId($job), LookupService::TRANSACTION_BY_BY_USER);

        self::assertTrue($cancelled, 'A queued job must be cancellable.');

        $this->em->refresh($job);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_CANCELLED, $job->getStatus()->getLookupCode());
        self::assertNull($job->getDateExecuted(), 'A cancelled job was never executed.');
    }

    public function testCancelJobRefusesAnAlreadyExecutedJob(): void
    {
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_scheduler_cancel_done_job');
        $jobId = $this->jobId($job);
        $this->scheduler->executeJob($jobId, LookupService::TRANSACTION_BY_BY_SYSTEM);

        $cancelled = $this->scheduler->cancelJob($jobId, LookupService::TRANSACTION_BY_BY_USER);

        self::assertFalse($cancelled, 'A finished (DONE) job must not be cancellable.');

        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            'A refused cancel must leave the DONE status untouched.'
        );
    }

    public function testDeleteJobSoftDeletesTheJob(): void
    {
        $job = $this->jobs->createDueQueuedEmailJob($this->qaUser(), 'qa_scheduler_delete_job');

        $deleted = $this->scheduler->deleteJob($this->jobId($job), LookupService::TRANSACTION_BY_BY_USER);

        self::assertTrue($deleted, 'Deleting a job must report success.');

        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DELETED,
            $job->getStatus()->getLookupCode(),
            'deleteJob is a soft delete: the row stays but is marked DELETED.'
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function emailSubject(array $config): ?string
    {
        $email = $config['email'] ?? null;
        if (!is_array($email)) {
            return null;
        }

        $subject = $email['subject'] ?? null;

        return is_string($subject) ? $subject : null;
    }

    /**
     * Assert (via the audit trail) that a scheduled job logged a transaction of
     * the given transaction-type lookup code.
     */
    private function jobTransactionExists(int $jobId, string $transactionTypeCode): bool
    {
        $type = $this->em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::TRANSACTION_TYPES,
            'lookupCode' => $transactionTypeCode,
        ]);
        self::assertInstanceOf(Lookup::class, $type, 'Transaction type lookup must be seeded. Run: composer test:reset-db');

        $transaction = $this->em->getRepository(Transaction::class)->findOneBy([
            'tableName' => 'scheduled_jobs',
            'idTableName' => $jobId,
            'idTransactionTypes' => $type->getId(),
        ]);

        return $transaction instanceof Transaction;
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }

    private function qaUserId(): int
    {
        $id = $this->qaUser()->getId();
        self::assertIsInt($id);

        return $id;
    }

    private function jobId(ScheduledJob $job): int
    {
        $id = $job->getId();
        self::assertIsInt($id);

        return $id;
    }
}
