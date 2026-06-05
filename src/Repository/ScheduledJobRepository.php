<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\ScheduledJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends ServiceEntityRepository<ScheduledJob>
 */
class ScheduledJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledJob::class);
    }

    /**
     * Create query builder for scheduled jobs with joins
     *
     * @return QueryBuilder
     *   A base query builder with status, type, user, action, and reminder joins.
     */
    public function createScheduledJobsQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('sj')
            ->leftJoin('sj.status', 'status')
            ->leftJoin('sj.jobType', 'jobType')
            ->leftJoin('sj.user', 'user')
            ->leftJoin('sj.action', 'action')
            ->leftJoin('sj.reminderMetadata', 'reminderMetadata');
    }

    /**
     * Find scheduled jobs with pagination, search, and filtering
     *
     * @return array<string, mixed>
     *   Paginated repository data for scheduled-job listings.
     */
    public function findScheduledJobsWithPagination(
        int $page = 1,
        int $pageSize = 20,
        ?string $search = null,
        ?string $status = null,
        ?string $jobType = null,
        ?\DateTime $dateFrom = null,
        ?\DateTime $dateTo = null,
        ?string $dateType = 'date_to_be_executed',
        ?string $sort = null,
        string $sortDirection = 'asc'
    ): array {
        $qb = $this->createScheduledJobsQueryBuilder();

        // Apply search filter
        if ($search) {
            $qb->andWhere('(sj.description LIKE :search OR sj.id LIKE :search OR user.name LIKE :search OR action.config LIKE :search OR sj.config LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        // Apply status filter
        if ($status) {
            $qb->andWhere('status.lookupValue = :status')
                ->setParameter('status', $status);
        }

        // Apply job type filter
        if ($jobType) {
            $qb->andWhere('jobType.lookupValue = :jobType')
                ->setParameter('jobType', $jobType);
        }

        // Apply date range filter
        if ($dateFrom && $dateTo) {
            // Set $dateFrom as start of day, $dateTo as end of day
            $dateFrom->setTime(0, 0, 0);
            $dateTo->setTime(23, 59, 59);

            switch ($dateType) {
                case 'date_create':
                    $qb->andWhere('sj.dateCreate BETWEEN :dateFrom AND :dateTo');
                    break;
                case 'date_to_be_executed':
                    $qb->andWhere('sj.dateToBeExecuted BETWEEN :dateFrom AND :dateTo');
                    break;
                case 'date_executed':
                    $qb->andWhere('sj.dateExecuted BETWEEN :dateFrom AND :dateTo');
                    break;
            }
            $qb->setParameter('dateFrom', $dateFrom->format('Y-m-d H:i:s'))
                ->setParameter('dateTo', $dateTo->format('Y-m-d H:i:s'));


        }

        // Apply sorting
        $validSortFields = ['id', 'date_create', 'date_to_be_executed', 'date_executed', 'description'];
        if ($sort && in_array($sort, $validSortFields)) {
            switch ($sort) {
                case 'date_create':
                    $qb->orderBy('sj.dateCreate', $sortDirection);
                    break;
                case 'date_to_be_executed':
                    $qb->orderBy('sj.dateToBeExecuted', $sortDirection);
                    break;
                case 'date_executed':
                    $qb->orderBy('sj.dateExecuted', $sortDirection);
                    break;
                default:
                    $qb->orderBy('sj.' . $sort, $sortDirection);
                    break;
            }
        } else {
            $qb->orderBy('sj.dateCreate', 'desc');
        }

        // Get total count
        $countQb = clone $qb;
        $totalCount = (int) $countQb->select('COUNT(DISTINCT sj.id)')->getQuery()->getSingleScalarResult();

        // Apply pagination
        $offset = ($page - 1) * $pageSize;
        $qb->setFirstResult($offset)->setMaxResults($pageSize);

        $scheduledJobs = $qb->getQuery()->getResult();

        return [
            'scheduledJobs' => $scheduledJobs,
            'totalCount' => (int) $totalCount,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => ceil($totalCount / $pageSize)
        ];
    }

    /**
     * Find scheduled job by ID with all related data
     *
     * @param int $id
     *   The scheduled job id.
     *
     * @return ScheduledJob|null
     *   The matching scheduled job or `null`.
     */
    public function findScheduledJobById(int $id): ?ScheduledJob
    {
        /** @var ScheduledJob|null $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('sj.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Find scheduled jobs by status
     *
     * @param string $status
     *   The human-readable lookup value for the status.
     *
     * @return ScheduledJob[]
     *   Matching scheduled jobs.
     */
    public function findByStatus(string $status): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('status.lookupValue = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find scheduled jobs by job type
     *
     * @param string $jobType
     *   The human-readable lookup value for the job type.
     *
     * @return ScheduledJob[]
     *   Matching scheduled jobs.
     */
    public function findByJobType(string $jobType): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('jobType.lookupValue = :jobType')
            ->setParameter('jobType', $jobType)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find scheduled jobs by user
     *
     * @param int $userId
     *   The user id that owns the jobs.
     *
     * @return ScheduledJob[]
     *   Matching scheduled jobs.
     */
    public function findByUser(int $userId): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('user.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find scheduled jobs that need to be executed
     *
     * @return ScheduledJob[]
     *   Queued jobs whose execution time is now or in the past.
     */
    public function findJobsToExecute(): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('sj.dateToBeExecuted <= :now')
            ->andWhere('status.lookupCode = :status')
            ->setParameter('now', new \DateTime('now', new \DateTimeZone('UTC')))
            ->setParameter('status', 'queued')
            ->orderBy('sj.dateToBeExecuted', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find queued jobs created by one action, optionally for one user.
     *
     * @param int $actionId
     *   The source action id.
     * @param int|null $userId
     *   Optional user restriction.
     *
     * @return ScheduledJob[]
     */
    public function findQueuedJobsForAction(int $actionId, ?int $userId = null): array
    {
        $qb = $this->createScheduledJobsQueryBuilder()
            ->andWhere('action.id = :actionId')
            ->andWhere('status.lookupCode = :status')
            ->setParameter('actionId', $actionId)
            ->setParameter('status', 'queued');

        if ($userId !== null) {
            $qb->andWhere('user.id = :userId')
                ->setParameter('userId', $userId);
        }

        /** @var list<ScheduledJob> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Find queued jobs created by one action for one source data row.
     *
     * @param int $actionId
     *   The source action id.
     * @param int $rowId
     *   The source data-row id.
     *
     * @return ScheduledJob[]
     */
    public function findQueuedJobsForActionAndRow(int $actionId, int $rowId): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('action.id = :actionId')
            ->andWhere('IDENTITY(sj.dataRow) = :rowId')
            ->andWhere('status.lookupCode = :status')
            ->setParameter('actionId', $actionId)
            ->setParameter('rowId', $rowId)
            ->setParameter('status', 'queued')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find queued jobs linked to one source data row.
     *
     * @param int $rowId
     *   The source data-row id.
     *
     * @return ScheduledJob[]
     */
    public function findQueuedJobsForRow(int $rowId): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('IDENTITY(sj.dataRow) = :rowId')
            ->andWhere('status.lookupCode = :status')
            ->setParameter('rowId', $rowId)
            ->setParameter('status', 'queued')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find queued future jobs for a user (used for timezone recalculation).
     *
     * Only `queued` jobs whose execution time is still in the future are
     * returned; already due, running, or terminal jobs are excluded.
     *
     * @param int $userId
     *   The user whose queued future jobs should be returned.
     * @param \DateTimeInterface $now
     *   The current UTC time.
     *
     * @return ScheduledJob[]
     */
    public function findQueuedFutureJobsForUser(int $userId, \DateTimeInterface $now): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('user.id = :userId')
            ->andWhere('status.lookupCode = :status')
            ->andWhere('sj.dateToBeExecuted > :now')
            ->setParameter('userId', $userId)
            ->setParameter('status', 'queued')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Count due queued jobs (execution time now or in the past).
     *
     * @param \DateTimeInterface $now
     *   The current UTC time.
     */
    public function countDueQueuedJobs(\DateTimeInterface $now): int
    {
        return (int) $this->createQueryBuilder('sj')
            ->select('COUNT(sj.id)')
            ->leftJoin('sj.status', 'status')
            ->andWhere('sj.dateToBeExecuted <= :now')
            ->andWhere('status.lookupCode = :status')
            ->setParameter('now', $now)
            ->setParameter('status', 'queued')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find due queued jobs with a DB-level limit, oldest first.
     *
     * @param \DateTimeInterface $now
     *   The current UTC time.
     * @param int $limit
     *   The maximum number of jobs to return.
     *
     * @return ScheduledJob[]
     */
    public function findDueQueuedJobs(\DateTimeInterface $now, int $limit): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('sj.dateToBeExecuted <= :now')
            ->andWhere('status.lookupCode = :status')
            ->setParameter('now', $now)
            ->setParameter('status', 'queued')
            ->orderBy('sj.dateToBeExecuted', 'ASC')
            ->addOrderBy('sj.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Count jobs currently in the `running` state.
     */
    public function countRunningJobs(): int
    {
        return (int) $this->createQueryBuilder('sj')
            ->select('COUNT(sj.id)')
            ->leftJoin('sj.status', 'status')
            ->andWhere('status.lookupCode = :status')
            ->setParameter('status', 'running')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find running jobs whose start time is older than the stale threshold.
     *
     * @param \DateTimeInterface $threshold
     *   Jobs started at or before this UTC instant are considered stale.
     *
     * @return ScheduledJob[]
     */
    public function findStaleRunningJobs(\DateTimeInterface $threshold): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('status.lookupCode = :status')
            ->andWhere('sj.dateStarted IS NOT NULL')
            ->andWhere('sj.dateStarted <= :threshold')
            ->setParameter('status', 'running')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Atomically claim a queued job for execution.
     *
     * Transitions the job from `queued` to `running` in a single UPDATE guarded
     * by the current status, so overlapping runners cannot both execute it.
     *
     * @param int $jobId
     *   The job to claim.
     * @param \DateTimeInterface $startedAt
     *   The execution start time recorded on the job.
     *
     * @return bool
     *   `true` when this caller won the claim; `false` when another caller did.
     */
    public function claimQueuedJobForExecution(int $jobId, \DateTimeInterface $startedAt): bool
    {
        $em = $this->getEntityManager();

        $queuedStatusId = (int) $em->createQuery(
            'SELECT l.id FROM App\\Entity\\Lookup l WHERE l.typeCode = :type AND l.lookupCode = :code'
        )->setParameter('type', 'scheduledJobsStatus')->setParameter('code', 'queued')->getSingleScalarResult();

        $runningStatusId = (int) $em->createQuery(
            'SELECT l.id FROM App\\Entity\\Lookup l WHERE l.typeCode = :type AND l.lookupCode = :code'
        )->setParameter('type', 'scheduledJobsStatus')->setParameter('code', 'running')->getSingleScalarResult();

        $affected = $em->createQuery(
            'UPDATE App\\Entity\\ScheduledJob j
                SET j.status = :running, j.dateStarted = :startedAt
              WHERE j.id = :id AND IDENTITY(j.status) = :queued'
        )
            ->setParameter('running', $runningStatusId)
            ->setParameter('startedAt', $startedAt)
            ->setParameter('id', $jobId)
            ->setParameter('queued', $queuedStatusId)
            ->execute();

        return $affected === 1;
    }

    /**
     * Find queued reminder jobs that should be cleared when a form is completed.
     *
     * @param int $userId
     *   The user who owns the reminder jobs.
     * @param int $reminderTableId
     *   The reminder target data-table id.
     *
     * @return ScheduledJob[]
     */
    public function findQueuedReminderJobsForUserAndTable(int $userId, int $reminderTableId): array
    {
        /** @var list<ScheduledJob> $result */
        $result = $this->createScheduledJobsQueryBuilder()
            ->andWhere('user.id = :userId')
            ->andWhere('IDENTITY(reminderMetadata.reminderDataTable) = :reminderTableId')
            ->andWhere('status.lookupCode = :status')
            ->andWhere('(reminderMetadata.sessionEndDate IS NULL OR :now BETWEEN reminderMetadata.sessionStartDate AND reminderMetadata.sessionEndDate)')
            ->setParameter('userId', $userId)
            ->setParameter('reminderTableId', $reminderTableId)
            ->setParameter('status', 'queued')
            ->setParameter('now', new \DateTime('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getResult();

        return $result;
    }
}
