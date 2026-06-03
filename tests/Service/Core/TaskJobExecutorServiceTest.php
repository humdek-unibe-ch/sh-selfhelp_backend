<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Core;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Entity\UsersGroup;
use App\Service\Core\LookupService;
use App\Service\Core\TaskJobExecutorService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Behavioural coverage for {@see TaskJobExecutorService} — the executor for
 * group add/remove scheduled task jobs (plan Phase 8: task job success/failure).
 *
 * The email path is covered by FormActionJobChainTest; this targets the task
 * path's public side effect: a user-group membership change + audit + acl bump.
 */
final class TaskJobExecutorServiceTest extends QaKernelTestCase
{
    private TaskJobExecutorService $executor;
    private ScheduledJobFactory $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = $this->service(TaskJobExecutorService::class);
        $this->jobs = new ScheduledJobFactory($this->em);
    }

    public function testAddGroupTaskCreatesMembershipAndBumpsAclVersion(): void
    {
        $guest = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);
        $subject = $this->subjectGroup();
        self::assertNull($this->membership($guest, $subject), 'qa.guest must not start in the subject group.');

        // acl_version is an opaque random token (the FE BFF only compares for
        // change), so assert it CHANGED rather than ordering it numerically.
        $aclBefore = $guest->getAclVersion();
        $job = $this->taskJob($guest, 'add_group', ['subject']);

        $result = $this->executor->execute($job, LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertTrue($result, 'add_group on a non-member must succeed.');
        self::assertNotNull($this->membership($guest, $subject), 'add_group must create the membership.');
        self::assertNotSame($aclBefore, $guest->getAclVersion(), 'A membership change must bump the acl version.');
        self::assertSame(1, $this->taskTransactionCount(LookupService::TRANSACTION_TYPES_EXECUTE_TASK_OK, (int) $job->getId()));
    }

    public function testRemoveGroupTaskDropsMembership(): void
    {
        $member = $this->user(QaBaselineFixture::QA_USER_EMAIL);
        $subject = $this->subjectGroup();
        self::assertNotNull($this->membership($member, $subject), 'qa.user must start in the subject group.');

        $job = $this->taskJob($member, 'remove_group', ['subject']);

        $result = $this->executor->execute($job, LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertTrue($result, 'remove_group on a member must succeed.');
        self::assertNull($this->membership($member, $subject), 'remove_group must delete the membership.');
    }

    public function testUnresolvableGroupFailsAndLogsFailure(): void
    {
        $guest = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);
        $job = $this->taskJob($guest, 'add_group', ['qa_nonexistent_group']);

        $result = $this->executor->execute($job, LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertFalse($result, 'A task referencing an unknown group must fail.');
        self::assertSame(1, $this->taskTransactionCount(LookupService::TRANSACTION_TYPES_EXECUTE_TASK_FAIL, (int) $job->getId()));
    }

    public function testInvalidTaskTypeFails(): void
    {
        $guest = $this->user(QaBaselineFixture::QA_GUEST_EMAIL);
        $job = $this->taskJob($guest, 'qa_bogus_task_type', ['subject']);

        self::assertFalse(
            $this->executor->execute($job, LookupService::TRANSACTION_BY_BY_SYSTEM),
            'An unknown task type must fail.',
        );
    }

    /**
     * @param list<string> $groups
     */
    private function taskJob(User $user, string $taskType, array $groups): ScheduledJob
    {
        return $this->jobs->create(
            LookupService::JOB_TYPES_TASK,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            new \DateTime('now', new \DateTimeZone('UTC')),
            'qa_task_job',
            ['task' => ['task_type' => $taskType, 'groups' => $groups]],
        );
    }

    private function membership(User $user, Group $group): ?UsersGroup
    {
        return $this->em->getRepository(UsersGroup::class)->findOneBy([
            'user' => $user->getId(),
            'group' => $group->getId(),
        ]);
    }

    private function taskTransactionCount(string $transactionCode, int $jobId): int
    {
        return $this->coerceInt($this->em->getConnection()->fetchOne(
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

    private function subjectGroup(): Group
    {
        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'subject']);
        self::assertInstanceOf(Group::class, $group, 'The seeded "subject" group must exist.');

        return $group;
    }

    private function user(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, "{$email} must be seeded. Run: composer test:reset-db");

        return $user;
    }
}
