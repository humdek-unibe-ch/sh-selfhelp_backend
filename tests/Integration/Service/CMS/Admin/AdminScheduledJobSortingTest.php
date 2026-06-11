<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Service\CMS\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Service\CMS\Admin\AdminScheduledJobService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Regression for the admin scheduled-jobs list "sort by ID" bug: the sort
 * `match` in {@see AdminScheduledJobService::applyScheduledJobsSorting()} had no
 * `id` (or `date_executed`) case, so clicking the ID column fell through to the
 * default and ordered by `dateToBeExecuted` instead. This locks `sort=id` to a
 * true id ordering in both directions.
 */
final class AdminScheduledJobSortingTest extends QaKernelTestCase
{
    public function testListSortsByJobIdAscendingAndDescending(): void
    {
        $service = $this->service(AdminScheduledJobService::class);
        $factory = new ScheduledJobFactory($this->em);
        $user = $this->qaUser();

        // Unique token isolates these rows via the list `search` filter (and
        // keeps the per-(filters+sort) cache key unique across runs).
        $token = 'qa_sort_' . bin2hex(random_bytes(6));

        // Create in id order but with DESCENDING execution times, so a buggy
        // fallback to `dateToBeExecuted` produces the reverse of id order and
        // the assertions can tell the two apart.
        $base = new \DateTime('+10 days', new \DateTimeZone('UTC'));
        $first = $factory->create(LookupService::JOB_TYPES_EMAIL, LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $user, (clone $base)->modify('+3 hours'), $token);
        $second = $factory->create(LookupService::JOB_TYPES_EMAIL, LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $user, (clone $base)->modify('+2 hours'), $token);
        $third = $factory->create(LookupService::JOB_TYPES_EMAIL, LookupService::SCHEDULED_JOBS_STATUS_QUEUED, $user, (clone $base)->modify('+1 hour'), $token);

        $expectedAsc = [(int) $first->getId(), (int) $second->getId(), (int) $third->getId()];
        sort($expectedAsc);

        self::assertSame(
            $expectedAsc,
            $this->listedIds($service, $token, 'id', 'asc'),
            'sort=id asc must order rows by job id ascending, not by execution time.'
        );

        self::assertSame(
            array_reverse($expectedAsc),
            $this->listedIds($service, $token, 'id', 'desc'),
            'sort=id desc must order rows by job id descending.'
        );
    }

    /**
     * @return list<int>
     */
    private function listedIds(AdminScheduledJobService $service, string $search, string $sort, string $order): array
    {
        $result = $service->getScheduledJobs(['search' => $search], 1, 50, $sort, $order);

        $jobs = $result['scheduledJobs'];
        if (!is_array($jobs)) {
            self::fail('The list response must expose a scheduledJobs array.');
        }

        $ids = [];
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                self::fail('Each listed job must be an array.');
            }
            $id = $job['id'] ?? null;
            if (!is_int($id)) {
                self::fail('Each listed job must expose an int id.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
