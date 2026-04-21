<?php

namespace App\Service\CMS\Admin;

use App\Entity\ScheduledJob;
use App\Entity\Lookup;

use App\Repository\ScheduledJobRepository;
use App\Repository\UserRepository;
use App\Repository\TransactionRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\CMS\Admin\AdminActionTranslationService;
use App\Service\Core\LookupService;
use App\Service\Core\BaseService;
use App\Service\Core\TransactionService;

use App\Service\Auth\UserContextService;
use App\Exception\ServiceException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use App\Service\Core\JobSchedulerService;
use Psr\Log\LoggerInterface;

/**
 * Provides admin-facing scheduled-job list, detail, execution, and audit operations.
 *
 * Display formatting in this service resolves action translation keys using the
 * CMS default language so list and detail views show human-readable content.
 */
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
        private readonly AdminActionTranslationService $adminActionTranslationService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get ALL scheduled jobs without pagination (used for calendar view, export, etc.)
     * 
     * Returns the same formatted structure as the list view for consistency.
     */
    public function getAllScheduledJobs(
        array $filters = [],
        string $sort = 'adjusted_execution_time',
        string $sortDirection = 'asc',
    ): array {
        $cacheKey = "scheduled_jobs_all_" . md5(
            json_encode($filters) . $sort . $sortDirection
        );

        return $this->cache
            ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
            ->getList($cacheKey, function () use ($filters, $sort, $sortDirection) {
                $qb = $this->scheduledJobRepository->createQueryBuilder('sj');

                $qb->leftJoin('sj.user', 'u')
                    ->innerJoin('sj.jobType', 'jt')
                    ->innerJoin('sj.status', 'js')
                    ->leftJoin('sj.action', 'a')
                    ->leftJoin('sj.dataTable', 'dt')
                    ->leftJoin('u.timezone', 'user_tz');

                // Filter and sorting logic
                $this->applyScheduledJobsFilters($qb, $filters);
                $this->applyScheduledJobsSorting($qb, $sort, $sortDirection);

                // Safety limit to prevent memory / performance issues
                $qb->setMaxResults(5000);   // Expected max TODO: @Stefan check here

                $qb->select('sj');

                $jobsEntities = $qb->getQuery()->getResult();

                $jobs = [];
                foreach ($jobsEntities as $job) {
                    $jobs[] = $this->formatScheduledJobForList($job);
                }

                return [
                    'scheduledJobs' => $jobs,
                    'totalCount' => count($jobs),
                ];
            });
    }

    /**
     * Get paginated scheduled jobs formatted for the admin list view.
     *
     * @param array<string, mixed> $filters
     *   Search, status, type, and date filters from the admin UI.
     * @param int $page
     *   The requested page number.
     * @param int $perPage
     *   The requested page size.
     * @param string $sort
     *   The requested sort key.
     * @param string $order
     *   The sort direction.
     * @param string $order
     *   The sort direction.
     *
     * @return array<string, mixed>
     *   Paginated scheduled-job data for the admin table.
     */
    public function getScheduledJobs(
        array $filters = [],
        int $page = 1,
        int $perPage = 20,
        string $sort = 'adjusted_execution_time',
        string $order = 'asc',
        bool $includeTransactions = false,
    ): array {
        $cacheKey = "scheduled_jobs_timezone_aware_{$page}_{$perPage}_" . md5(
            json_encode($filters) . $sort . $order . 10232
        );

        return $this->cache
            ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
            ->getList($cacheKey, function () use ($filters, $page, $perPage, $sort, $order, $includeTransactions) {
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
                    $jobs[] = $this->formatScheduledJobForList($job, $includeTransactions);
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
     * Apply admin list filters to the scheduled-jobs query.
     *
     * @param \Doctrine\ORM\QueryBuilder $qb
     *   The query builder being prepared for execution.
     * @param array<string, mixed> $filters
     *   Raw filter values from the admin UI.
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

        if (!empty($filters['actionId'])) {
        $qb->andWhere('a.id = :actionId')
            ->setParameter('actionId', $filters['actionId']);
        }

        if (!empty($filters['userId'])) {
            $qb->andWhere('u.id = :user_id')
                ->setParameter('user_id', $filters['userId']);
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
     * Apply admin list sorting to the scheduled-jobs query.
     *
     * @param \Doctrine\ORM\QueryBuilder $qb
     *   The query builder being prepared for execution.
     * @param string $sort
     *   The requested sort key.
     * @param string $order
     *   The sort direction.
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
     * Format a scheduled job for the admin list view.
     *
     * @param ScheduledJob $job
     *   The scheduled job entity to format.
     *
     * @return array<string, mixed>
     *   The lightweight scheduled-job payload returned to the list endpoint.
     */
    private function formatScheduledJobForList(ScheduledJob $job, bool $includeTransactions = false): array
    {
        // Convert timezone for datetime fields
        $cmsTimezone = new \DateTimeZone($this->cmsPreferenceService->getDefaultTimezoneCode());
        $displayConfig = $this->resolveConfigForDisplay($job);

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

        $jobTransactions = $includeTransactions ? $this->getJobTransactions($job->getId()) : [];
        
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
            'email_subject' => $this->extractEmailSubjectForList($displayConfig),
            'config' => $displayConfig,
            'transactions' => $jobTransactions
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
     * Get a scheduled job by id, including display-ready config and metadata.
     *
     * @param int $jobId
     *   The scheduled job id.
     *
     * @return array<string, mixed>
     *   The formatted scheduled-job detail payload.
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
     * Execute a scheduled job immediately from the admin UI.
     *
     * @param int $jobId
     *   The scheduled job id to execute.
     *
     * @return array<string, mixed>|false
     *   The refreshed job detail payload after execution, or `false` on failure.
     */
    public function executeScheduledJob(int $jobId): array|false
    {
        $job = $this->jobSchedulerService->executeJob($jobId, LookupService::TRANSACTION_BY_BY_USER);
        return $job ? $this->formatScheduledJobForDetail($job) : false;
    }

    /**
     * Mark a scheduled job as deleted.
     *
     * @param int $jobId
     *   The scheduled job id to mark deleted.
     *
     * @return bool
     *   `true` when the operation succeeded, otherwise `false`.
     */
    public function deleteScheduledJob(int $jobId): bool
    {
        return $this->jobSchedulerService->deleteJob($jobId, LookupService::TRANSACTION_BY_BY_USER);
    }

    /**
     * Get transactions related to a scheduled job.
     *
     * @param int $jobId
     *   The scheduled job id whose transaction log should be returned.
     *
     * @return array<int, array<string, mixed>>
     *   Formatted transaction entries for the job.
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
     * Format a scheduled job for the admin detail view.
     *
     * @param ScheduledJob $job
     *   The scheduled job entity to format.
     *
     * @return array<string, mixed>
     *   The full scheduled-job detail payload.
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
            'user' => [
                'id' => $job->getUser()?->getId(),
                'email' => $job->getUser()?->getEmail(),
            ],
            'action' => [
                'id' => $job->getAction()?->getId(),
                'name' => $job->getAction()?->getName(),
            ],
            'source' => [
                'data_table_id' => $job->getDataTable()?->getId(),
                'data_table_name' => $job->getDataTable()?->getName(),
                'data_row_id' => $job->getDataRow()?->getId(),
            ],
            'parent_job_id' => $job->getReminderMetadata()?->getParentJob()?->getId(),
            'reminder_data_table_id' => $job->getReminderMetadata()?->getReminderDataTable()?->getId(),
            'reminder_session_start_date' => $job->getReminderMetadata()?->getSessionStartDate()?->format('Y-m-d H:i:s'),
            'reminder_session_end_date' => $job->getReminderMetadata()?->getSessionEndDate()?->format('Y-m-d H:i:s'),
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
            'config' => $this->resolveConfigForDisplay($job)
        ];
    }

    /**
     * Build a display-only scheduled-job config with translated content.
     *
     * The persisted config stored on the job remains unchanged; this method returns
     * a translated copy for admin list/detail rendering.
     *
     * @param ScheduledJob $job
     *   The scheduled job whose config should be resolved for display.
     *
     * @return array<string, mixed>|null
     *   The translated display config, or the original non-array payload.
     */
    private function resolveConfigForDisplay(ScheduledJob $job): ?array
    {
        $config = $job->getConfig();
        if (!is_array($config)) {
            return $config;
        }

        $actionId = $job->getAction()?->getId();
        if (!$actionId) {
            return $config;
        }

        if (isset($config['email']) && is_array($config['email'])) {
            $config['email'] = $this->resolveTranslatedConfigFields(
                $actionId,
                $config['email'],
                ['subject', 'body', 'from_name', 'from_email']
            );
        }

        if (isset($config['notification']) && is_array($config['notification'])) {
            $config['notification'] = $this->resolveTranslatedConfigFields(
                $actionId,
                $config['notification'],
                ['subject', 'body']
            );
        }

        return $config;
    }

    /**
     * Resolve configured translation keys for the specified config fields.
     *
     * @param array<string, mixed> $config
     *   The config subsection containing translatable fields.
     * @param string[] $fields
     *   The field names that should be translated when present.
     *
     * @return array<string, mixed>
     *   The config subsection with translated field values.
     */
    private function resolveTranslatedConfigFields(int $actionId, array $config, array $fields): array
    {
        foreach ($fields as $field) {
            if (!isset($config[$field]) || !is_string($config[$field]) || trim($config[$field]) === '') {
                continue;
            }

            $config[$field] = $this->adminActionTranslationService->resolveTranslationForDefaultLanguage(
                $actionId,
                $config[$field]
            );
        }

        return $config;
    }

    /**
     * Extract the translated subject used by the scheduled-jobs list view.
     *
     * @param array<string, mixed>|null $config
     *   The display-ready scheduled-job config.
     *
     * @return string|null
     *   The translated email or notification subject, or `null` when unavailable.
     */
    private function extractEmailSubjectForList(?array $config): ?string
    {
        if (!is_array($config)) {
            return null;
        }

        $emailSubject = $config['email']['subject'] ?? null;
        if (is_string($emailSubject) && trim($emailSubject) !== '') {
            return $emailSubject;
        }

        $notificationSubject = $config['notification']['subject'] ?? null;
        if (is_string($notificationSubject) && trim($notificationSubject) !== '') {
            return $notificationSubject;
        }

        return null;
    }

}
