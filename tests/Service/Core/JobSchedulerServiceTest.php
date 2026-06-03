<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ScheduledJob;
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
