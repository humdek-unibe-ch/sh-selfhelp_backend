<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\Core;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Issue #29 delivery-preference enforcement inside the single per-job execution
 * path {@see JobSchedulerService::executeJob()} that every scheduler caller uses.
 *
 * A disabled communication preference is an intentional, audited skip — it ends
 * in a `skipped_*` status (never `failed`), records the execution time, and does
 * not call the mailer. `required_system` mail (account/security) bypasses the
 * email preference. A terminal job can never be executed twice (atomic claim).
 */
final class JobSchedulerPreferenceTest extends QaKernelTestCase
{
    private JobSchedulerService $jobScheduler;
    private ScheduledJobFactory $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobScheduler = $this->service(JobSchedulerService::class);
        $this->jobs = new ScheduledJobFactory($this->em);
    }

    public function testEmailJobIsSkippedWhenUserDisabledEmails(): void
    {
        $user = $this->userWithEmailPreference(false);
        $job = $this->jobs->createDueQueuedEmailJob($user, 'qa_pref_email_disabled');

        $executed = $this->jobScheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertNotFalse($executed);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS,
            $job->getStatus()->getLookupCode(),
            'An email job for a user who disabled emails must end in the skipped status, not failed.'
        );
        self::assertNotNull($job->getDateExecuted(), 'A skipped job still records a terminal execution time.');
    }

    public function testRequiredSystemEmailBypassesUserPreference(): void
    {
        $user = $this->userWithEmailPreference(false);
        $job = $this->jobs->create(
            LookupService::JOB_TYPES_EMAIL,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            new \DateTime('now', new \DateTimeZone('UTC')),
            'qa_pref_email_required_system',
            ['email' => [
                'delivery_policy' => LookupService::SCHEDULED_JOB_DELIVERY_POLICY_REQUIRED_SYSTEM,
                'recipient_emails' => (string) $user->getEmail(),
                'subject' => 'QA login code',
                'body' => 'QA required system mail',
                'is_html' => false,
            ]],
        );

        $executed = $this->jobScheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertNotFalse($executed);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_DONE,
            $job->getStatus()->getLookupCode(),
            'required_system mail must be delivered even when the user disabled emails.'
        );
    }

    public function testNotificationJobIsSkippedWhenUserDisabledNotifications(): void
    {
        $user = $this->userWithNotificationPreference(false);
        $job = $this->jobs->create(
            LookupService::JOB_TYPES_NOTIFICATION,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            new \DateTime('now', new \DateTimeZone('UTC')),
            'qa_pref_notification_disabled',
            ['notification' => [
                'subject' => 'QA push',
                'body' => 'QA push body',
            ]],
        );

        $executed = $this->jobScheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertNotFalse($executed);
        $this->em->refresh($job);
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_NOTIFICATIONS,
            $job->getStatus()->getLookupCode(),
            'A notification job for a user who disabled notifications must end skipped, never reaching Firebase.'
        );
        self::assertNotNull($job->getDateExecuted());
    }

    public function testExternalEmailWithoutLinkedUserIsSent(): void
    {
        // No job user and an external address that maps to no account: there is
        // no SelfHelp preference to apply, so it is delivered (null mailer).
        $job = $this->jobs->create(
            LookupService::JOB_TYPES_EMAIL,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            null,
            new \DateTime('now', new \DateTimeZone('UTC')),
            'qa_pref_external_email',
            ['email' => [
                'recipient_emails' => 'qa-external-mailbox@selfhelp.test',
                'subject' => 'QA external',
                'body' => 'QA external body',
                'is_html' => false,
            ]],
        );

        $executed = $this->jobScheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertNotFalse($executed);
        $this->em->refresh($job);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_DONE, $job->getStatus()->getLookupCode());
    }

    public function testTerminalJobCannotBeExecutedTwice(): void
    {
        $user = $this->userWithEmailPreference(true);
        $job = $this->jobs->createDueQueuedEmailJob($user, 'qa_pref_double_execute');

        $first = $this->jobScheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_SYSTEM);
        self::assertNotFalse($first);
        $this->em->refresh($job);
        self::assertSame(LookupService::SCHEDULED_JOBS_STATUS_DONE, $job->getStatus()->getLookupCode());

        // The job is no longer queued; the atomic claim must reject re-execution.
        $second = $this->jobScheduler->executeJob((int) $job->getId(), LookupService::TRANSACTION_BY_BY_SYSTEM);
        self::assertFalse($second, 'A done job must not be claimable for a second execution.');
    }

    private function userWithEmailPreference(bool $receivesEmails): User
    {
        $user = $this->qaUser();
        $user->setReceivesEmails($receivesEmails);
        $this->em->flush();

        return $user;
    }

    private function userWithNotificationPreference(bool $receivesNotifications): User
    {
        $user = $this->qaUser();
        $user->setReceivesNotifications($receivesNotifications);
        $this->em->flush();

        return $user;
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
