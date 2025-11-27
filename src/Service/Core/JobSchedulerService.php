<?php

namespace App\Service\Core;

use App\Entity\Lookup;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Entity\Action;
use App\Entity\MailQueue;
use App\Entity\Notification;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Auth\UserDataService;
use App\Service\Core\TransactionService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service responsible for scheduling and executing jobs with timezone awareness
 *
 * New simplified structure:
 * - Direct user relationships (1-on-1)
 * - Nullable action/datatable relationships for system jobs
 * - JSON-based job configuration
 * - Timezone-aware scheduling with dynamic adjustment
 */
class JobSchedulerService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionService $transactionService,
        private readonly LookupService $lookupService,
        private readonly UserDataService $userDataService,
        private readonly CmsPreferenceService $cmsPreferences,
        private readonly LoggerInterface $logger,
        private readonly CacheService $cache
    ) {
    }

    /**
     * Determine the user associated with a job
     */
    private function getUserForJob(array $jobData): ?User
    {

        // Try to get user from jobData
        if (isset($jobData['user_id'])) {
            return $this->em->getRepository(User::class)->find($jobData['user_id']);
        }

        // For user transactions, try to find user from context or job data
        if (isset($jobData['users']) && is_array($jobData['users']) && !empty($jobData['users'])) {
            return $this->em->getRepository(User::class)->find($jobData['users'][0]);
        }

        // Fallback: return null
        return null;
    }

    /**
     * Schedule a job for a user
     *
     * @param array $jobData Job configuration data
     * @param string $transactionBy Who initiated the transaction
     * @return ScheduledJob|false Job entity if successful, false on failure
     */
    public function scheduleJob(array $jobData, string $transactionBy): ScheduledJob|false
    {
        try {
            // Determine the user for this job
            $user = $this->getUserForJob($jobData);

            $job = $this->createScheduledJob($jobData, $user);
            if (!$job) {
                throw new \Exception('Failed to create scheduled job');
            }

            // Store job-specific configuration
            $this->storeJobConfig($job, $jobData);

            // Log the transaction
            $this->transactionService->logTransaction(
                $this->lookupService::TRANSACTION_TYPES_INSERT,
                $transactionBy,
                'scheduledJobs',
                $job->getId(),
                $job,
                'Job scheduled: ' . ($jobData['description'] ?? $jobData['type'])
            );

            $this->cache
                ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
                ->invalidateAllListsInCategory();

            return $job;

        } catch (\Exception $e) {
            $this->logger->error('Failed to schedule job', [
                'error' => $e->getMessage(),
                'jobData' => $jobData
            ]);
            return false;
        }
    }

    /**
     * Schedule an email validation job for a user
     * 
     * @param int $userId User ID
     * @param string $token Validation token
     * @param array $emailConfig Email configuration
     * @return int|false Job ID if successful, false on failure
     */
    public function scheduleUserValidationEmail(int $userId, string $token, array $emailConfig = []): ScheduledJob|false
    {
        $defaultConfig = [
            'from_email' => $emailConfig['from_email'] ?? 'noreply@example.com',
            'from_name' => $emailConfig['from_name'] ?? 'System',
            'reply_to' => $emailConfig['reply_to'] ?? 'noreply@example.com',
            'subject' => $emailConfig['subject'] ?? 'Account Validation Required',
            'body' => $emailConfig['body'] ?? $this->getDefaultValidationEmailBody($userId, $token),
            'is_html' => $emailConfig['is_html'] ?? true
        ];

        $jobData = [
            'type' => $this->lookupService::JOB_TYPES_EMAIL,
            'description' => 'User account validation email',
            'date_to_be_executed' => new \DateTime('now', new \DateTimeZone('UTC')), // Send immediately
            'users' => [$userId],
            'email_config' => $defaultConfig
        ];

        return $this->scheduleJob($jobData, $this->lookupService::TRANSACTION_BY_BY_SYSTEM);
    }

    /**
     * Execute a scheduled job
     * 
     * @param int $jobId Job ID to execute
     * @param string $transactionBy Who initiated the execution
     * @return bool True if successful, false on failure
     */
    public function executeJob(int $jobId, string $transactionBy): ScheduledJob|false
    {
        try {
            $this->em->beginTransaction();

            $job = $this->em->getRepository(ScheduledJob::class)->find($jobId);
            if (!$job) {
                throw new \Exception('Job not found: ' . $jobId);
            }

            // Set job status to running to prevent double execution
            $runningStatus = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => $this->lookupService::SCHEDULED_JOBS_STATUS,
                'lookupCode' => $this->lookupService::SCHEDULED_JOBS_STATUS_RUNNING
            ]);
            $job->setStatus($runningStatus);
            $this->em->flush();

            // Determine job type and execute accordingly
            $jobTypeName = $job->getJobType()->getLookupCode();

            $success = match ($jobTypeName) {
                $this->lookupService::JOB_TYPES_EMAIL => $this->executeEmailJob($job, $transactionBy),
                $this->lookupService::JOB_TYPES_NOTIFICATION => $this->executeNotificationJob($job, $transactionBy),
                default => throw new \Exception('Unknown job type: ' . $jobTypeName)
            };

            // Update job status to final result
            $finalStatus = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => $this->lookupService::SCHEDULED_JOBS_STATUS,
                'lookupCode' => $success ? $this->lookupService::SCHEDULED_JOBS_STATUS_DONE : $this->lookupService::SCHEDULED_JOBS_STATUS_FAILED
            ]);

            $job->setStatus($finalStatus);
            $job->setDateExecuted(new \DateTime('now', new \DateTimeZone('UTC')));
            $this->em->flush();

            // Log the execution
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                $transactionBy,
                'scheduledJobs',
                $jobId,
                false,
                'Job executed: ' . ($success ? 'executed' : 'failed')
            );

            $this->cache
                ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
                ->invalidateItemAndLists("scheduledJob_{$jobId}");

            $this->cache
                ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SCHEDULED_JOB, $jobId);

            $this->em->commit();
            return $job;

        } catch (\Exception $e) {
            $this->em->rollback();

            // Set job status to failed if something went wrong
            try {
                $failedStatus = $this->em->getRepository(Lookup::class)->findOneBy([
                    'typeCode' => $this->lookupService::SCHEDULED_JOBS_STATUS,
                    'lookupCode' => $this->lookupService::SCHEDULED_JOBS_STATUS_FAILED
                ]);
                $job->setStatus($failedStatus);
                $job->setDateExecuted(new \DateTime('now', new \DateTimeZone('UTC')));
                $this->em->flush();
            } catch (\Exception $e2) {
                $this->logger->error('Failed to set job status to failed', [
                    'jobId' => $jobId,
                    'error' => $e2->getMessage()
                ]);
            }

            $this->logger->error('Failed to execute job', [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Cancel a scheduled job
     *
     * @param int $jobId Job ID to cancel
     * @param string $transactionBy Who initiated the cancellation
     * @return bool True if successful, false on failure
     */
    public function cancelJob(int $jobId, string $transactionBy): bool
    {
        try {
            $this->em->beginTransaction();

            $job = $this->em->getRepository(ScheduledJob::class)->find($jobId);
            if (!$job) {
                throw new \Exception('Job not found: ' . $jobId);
            }

            // Check if job can be cancelled (not already running, done, or failed)
            $currentStatus = $job->getStatus()->getLookupCode();
            if (in_array($currentStatus, [
                $this->lookupService::SCHEDULED_JOBS_STATUS_RUNNING,
                $this->lookupService::SCHEDULED_JOBS_STATUS_DONE,
                $this->lookupService::SCHEDULED_JOBS_STATUS_FAILED
            ])) {
                throw new \Exception('Job cannot be cancelled in current status: ' . $currentStatus);
            }

            // Set status to cancelled
            $cancelledStatus = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => $this->lookupService::SCHEDULED_JOBS_STATUS,
                'lookupCode' => $this->lookupService::SCHEDULED_JOBS_STATUS_CANCELLED
            ]);
            $job->setStatus($cancelledStatus);
            $this->em->flush();

            // Log the cancellation
            $this->transactionService->logTransaction(
                $this->lookupService::TRANSACTION_TYPES_UPDATE,
                $transactionBy,
                'scheduledJobs',
                $jobId,
                $job,
                'Job cancelled by user'
            );

            $this->cache
                ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
                ->invalidateItemAndLists("scheduledJob_{$jobId}");

            $this->cache
                ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SCHEDULED_JOB, $jobId);

            $this->em->commit();
            return true;

        } catch (\Exception $e) {
            $this->em->rollback();
            $this->logger->error('Failed to cancel job', [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete a scheduled job (mark as deleted)
     * 
     * @param int $jobId Job ID to delete
     * @param string $transactionBy Who initiated the deletion
     * @return bool True if successful, false on failure
     */
    public function deleteJob(int $jobId, string $transactionBy): bool
    {
        try {
            $this->em->beginTransaction();

            $job = $this->em->getRepository(ScheduledJob::class)->find($jobId);
            if (!$job) {
                throw new \Exception('Job not found: ' . $jobId);
            }

            $deletedStatus = $this->lookupService->findByTypeAndCode($this->lookupService::SCHEDULED_JOBS_STATUS, $this->lookupService::SCHEDULED_JOBS_STATUS_DELETED);
            $job->setStatus($deletedStatus);
            $this->em->flush();

            $this->transactionService->logTransaction(
                'delete',
                $transactionBy,
                'scheduledJobs',
                $jobId,
                false,
                'Job marked as deleted'
            );

            $this->cache
                ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
                ->invalidateItemAndLists("scheduledJob_{$jobId}");

            $this->cache
                ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SCHEDULED_JOB, $jobId);

            $this->em->commit();
            return true;

        } catch (\Exception $e) {
            $this->em->rollback();
            $this->logger->error('Failed to delete job', [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Schedule an email job directly (public method)
     * 
     * @param array $emailConfig Email configuration
     * @param \DateTime|null $dateToExecute When to execute the job (default: now)
     * @param int|null $userId Optional user ID to associate with the job
     * @return int|false Job ID if successful, false on failure
     */
    public function scheduleDirectEmailJob(
        array $emailConfig,
        ?\DateTime $dateToExecute = null,
        ?int $userId = null
    ): int|false {
        $jobData = [
            'type' => $this->lookupService::JOB_TYPES_EMAIL,
            'description' => $emailConfig['subject'] ?? 'Email job',
            'date_to_be_executed' => $dateToExecute ?? new \DateTime('now', new \DateTimeZone('UTC')),
            'email_config' => $emailConfig
        ];

        if ($userId) {
            $jobData['users'] = [$userId];
        }

        $job =  $this->scheduleJob($jobData, $this->lookupService::TRANSACTION_BY_BY_SYSTEM);

        return $job ? $job->getId() : false;
    }

    /**
     * Create the base scheduled job entry
     */
    /**
     * Create a scheduled job with timezone handling
     */
    private function createScheduledJob(array $jobData, ?User $user): ScheduledJob|false
    {
        try {
            // Get job type using constants
            $jobType = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => $this->lookupService::JOB_TYPES,
                'lookupCode' => $jobData['type']
            ]);

            if (!$jobType) {
                throw new \Exception('Invalid job type: ' . $jobData['type']);
            }

            // Get status using constants
            $status = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => $this->lookupService::SCHEDULED_JOBS_STATUS,
                'lookupCode' => $this->lookupService::SCHEDULED_JOBS_STATUS_QUEUED
            ]);

            $scheduledJob = new ScheduledJob();
            $scheduledJob->setUser($user);
            $scheduledJob->setJobType($jobType);
            $scheduledJob->setStatus($status);
            $scheduledJob->setDescription($jobData['description'] ?? '');
            $scheduledJob->setDateToBeExecuted($jobData['date_to_be_executed'] ?? new \DateTime('now', new \DateTimeZone('UTC')));

            // Set relationships if provided (nullable for system jobs)
            if (isset($jobData['action']) && $jobData['action'] instanceof Action) {
                $action = $jobData['action'];
                $scheduledJob->setAction($action);
                $scheduledJob->setDataTable($action->getDataTable());
                $scheduledJob->setDataRow($jobData['dataRow'] ?? null);
            }

            $this->em->persist($scheduledJob);
            $this->em->flush();

            return $scheduledJob;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create scheduled job', ['error' => $e->getMessage()]);
            return false;
        }
    }


    /**
     * Store job-specific configuration in JSON format
     */
    private function storeJobConfig(ScheduledJob $job, array $jobData): void
    {
        $config = [];

        // Store job-specific configuration based on type
        switch ($job->getJobType()->getLookupCode()) {
            case $this->lookupService::JOB_TYPES_EMAIL:
                $config['email'] = $jobData['email_config'] ?? [];
                break;

            case $this->lookupService::JOB_TYPES_NOTIFICATION:
                $config['notification'] = $jobData['notification_config'] ?? [];
                break;

            case 'reminder':
                $config['reminder'] = [
                    'session_start_date_utc' => ($jobData['session_start'] ?? new \DateTime('now', new \DateTimeZone('UTC')))->format('c'),
                    'session_end_date_utc' => ($jobData['session_end'] ?? new \DateTime('now', new \DateTimeZone('UTC')))->format('c'),
                    'reminder_offset_minutes' => $jobData['reminder_offset_minutes'] ?? 15
                ];
                break;
        }

        $job->setConfig($config);
        $this->em->flush();
    }

    /**
     * Schedule an email job
     */
    private function scheduleEmailJob(ScheduledJob $job, array $jobData): bool
    {
        try {
            $emailConfig = $jobData['email_config'];

            $mailQueue = new MailQueue();
            $mailQueue->setFromEmail($emailConfig['from_email']);
            $mailQueue->setFromName($emailConfig['from_name']);
            $mailQueue->setReplyTo($emailConfig['reply_to']);
            $mailQueue->setRecipientEmails($emailConfig['recipient_emails'] ?? '');
            $mailQueue->setSubject($emailConfig['subject']);
            $mailQueue->setBody($emailConfig['body']);
            $mailQueue->setIsHtml($emailConfig['is_html'] ?? true);

            if (isset($emailConfig['cc_emails'])) {
                $mailQueue->setCcEmails($emailConfig['cc_emails']);
            }
            if (isset($emailConfig['bcc_emails'])) {
                $mailQueue->setBccEmails($emailConfig['bcc_emails']);
            }

            $this->em->persist($mailQueue);

            $this->em->flush();

            // Link scheduled job to mail queue
            $scheduledJobMailQueue = new ScheduledJobsMailQueue();
            $scheduledJobMailQueue->setScheduledJob($job);
            $scheduledJobMailQueue->setMailQueue($mailQueue);

            $this->em->persist($scheduledJobMailQueue);

            $this->em->flush();

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to schedule email job', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Schedule a notification job
     */
    private function scheduleNotificationJob(int $jobId, array $jobData): bool
    {
        try {
            $notificationConfig = $jobData['notification_config'];

            $notification = new Notification();
            $notification->setSubject($notificationConfig['subject']);
            $notification->setBody($notificationConfig['body']);

            if (isset($notificationConfig['url'])) {
                $notification->setUrl($notificationConfig['url']);
            }

            $this->em->persist($notification);

            $this->em->flush();

            // Link scheduled job to notification
            $scheduledJobNotification = new ScheduledJobsNotification();
            $job = $this->em->getRepository(ScheduledJob::class)->find($jobId);
            $scheduledJobNotification->setScheduledJob($job);
            $scheduledJobNotification->setNotification($notification);

            $this->em->persist($scheduledJobNotification);

            $this->em->flush();

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Failed to schedule notification job', ['error' => $e->getMessage()]);
            return false;
        }
    }



    /**
     * Execute an email job
     */
    private function executeEmailJob(ScheduledJob $job, string $transactionBy): bool
    {
        // TODO: Implement email sending logic
        // This will be implemented in a separate EmailService
        $this->logger->info('Email job execution not yet implemented', ['jobId' => $job->getId()]);
        return true;
    }

    /**
     * Execute a notification job
     */
    private function executeNotificationJob(ScheduledJob $job, string $transactionBy): bool
    {
        // TODO: Implement push notification sending logic
        // This will be implemented in a separate NotificationService
        $this->logger->info('Notification job execution not yet implemented', ['jobId' => $job->getId()]);
        return true;
    }


    /**
     * Get default validation email body
     */
    private function getDefaultValidationEmailBody(int $userId, string $token): string
    {
        $validationUrl = "validate/{$userId}/{$token}";

        return "
        <h2>Account Validation Required</h2>
        <p>Thank you for registering! Please click the link below to validate your account:</p>
        <p><a href=\"{$validationUrl}\">{$validationUrl}</a></p>
        <p>If you did not create this account, please ignore this email.</p>
        ";
    }
}