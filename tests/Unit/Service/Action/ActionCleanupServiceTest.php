<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Entity\Action;
use App\Entity\Lookup;
use App\Entity\ScheduledJob;
use App\Entity\Transaction;
use App\Plugin\Lookup\LookupPolicyRegistry;
use App\Repository\LookupRepository;
use App\Repository\ScheduledJobRepository;
use App\Service\Action\ActionCleanupService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

/**
 * Unit test for action-driven cleanup of queued scheduled jobs.
 *
 * Collaborators are deterministic doubles (no DB, no clock, no random ids). The
 * final {@see LookupService} cannot be mocked, so it is built for real over a
 * controlled cache + repository pair that resolve the "deleted" status lookup.
 * Per PHPUnit 12 guidance, only doubles whose calls are asserted use createMock;
 * the rest are stubs.
 *
 * Pinned behaviour: each entry point delegates to the matching repository query;
 * found jobs are marked deleted, audited, and flushed exactly once; an empty
 * result is a true no-op; a missing status lookup fails loudly without flushing.
 */
final class ActionCleanupServiceTest extends TestCase
{
    public function testEachEntryPointDelegatesToTheMatchingRepositoryQuery(): void
    {
        $repository = $this->createMock(ScheduledJobRepository::class);
        $repository->expects(self::once())->method('findQueuedJobsForAction')->with(7, 42)->willReturn([]);
        $repository->expects(self::once())->method('findQueuedJobsForActionAndRow')->with(7, 200)->willReturn([]);
        $repository->expects(self::once())->method('findQueuedJobsForRow')->with(200)->willReturn([]);
        $repository->expects(self::once())->method('findQueuedReminderJobsForUserAndTable')->with(42, 9)->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        // Empty result sets must never flush.
        $entityManager->expects(self::never())->method('flush');

        $service = new ActionCleanupService(
            $repository,
            $entityManager,
            $this->makeLookupService(null),
            $this->createStub(TransactionService::class),
        );

        $action = $this->createStub(Action::class);
        $action->method('getId')->willReturn(7);

        $service->deleteQueuedJobsForAction($action, 42, LookupService::TRANSACTION_BY_BY_SYSTEM);
        $service->deleteQueuedJobsForRecordAndAction($action, 200, LookupService::TRANSACTION_BY_BY_SYSTEM);
        $service->deleteQueuedJobsForRecord(200, LookupService::TRANSACTION_BY_BY_SYSTEM);
        $service->deleteQueuedReminderJobsForUserAndTable(42, 9, LookupService::TRANSACTION_BY_BY_SYSTEM);
    }

    public function testFoundJobsAreMarkedDeletedAuditedAndFlushedOnce(): void
    {
        $deletedStatus = $this->createStub(Lookup::class);

        $job1 = $this->createMock(ScheduledJob::class);
        $job1->method('getId')->willReturn(101);
        $job1->expects(self::once())->method('setStatus')->with($deletedStatus);

        $job2 = $this->createMock(ScheduledJob::class);
        $job2->method('getId')->willReturn(102);
        $job2->expects(self::once())->method('setStatus')->with($deletedStatus);

        $repository = $this->createStub(ScheduledJobRepository::class);
        $repository->method('findQueuedJobsForAction')->willReturn([$job1, $job2]);

        $logged = [];
        $transactionService = $this->createStub(TransactionService::class);
        $transactionService->method('logTransaction')->willReturnCallback(
            function (string $type, string $by, ?string $table, ?int $id) use (&$logged): Transaction {
                $logged[] = [$type, $by, $table, $id];

                return $this->createStub(Transaction::class);
            }
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $action = $this->createStub(Action::class);
        $action->method('getId')->willReturn(7);

        $service = new ActionCleanupService(
            $repository,
            $entityManager,
            $this->makeLookupService($deletedStatus),
            $transactionService,
        );
        $service->deleteQueuedJobsForAction($action, null, LookupService::TRANSACTION_BY_BY_SYSTEM);

        self::assertSame(
            [
                [LookupService::TRANSACTION_TYPES_DELETE, LookupService::TRANSACTION_BY_BY_SYSTEM, 'scheduled_jobs', 101],
                [LookupService::TRANSACTION_TYPES_DELETE, LookupService::TRANSACTION_BY_BY_SYSTEM, 'scheduled_jobs', 102],
            ],
            $logged,
            'Each deleted job must be audited as a scheduled_jobs delete transaction.'
        );
    }

    public function testEmptyResultIsANoOpWithoutAuditOrFlush(): void
    {
        $repository = $this->createStub(ScheduledJobRepository::class);
        $repository->method('findQueuedJobsForRow')->willReturn([]);

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->expects(self::never())->method('logTransaction');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new ActionCleanupService(
            $repository,
            $entityManager,
            $this->makeLookupService($this->createStub(Lookup::class)),
            $transactionService,
        );
        $service->deleteQueuedJobsForRecord(200, LookupService::TRANSACTION_BY_BY_SYSTEM);
    }

    public function testMissingDeletedStatusLookupThrowsWithoutFlushing(): void
    {
        $job = $this->createMock(ScheduledJob::class);
        $job->expects(self::never())->method('setStatus');

        $repository = $this->createStub(ScheduledJobRepository::class);
        $repository->method('findQueuedJobsForRow')->willReturn([$job]);

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->expects(self::never())->method('logTransaction');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new ActionCleanupService(
            $repository,
            $entityManager,
            $this->makeLookupService(null),
            $transactionService,
        );

        $this->expectException(\RuntimeException::class);
        $service->deleteQueuedJobsForRecord(200, LookupService::TRANSACTION_BY_BY_SYSTEM);
    }

    /**
     * Build a real (final) LookupService whose findByTypeAndCode resolves to the
     * given status (or null) via a controlled cache + repository pair.
     */
    private function makeLookupService(?Lookup $status): LookupService
    {
        $lookupRepository = $this->createStub(LookupRepository::class);

        $cache = $this->createStub(CacheService::class);
        $cache->method('withCategory')->willReturnSelf();

        if ($status === null) {
            $cache->method('getItem')->willReturn(null);
        } else {
            $cache->method('getItem')->willReturn(555);
            $lookupRepository->method('find')->willReturn($status);
        }

        return new LookupService(
            $lookupRepository,
            $cache,
            $this->createStub(EventDispatcherInterface::class),
            new LookupPolicyRegistry(),
            new NullLogger(),
        );
    }
}
