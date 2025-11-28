<?php

namespace App\Service\CMS\Admin;

use App\Entity\ScheduledJob;
use App\Entity\Lookup;

use App\Repository\ScheduledJobRepository;
use App\Repository\UserRepository;
use App\Repository\TransactionRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\LookupService;
use App\Service\Core\BaseService;
use App\Service\Core\TransactionService;

use App\Service\Auth\UserContextService;
use App\Exception\ServiceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use App\Service\Core\JobSchedulerService;
use Psr\Log\LoggerInterface;

class AdminScheduledJobService extends BaseService
{

    public function __construct(
        private readonly UserContextService $userContextService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ScheduledJobRepository $scheduledJobRepository,
        private readonly UserRepository $userRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly LookupService $lookupService,
        private readonly TransactionService $transactionService,
        private readonly JobSchedulerService $jobSchedulerService,
        private readonly CacheService $cache,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get scheduled jobs with timezone adjustment using Doctrine QueryBuilder
     */
    public function getScheduledJobs(
        array $filters = [],
        int $page = 1,
        int $perPage = 20,
        string $sort = 'adjusted_execution_time',
        string $order = 'asc'
    ): array {
        $cacheKey = "scheduled_jobs_timezone_aware_{$page}_{$perPage}_" . md5(
            json_encode($filters) . $sort . $order
        );

        return $this->cache
            ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
            ->getList($cacheKey, function () use ($filters, $page, $perPage, $sort, $order) {
                $qb = $this->scheduledJobRepository->createQueryBuilder('sj');

                // Add JOINs for filtering and selection
                $qb->leftJoin('sj.user', 'u')
                    ->innerJoin('sj.jobType', 'jt')
                    ->innerJoin('sj.status', 'js')
                    ->leftJoin('sj.action', 'a')
                    ->leftJoin('sj.dataTable', 'dt')
                    ->leftJoin('u.timezone', 'user_tz');

                // Add WHERE conditions
                $this->applyScheduledJobsFilters($qb, $filters);

                // Add sorting
                $this->applyScheduledJobsSorting($qb, $sort, $order);

                // Get total count
                $countQb = clone $qb;
                $countQb->select('COUNT(sj.id)');
                $totalItems = (int) $countQb->getQuery()->getSingleScalarResult();

                // Add pagination
                $qb->setFirstResult(($page - 1) * $perPage)
                    ->setMaxResults($perPage);

                // Select only the main entity (relationships will be loaded)
                $qb->select('sj');

                $jobsEntities = $qb->getQuery()->getResult();
                $jobs = [];

                foreach ($jobsEntities as $job) {
                    $jobs[] = $this->formatScheduledJobForList($job);
                }

                return [
                    'scheduledJobs' => $jobs,
                    'totalCount' => $totalItems,
                    'page' => $page,
                    'pageSize' => $perPage,
                    'totalPages' => (int) ceil($totalItems / $perPage)
                ];
            });
    }

    /**
     * Apply filters to the scheduled jobs query builder
     */
    private function applyScheduledJobsFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['status'])) {
            $qb->andWhere('js.lookupCode = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['job_type'])) {
            $qb->andWhere('jt.lookupCode = :job_type')
                ->setParameter('job_type', $filters['job_type']);
        }

        if (!empty($filters['user_id'])) {
            $qb->andWhere('u.id = :user_id')
                ->setParameter('user_id', $filters['user_id']);
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('sj.id', ':search'),
                    $qb->expr()->like('u.id', ':search'),
                    $qb->expr()->like('u.email', ':search'),
                    $qb->expr()->like('a.name', ':search'),
                    $qb->expr()->like('dt.displayName', ':search'),
                    $qb->expr()->like('jt.lookupValue', ':search'),
                    $qb->expr()->like('js.lookupValue', ':search'),
                    $qb->expr()->like('sj.description', ':search'),
                    $qb->expr()->like('sj.config', ':search')
                )
            )->setParameter('search', $searchTerm);
        }

        // Determine which date column to filter on
        $dateField = match($filters['date_type'] ?? 'date_to_be_executed') {
            'date_create' => 'sj.dateCreate',
            'date_to_be_executed' => 'sj.dateToBeExecuted',
            'date_executed' => 'sj.dateExecuted',
            default => 'sj.dateToBeExecuted'
        };

        if (!empty($filters['date_from'])) {
            $dateFromObj = new \DateTime($filters['date_from']);
            $qb->andWhere("{$dateField} >= :date_from_start")
                ->setParameter('date_from_start', $dateFromObj->format('Y-m-d 00:00:00'));
        }

        if (!empty($filters['date_to'])) {
            $dateToObj = new \DateTime($filters['date_to']);
            $qb->andWhere("{$dateField} <= :date_to_end")
                ->setParameter('date_to_end', $dateToObj->format('Y-m-d 23:59:59'));
        }
    }

    /**
     * Apply sorting to the scheduled jobs query builder
     */
    private function applyScheduledJobsSorting(\Doctrine\ORM\QueryBuilder $qb, string $sort, string $order): void
    {
        $orderBy = match($sort) {
            'adjusted_execution_time' => 'sj.dateToBeExecuted',
            'date_create' => 'sj.dateCreate',
            'description' => 'sj.description',
            default => 'sj.dateToBeExecuted'
        };

        $qb->orderBy($orderBy, $order);
    }

    /**
     * Format scheduled job for list view
     */
    private function formatScheduledJobForList(ScheduledJob $job): array
    {
        // Convert timezone for datetime fields
        $cmsTimezone = new \DateTimeZone($this->cmsPreferenceService->getDefaultTimezoneCode());

        $dateCreate = $job->getDateCreate();
        if ($dateCreate) {
            $dateCreate = \DateTime::createFromInterface($dateCreate)->setTimezone($cmsTimezone);
        }

        $dateToBeExecuted = $job->getDateToBeExecuted();
        if ($dateToBeExecuted) {
            $dateToBeExecuted = \DateTime::createFromInterface($dateToBeExecuted)->setTimezone($cmsTimezone);
        }

        $dateExecuted = $job->getDateExecuted();
        if ($dateExecuted) {
            $dateExecuted = \DateTime::createFromInterface($dateExecuted)->setTimezone($cmsTimezone);
        }

        return [
            'id' => $job->getId(),
            'id_users' => $job->getUser()?->getId(),
            'user_email' => $job->getUser()?->getEmail(),
            'action_name' => $job->getAction()?->getName(),
            'data_table_name' => $job->getDataTable()?->getDisplayName(),
            'data_row' => $job->getDataRow()?->getId(),
            'job_types' => $job->getJobType()->getLookupValue(),
            'status' => $job->getStatus()->getLookupValue(),
            'description' => $job->getDescription(),
            'user_timezone' => $job->getUser()?->getTimezone()?->getLookupCode() ?? 'Europe/Zurich',
            'date_scheduled' => $dateToBeExecuted?->format('Y-m-d H:i:s'),
            'date_created' => $dateCreate?->format('Y-m-d H:i:s'),
            'date_to_be_executed' => $dateToBeExecuted?->format('Y-m-d H:i:s'),
            'date_executed' => $dateExecuted?->format('Y-m-d H:i:s'),
            'config' => $job->getConfig()
        ];
    }


    /**
     * Cancel a scheduled job
     *
     * @param int $jobId Job ID to cancel
     * @return array|null Job data if successful, null on failure
     */
    public function cancelScheduledJob(int $jobId): ?array
    {
        try {
            $user = $this->userContextService->getCurrentUser();
            $userId = $user ? $user->getId() : null;

            $result = $this->jobSchedulerService->cancelJob($jobId, $userId ?: $this->lookupService::TRANSACTION_BY_BY_SYSTEM);

            if ($result) {
                return $this->getScheduledJobById($jobId);
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to cancel scheduled job', [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get scheduled job by ID with all related data and entity scope caching
     */
    public function getScheduledJobById(int $jobId): array
    {
        $cacheKey = "scheduled_job_{$jobId}";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_SCHEDULED_JOB, $jobId)
            ->getItem($cacheKey, function () use ($jobId) {
                $job = $this->scheduledJobRepository->findScheduledJobById($jobId);

                if (!$job) {
                    throw new ServiceException('Scheduled job not found', Response::HTTP_NOT_FOUND);
                }

                return $this->formatScheduledJobForDetail($job);
            });
    }

    /**
     * Execute a scheduled job
     */
    public function executeScheduledJob(int $jobId): array|false
    {
        $job = $this->jobSchedulerService->executeJob($jobId, LookupService::TRANSACTION_BY_BY_USER);
        return $job ? $this->formatScheduledJobForDetail($job) : false;
    }

    /**
     * Delete a scheduled job (change status to deleted)
     */
    public function deleteScheduledJob(int $jobId): bool
    {
        return $this->jobSchedulerService->deleteJob($jobId, LookupService::TRANSACTION_BY_BY_USER);
    }

    /**
     * Get transactions related to a scheduled job with entity scope caching
     */
    public function getJobTransactions(int $jobId): array
    {
        $cacheKey = "scheduled_job_transactions_{$jobId}";
        return $this->cache
            ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_SCHEDULED_JOB, $jobId)
            ->getItem($cacheKey, function () use ($jobId) {
                $job = $this->scheduledJobRepository->find($jobId);

                if (!$job) {
                    throw new ServiceException('Scheduled job not found', Response::HTTP_NOT_FOUND);
                }

                $transactions = $this->transactionRepository->createQueryBuilder('t')
                    ->where('t.tableName = :tableName')
                    ->andWhere('t.idTableName = :idTableName')
                    ->setParameter('tableName', 'scheduledJobs')
                    ->setParameter('idTableName', $jobId)
                    ->orderBy('t.transactionTime', 'desc')
                    ->getQuery()
                    ->getResult();

                // Convert timezone for datetime fields
                $cmsTimezone = new \DateTimeZone($this->cmsPreferenceService->getDefaultTimezoneCode());

                $formattedTransactions = [];
                foreach ($transactions as $transaction) {
                    $transactionTime = $transaction->getTransactionTime();
                    if ($transactionTime) {
                        $transactionTime = $transactionTime->setTimezone($cmsTimezone);
                    }

                    $formattedTransactions[] = [
                        'transaction_id' => $transaction->getId(),
                        'transaction_time' => $transactionTime?->format('Y-m-d H:i:s'),
                        'transaction_type' => $transaction->getTransactionType()?->getLookupValue(),
                        'transaction_verbal_log' => $transaction->getTransactionLog(),
                        'user' => $transaction->getUser()?->getName()
                    ];
                }

                return $formattedTransactions;
            });
    }


    /**
     * Format scheduled job for detail view
     */
    private function formatScheduledJobForDetail(ScheduledJob $job): array
    {
        // Convert timezone for datetime fields
        $cmsTimezone = new \DateTimeZone($this->cmsPreferenceService->getDefaultTimezoneCode());

        $dateCreate = $job->getDateCreate();
        if ($dateCreate) {
            $dateCreate = \DateTime::createFromInterface($dateCreate)->setTimezone($cmsTimezone);
        }

        $dateToBeExecuted = $job->getDateToBeExecuted();
        if ($dateToBeExecuted) {
            $dateToBeExecuted = \DateTime::createFromInterface($dateToBeExecuted)->setTimezone($cmsTimezone);
        }

        $dateExecuted = $job->getDateExecuted();
        if ($dateExecuted) {
            $dateExecuted = \DateTime::createFromInterface($dateExecuted)->setTimezone($cmsTimezone);
        }

        return [
            'id' => $job->getId(),
            'status' => [
                'id' => $job->getStatus()?->getId(),
                'value' => $job->getStatus()?->getLookupValue()
            ],
            'job_type' => [
                'id' => $job->getJobType()?->getId(),
                'value' => $job->getJobType()?->getLookupValue()
            ],
            'description' => $job->getDescription(),
            'date_create' => $dateCreate?->format('Y-m-d H:i:s'),
            'date_to_be_executed' => $dateToBeExecuted?->format('Y-m-d H:i:s'),
            'date_executed' => $dateExecuted?->format('Y-m-d H:i:s'),
            'config' => $job->getConfig()
        ];
    }

}