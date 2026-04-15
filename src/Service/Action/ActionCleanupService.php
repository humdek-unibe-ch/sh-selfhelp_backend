<?php

namespace App\Service\Action;

use App\Entity\Action;
use App\Entity\ScheduledJob;
use App\Repository\ScheduledJobRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies action-driven cleanup rules to queued scheduled jobs.
 *
 * Cleanup covers action-wide replacement, record-scoped replacement, deleted-record
 * cleanup, and reminder cancellation after the target form has been completed.
 */
class ActionCleanupService
{
    public function __construct(
        private readonly ScheduledJobRepository $scheduledJobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LookupService $lookupService,
        private readonly TransactionService $transactionService
    ) {
    }

    /**
     * Delete queued jobs for a given action, optionally scoped to one user.
     *
     * @param Action $action
     *   The action whose queued jobs should be deleted.
     * @param int|null $userId
     *   Optional user restriction for the queued jobs being deleted.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     */
    public function deleteQueuedJobsForAction(Action $action, ?int $userId, string $transactionBy): void
    {
        $jobs = $this->scheduledJobRepository->findQueuedJobsForAction($action->getId(), $userId);
        $this->markJobsDeleted($jobs, $transactionBy);
    }

    /**
     * Delete queued jobs for one action and one data-row source record.
     *
     * @param Action $action
     *   The action whose queued jobs should be deleted.
     * @param int $recordId
     *   The data-row id linked to the queued jobs.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     */
    public function deleteQueuedJobsForRecordAndAction(Action $action, int $recordId, string $transactionBy): void
    {
        $jobs = $this->scheduledJobRepository->findQueuedJobsForActionAndRow($action->getId(), $recordId);
        $this->markJobsDeleted($jobs, $transactionBy);
    }

    /**
     * Delete all queued jobs linked to a deleted source record.
     *
     * @param int $recordId
     *   The deleted data-row id.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     */
    public function deleteQueuedJobsForRecord(int $recordId, string $transactionBy): void
    {
        $jobs = $this->scheduledJobRepository->findQueuedJobsForRow($recordId);
        $this->markJobsDeleted($jobs, $transactionBy);
    }

    /**
     * Delete queued reminder jobs for a user and reminder target table.
     *
     * @param int $userId
     *   The user whose reminder jobs should be inspected.
     * @param int $tableId
     *   The reminder target data-table id.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     */
    public function deleteQueuedReminderJobsForUserAndTable(int $userId, int $tableId, string $transactionBy): void
    {
        $jobs = $this->scheduledJobRepository->findQueuedReminderJobsForUserAndTable($userId, $tableId);
        $this->markJobsDeleted($jobs, $transactionBy);
    }

    /**
     * @param ScheduledJob[] $jobs
     *   The queued jobs that should be marked deleted.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     */
    private function markJobsDeleted(array $jobs, string $transactionBy): void
    {
        if ($jobs === []) {
            return;
        }

        $deletedStatus = $this->lookupService->findByTypeAndCode(
            LookupService::SCHEDULED_JOBS_STATUS,
            LookupService::SCHEDULED_JOBS_STATUS_DELETED
        );

        foreach ($jobs as $job) {
            $job->setStatus($deletedStatus);
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                $transactionBy,
                'scheduledJobs',
                $job->getId(),
                false,
                'Scheduled job marked as deleted by action cleanup'
            );
        }

        $this->entityManager->flush();
    }
}
