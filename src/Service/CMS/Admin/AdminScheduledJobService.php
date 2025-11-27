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
     * Get scheduled jobs with timezone adjustment using SQL
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
                $sql = $this->buildTimezoneAwareQuery($filters, $sort, $order, $perPage, ($page - 1) * $perPage);
                $conn = $this->entityManager->getConnection();
                $result = $conn->executeQuery($sql, $this->buildCountParameters($filters));

                $jobs = [];
                while ($row = $result->fetchAssociative()) {
                    $jobs[] = $this->hydrateJobFromRow($row);
                }

                // Get total count for pagination
                $countSql = $this->buildCountQuery($filters);
                $countResult = $conn->executeQuery($countSql, $this->buildCountParameters($filters));
                $totalItems = (int) $countResult->fetchOne();

                return [
                    'scheduledJobs' => $jobs,
                    'totalCount' => $totalItems,
                    'page' => $page,
                    'pageSize' => $perPage,
                    'totalPages' => (int) ceil($totalItems / $perPage)
                ];
            });
    }

    private function buildTimezoneAwareQuery(array $filters, string $sort, string $order, int $limit, int $offset): string
    {
        $orderBy = match($sort) {
            'adjusted_execution_time' => 'sj.date_to_be_executed',
            'date_create' => 'sj.date_create',
            'description' => 'sj.description',
            default => 'sj.date_to_be_executed'
        };

        // Get CMS default timezone value
        $cmsTimezone = $this->cmsPreferenceService->getDefaultTimezoneCode();

        return "
            SELECT
                sj.id,
                sj.id_users,
                u.email as user_email,
                a.name as action_name,
                dt.displayName as data_table_name,
                sj.id_dataRows as data_row,
                jt.lookup_value as job_types,
                js.lookup_value as status,
                sj.description,
                COALESCE(user_tz.lookup_code, 'Europe/Zurich') as timezone,
                CONVERT_TZ(
                    sj.date_to_be_executed,
                    '+00:00',
                    '{$cmsTimezone}'
                ) as date_scheduled,
                CONVERT_TZ(
                    sj.date_create,
                    '+00:00',
                    '{$cmsTimezone}'
                ) as date_created,
                CONVERT_TZ(
                    sj.date_to_be_executed,
                    '+00:00',
                    '{$cmsTimezone}'
                ) as date_to_be_executed,
                CONVERT_TZ(
                    sj.date_executed,
                    '+00:00',
                    '{$cmsTimezone}'
                ) as date_executed,
                sj.description as description,
                sj.config

            FROM scheduledJobs sj
            LEFT JOIN users u ON u.id = sj.id_users
            INNER JOIN lookups jt ON jt.id = sj.id_jobTypes
            INNER JOIN lookups js ON js.id = sj.id_jobStatus

            LEFT JOIN actions a ON a.id = sj.id_actions
            LEFT JOIN dataTables dt ON dt.id = sj.id_dataTables

            LEFT JOIN lookups user_tz ON user_tz.id = u.id_timezones
                AND user_tz.type_code = 'timezones'

            WHERE 1=1
            " . $this->buildWhereClause($filters) . "

            ORDER BY {$orderBy} {$order}
            LIMIT {$limit} OFFSET {$offset}";
    }

    private function buildCountQuery(array $filters): string
    {
        return "
            SELECT COUNT(*)
            FROM scheduledJobs sj
            INNER JOIN users u ON u.id = sj.id_users
            INNER JOIN lookups jt ON jt.id = sj.id_jobTypes
            INNER JOIN lookups js ON js.id = sj.id_jobStatus
            WHERE 1=1
            " . $this->buildWhereClause($filters);
    }

    private function buildWhereClause(array $filters): string
    {
        $clauses = [];

        if (!empty($filters['status'])) {
            $clauses[] = "js.lookup_code = :status";
        }

        if (!empty($filters['job_type'])) {
            $clauses[] = "jt.lookup_code = :job_type";
        }

        if (!empty($filters['user_id'])) {
            $clauses[] = "sj.id_users = :user_id";
        }

        if (!empty($filters['search'])) {
            // Search across all relevant columns
            $clauses[] = "(
                sj.id LIKE :search OR
                sj.id_users LIKE :search OR
                u.email LIKE :search OR
                a.name LIKE :search OR
                dt.displayName LIKE :search OR
                sj.id_dataRows LIKE :search OR
                jt.lookup_value LIKE :search OR
                js.lookup_value LIKE :search OR
                sj.description LIKE :search OR
                COALESCE(user_tz.lookup_code, 'Europe/Zurich') LIKE :search OR
                sj.config LIKE :search
            )";
        }

        // Determine which date column to filter on
        $dateColumn = match($filters['date_type'] ?? 'date_to_be_executed') {
            'date_create' => 'sj.date_create',
            'date_to_be_executed' => 'sj.date_to_be_executed',
            'date_executed' => 'sj.date_executed',
            default => 'sj.date_to_be_executed'
        };

        if (!empty($filters['date_from'])) {
            $clauses[] = "{$dateColumn} >= :date_from_start";
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = "{$dateColumn} <= :date_to_end";
        }

        return $clauses ? ' AND ' . implode(' AND ', $clauses) : '';
    }


    private function buildCountParameters(array $filters): array
    {
        $params = [];

        if (!empty($filters['status'])) {
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['job_type'])) {
            $params['job_type'] = $filters['job_type'];
        }

        if (!empty($filters['user_id'])) {
            $params['user_id'] = $filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['date_from'])) {
            // Convert date string to start of day
            $dateFromObj = new \DateTime($filters['date_from']);
            $params['date_from_start'] = $dateFromObj->format('Y-m-d 00:00:00');
        }

        if (!empty($filters['date_to'])) {
            // Convert date string to end of day
            $dateToObj = new \DateTime($filters['date_to']);
            $params['date_to_end'] = $dateToObj->format('Y-m-d 23:59:59');
        }

        return $params;
    }

    private function hydrateJobFromRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'id_users' => $row['id_users'],
            'user_email' => $row['user_email'],
            'action_name' => $row['action_name'],
            'data_table_name' => $row['data_table_name'],
            'data_row' => $row['data_row'],
            'job_types' => $row['job_types'],
            'status' => $row['status'],
            'description' => $row['description'],
            'timezone' => $row['timezone'],
            'date_scheduled' => $row['date_scheduled'],
            'date_created' => $row['date_created'],
            'date_to_be_executed' => $row['date_to_be_executed'],
            'date_executed' => $row['date_executed'],
            'config' => json_decode($row['config'] ?? '{}', true)
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

                $formattedTransactions = [];
                foreach ($transactions as $transaction) {
                    $formattedTransactions[] = [
                        'transaction_id' => $transaction->getId(),
                        'transaction_time' => $transaction->getTransactionTime()->format('Y-m-d H:i:s'),
                        'transaction_type' => $transaction->getTransactionType()?->getLookupValue(),
                        'transaction_verbal_log' => $transaction->getTransactionLog(),
                        'user' => $transaction->getUser()?->getName()
                    ];
                }

                return $formattedTransactions;
            });
    }

    /**
     * Format scheduled job for list view
     */
    private function formatScheduledJobForList(ScheduledJob $job): array
    {
        return [
            'id' => $job->getId(),
            'status' => $job->getStatus()?->getLookupValue(),
            'type' => $job->getJobType()?->getLookupValue(),
            'entry_date' => $job->getDateCreate()->format('Y-m-d H:i:s'),
            'date_to_be_executed' => $job->getDateToBeExecuted()?->format('Y-m-d H:i:s'),
            'execution_date' => $job->getDateExecuted()?->format('Y-m-d H:i:s'),
            'description' => $job->getDescription(),
            'message' => $job->getConfig()
        ];
    }

    /**
     * Format scheduled job for detail view
     */
    private function formatScheduledJobForDetail(ScheduledJob $job): array
    {
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
            'date_create' => $job->getDateCreate()->format('Y-m-d H:i:s'),
            'date_to_be_executed' => $job->getDateToBeExecuted()?->format('Y-m-d H:i:s'),
            'date_executed' => $job->getDateExecuted()?->format('Y-m-d H:i:s'),
            'config' => $job->getConfig()
        ];
    }

}