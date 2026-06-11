<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\Core;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Repository\ScheduledJobRepository;
use App\Service\Core\LookupService;
use App\Service\Core\QueuedJobTimezoneAdjustmentService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * DB-backed coverage for the timezone-change recalculation contract
 * ({@see QueuedJobTimezoneAdjustmentService}, plan §"recalculate ALL queued jobs
 * on timezone change + runner sends newly-due in execution order").
 *
 * Asserts the two behaviours the unit test cannot: (1) EVERY queued future job
 * is re-anchored (wall-clock and relative alike), and (2) when moving the
 * timezone forward pulls jobs into the past, the runner's due-job query returns
 * exactly the newly-due ones in execution order (`date_to_be_executed ASC,
 * id ASC`) while the still-future job is excluded. DAMA rolls the rows back.
 */
final class QueuedJobTimezoneRecalculationTest extends QaKernelTestCase
{
    public function testTimezoneChangeReanchorsAllQueuedJobsAndNewlyDueAreReturnedInOrder(): void
    {
        $service = $this->service(QueuedJobTimezoneAdjustmentService::class);
        $repository = $this->service(ScheduledJobRepository::class);
        $factory = new ScheduledJobFactory($this->em);
        $user = $this->qaUser();
        $userId = (int) $user->getId();

        $utc = new \DateTimeZone('UTC');
        $now = new \DateTime('now', $utc);

        // Three queued future jobs whose intended wall-clock time is in
        // Europe/Zurich. Asia/Tokyo runs 7-8h ahead of Zurich, so the same local
        // time maps to an earlier UTC instant: the two near-term jobs become due,
        // the +9h job stays in the future.
        $job1 = $this->zurichJob($factory, $user, (clone $now)->modify('+1 hour'), 'qa_tz_recalc_1');
        $job2 = $this->zurichJob($factory, $user, (clone $now)->modify('+2 hours'), 'qa_tz_recalc_2');
        $job3 = $this->zurichJob($factory, $user, (clone $now)->modify('+9 hours'), 'qa_tz_recalc_3');

        $before1 = $job1->getDateToBeExecuted()->getTimestamp();

        $adjusted = $service->adjustForUser($userId, 'Asia/Tokyo');

        self::assertSame(3, $adjusted, 'Every queued future job (wall-clock and relative) must be re-anchored.');

        foreach ([$job1, $job2, $job3] as $job) {
            $this->em->refresh($job);
            $schedule = $job->getConfig()['schedule'] ?? [];
            self::assertIsArray($schedule);
            self::assertSame('Asia/Tokyo', $schedule['timezone'] ?? null, 'Each job must record the new timezone.');
            self::assertSame('user', $schedule['timezone_source'] ?? null);
        }
        self::assertNotSame(
            $before1,
            $job1->getDateToBeExecuted()->getTimestamp(),
            'Re-anchoring must move the absolute execution instant.'
        );

        // The runner picks due jobs oldest-first; filter to our rows so unrelated
        // baseline jobs do not affect the assertion on relative order.
        $dueIds = array_map(
            static fn (ScheduledJob $j): int => (int) $j->getId(),
            $repository->findDueQueuedJobs(new \DateTime('now', $utc), 500)
        );
        $mineInDueOrder = array_values(array_filter(
            $dueIds,
            static fn (int $id): bool => in_array($id, [(int) $job1->getId(), (int) $job2->getId(), (int) $job3->getId()], true)
        ));

        self::assertSame(
            [(int) $job1->getId(), (int) $job2->getId()],
            $mineInDueOrder,
            'Newly-due jobs must be returned in execution order; the still-future job must be excluded.'
        );
    }

    private function zurichJob(
        ScheduledJobFactory $factory,
        User $user,
        \DateTimeInterface $dueAtUtc,
        string $description
    ): ScheduledJob {
        $zurich = new \DateTimeZone('Europe/Zurich');

        return $factory->create(
            LookupService::JOB_TYPES_EMAIL,
            LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            $user,
            $dueAtUtc,
            $description,
            [
                'email' => [
                    'recipient_emails' => (string) $user->getEmail(),
                    'subject' => 'QA timezone recalc',
                    'body' => 'QA timezone recalc body',
                ],
                'schedule' => [
                    'wall_clock' => true,
                    'timezone' => 'Europe/Zurich',
                    'timezone_source' => 'user',
                    'local_datetime' => \DateTime::createFromInterface($dueAtUtc)
                        ->setTimezone($zurich)
                        ->format('Y-m-d\TH:i:s'),
                ],
            ],
        );
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
