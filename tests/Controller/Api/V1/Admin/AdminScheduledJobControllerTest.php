<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\ScheduledJobFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FUNCTIONAL / response-contract tests for the admin scheduled-jobs API.
 *
 * Scope split (Phase 7 de-duplication): this class certifies the success-path
 * response SHAPE of each endpoint against a self-seeded `qa_` job, so it never
 * reads or mutates real business rows (canonical Testing Rule 9) and never
 * skips for lack of data. The admin-only permission matrix (admin 200 /
 * editor|user|guest 403 / anonymous 401) lives in
 * {@see AdminScheduledJobPermissionTest} and is deliberately NOT repeated here
 * — the previous `testUnauthorizedAccess` was folded into that matrix.
 *
 * The earlier version of this test executed/deleted the FIRST real job in the
 * database; that violated Rule 9 and is replaced by execute/delete against the
 * seeded `qa_` job (DAMA rolls the transaction back afterwards).
 */
final class AdminScheduledJobControllerTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private string $adminToken;
    private ScheduledJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->service(EntityManagerInterface::class);
        $this->adminToken = $this->loginAsQaAdmin();
        $this->job = (new ScheduledJobFactory($this->em))
            ->createDueQueuedEmailJob($this->qaUser(), 'qa_admin_scheduled_job');
    }

    public function testListWithoutPaginationReturnsAllJobsEnvelope(): void
    {
        // No page param => the controller's "calendar" branch
        // (AdminScheduledJobService::getAllScheduledJobs): jobs + totalCount only.
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/scheduled-jobs', null, $this->adminToken);
        $data = $this->assertEnvelopeSuccess($envelope);

        foreach (['scheduledJobs', 'totalCount'] as $key) {
            self::assertArrayHasKey($key, $data, "All-jobs list payload must expose '$key'.");
        }
        self::assertIsArray($data['scheduledJobs']);
    }

    public function testPaginatedListReturnsFullPaginationEnvelope(): void
    {
        // With a page param (and filters) the controller uses the paginated
        // branch (AdminScheduledJobService::getScheduledJobs) which adds the
        // page/pageSize/totalPages keys the admin table consumes.
        $envelope = $this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/scheduled-jobs?page=1&pageSize=10&search=qa&status=Queued&dateType=date_to_be_executed',
            null,
            $this->adminToken,
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        foreach (['scheduledJobs', 'totalCount', 'page', 'pageSize', 'totalPages'] as $key) {
            self::assertArrayHasKey($key, $data, "Paginated list payload must expose '$key'.");
        }
        self::assertSame(1, $data['page']);
        self::assertSame(10, $data['pageSize']);
    }

    public function testGetByIdReturnsTheJob(): void
    {
        $id = (int) $this->job->getId();
        $envelope = $this->jsonRequest('GET', "/cms-api/v1/admin/scheduled-jobs/{$id}", null, $this->adminToken);
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertSame($id, $data['id'] ?? null, 'Detail payload must echo the requested job id.');
    }

    public function testGetByIdNotFound(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/scheduled-jobs/2147483600', null, $this->adminToken);

        $this->assertEnvelope404($envelope);
    }

    public function testGetJobTransactionsReturnsAList(): void
    {
        $id = (int) $this->job->getId();
        $envelope = $this->jsonRequest('GET', "/cms-api/v1/admin/scheduled-jobs/{$id}/transactions", null, $this->adminToken);
        $this->assertEnvelopeSuccess($envelope);

        self::assertIsArray($envelope['data'], 'Transactions payload must be a list.');
    }

    public function testExecuteRunsTheQueuedJob(): void
    {
        $id = (int) $this->job->getId();
        $envelope = $this->jsonRequest('POST', "/cms-api/v1/admin/scheduled-jobs/{$id}/execute", null, $this->adminToken);
        $this->assertEnvelopeSuccess($envelope);

        self::assertSame('OK', $envelope['message'] ?? null, 'Execute must return the OK envelope message.');
    }

    public function testDeleteRemovesTheJob(): void
    {
        $id = (int) $this->job->getId();
        $envelope = $this->jsonRequest('DELETE', "/cms-api/v1/admin/scheduled-jobs/{$id}", null, $this->adminToken);
        $this->assertEnvelopeSuccess($envelope);

        self::assertSame('OK', $envelope['message'] ?? null, 'Delete must return the OK envelope message.');
    }

    public function testCancelFlipsTheQueuedJobToCancelled(): void
    {
        $id = (int) $this->job->getId();
        $envelope = $this->jsonRequest('POST', "/cms-api/v1/admin/scheduled-jobs/{$id}/cancel", null, $this->adminToken);
        $this->assertEnvelopeSuccess($envelope);

        // Public side effect: the persisted status flips to the cancelled lookup
        // (JobSchedulerService::cancelJob) and the transaction log records it.
        $this->em->clear();
        $job = $this->em->getRepository(ScheduledJob::class)->find($id);
        self::assertInstanceOf(ScheduledJob::class, $job, 'Cancelled job must still exist (cancel is not delete).');
        self::assertSame(
            LookupService::SCHEDULED_JOBS_STATUS_CANCELLED,
            $job->getStatus()->getLookupCode(),
            'Cancel must flip the queued job to the cancelled status lookup.',
        );
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
