<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\Core;

use App\Entity\Action;
use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Entity\Lookup;
use App\Entity\ScheduledJob;
use App\Entity\ScheduledJobReminder;
use App\Entity\User;
use App\Plugin\ScheduledJob\PluginScheduledJobRegistry;
use App\Service\Auth\MailTemplateDefaults;
use App\Service\Auth\UserDataService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\CmsPreferenceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

/**
 * Central service for creating, persisting, and executing scheduled jobs.
 *
 * This service acts as the single execution backbone for legacy-equivalent action
 * jobs, user-validation emails, direct emails, notifications, task jobs, and
 * reminder metadata persistence.
 */
class JobSchedulerService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionService $transactionService,
        private readonly LookupService $lookupService,
        private readonly UserDataService $userDataService,
        private readonly CmsPreferenceService $cmsPreferences,
        private readonly ConditionService $conditionService,
        private readonly TaskJobExecutorService $taskJobExecutorService,
        private readonly LoggerInterface $logger,
        private readonly CacheService $cache,
        private readonly MailerInterface $mailer,
        private readonly PluginScheduledJobRegistry $pluginScheduledJobs,
    ) {
    }

    /**
     * Resolve the primary user entity associated with a scheduling payload.
     *
     * @param array<string, mixed> $jobData
     *   The job payload passed into the scheduler.
     *
     * @return User|null
     *   The resolved user entity or `null` for system-scoped jobs.
     */
    private function getUserForJob(array $jobData): ?User
    {
        if (isset($jobData['user_id'])) {
            return $this->em->getRepository(User::class)->find($jobData['user_id']);
        }

        if (isset($jobData['users']) && is_array($jobData['users']) && $jobData['users'] !== []) {
            return $this->em->getRepository(User::class)->find($jobData['users'][0]);
        }

        return null;
    }

    /**
     * Persist a scheduled job and its structured config payload.
     *
     * @param array<string, mixed> $jobData
     *   The normalized job payload to schedule.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return ScheduledJob|false
     *   The created scheduled job or `false` when scheduling fails.
     */
    public function scheduleJob(array $jobData, string $transactionBy): ScheduledJob|false
    {
        try {
            $user = $this->getUserForJob($jobData);

            $job = $this->createScheduledJob($jobData, $user);

            if (!$job) {
                throw new \RuntimeException('Failed to create scheduled job');
            }

            $this->storeJobConfig($job, $jobData);

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_INSERT,
                $transactionBy,
                'scheduled_jobs',
                $job->getId(),
                $job,
                'Job scheduled: ' . ($jobData['description'] ?? $jobData['type'])
            );

            $this->cache
                ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
                ->invalidateAllListsInCategory();

            return $job;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to schedule job', [
                'error' => $e->getMessage(),
                'jobData' => $jobData,
            ]);

            return false;
        }
    }

    /**
     * Schedule the built-in user-validation email job.
     *
     * @param int $userId
     *   The user that should receive the validation email.
     * @param string $token
     *   The validation token embedded in the email body.
     * @param array<string, mixed> $emailConfig
     *   Optional overrides for the default email payload.
     *
     * @return ScheduledJob|false
     *   The created scheduled job or `false` when scheduling fails.
     */
    public function scheduleUserValidationEmail(int $userId, string $token, array $emailConfig = []): ScheduledJob|false
    {
        $defaultConfig = array_merge(
            [
                'from_email'       => MailTemplateDefaults::FROM_EMAIL,
                'from_name'        => MailTemplateDefaults::FROM_NAME,
                'reply_to'         => MailTemplateDefaults::REPLY_TO,
                'recipient_emails' => null,
                'subject'          => MailTemplateDefaults::getSubject(MailTemplateDefaults::TYPE_CONFIRM, 'en-GB'),
                'body'             => MailTemplateDefaults::getBody(MailTemplateDefaults::TYPE_CONFIRM, 'en-GB'),
                'is_html'          => MailTemplateDefaults::IS_HTML,
                'attachments'      => [],
            ],
            $emailConfig
        );

        $jobData = [
            'type' => LookupService::JOB_TYPES_EMAIL,
            'description' => 'User account validation email',
            'date_to_be_executed' => new \DateTime('now', new \DateTimeZone('UTC')),
            'users' => [$userId],
            'email_config' => $defaultConfig,
        ];

        return $this->scheduleJob($jobData, LookupService::TRANSACTION_BY_BY_SYSTEM);
    }

    /**
     * Execute a scheduled job by id and update its execution status.
     *
     * @param int $jobId
     *   The scheduled job id.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return ScheduledJob|false
     *   The updated job entity after execution, or `false` on failure.
     */
    public function executeJob(int $jobId, string $transactionBy): ScheduledJob|false
    {
        try {
            $this->em->beginTransaction();

            $job = $this->em->getRepository(ScheduledJob::class)->find($jobId);
            if (!$job) {
                throw new \RuntimeException('Job not found: ' . $jobId);
            }

            $runningStatus = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => LookupService::SCHEDULED_JOBS_STATUS,
                'lookupCode' => LookupService::SCHEDULED_JOBS_STATUS_RUNNING,
            ]);
            $job->setStatus($runningStatus);
            $this->em->flush();

            $success = $this->canExecuteJob($job)
                ? $this->executeByType($job, $transactionBy)
                : false;

            $finalStatus = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => LookupService::SCHEDULED_JOBS_STATUS,
                'lookupCode' => $success ? LookupService::SCHEDULED_JOBS_STATUS_DONE : LookupService::SCHEDULED_JOBS_STATUS_FAILED,
            ]);

            $job->setStatus($finalStatus);
            $job->setDateExecuted(new \DateTime('now', new \DateTimeZone('UTC')));
            $this->em->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                $transactionBy,
                'scheduled_jobs',
                $jobId,
                false,
                'Job executed: ' . ($success ? 'executed' : 'failed')
            );

            $this->invalidateJobCache($jobId);
            $this->em->commit();

            return $job;
        } catch (\Throwable $e) {
            $this->em->rollback();

            try {
                $job = $this->em->getRepository(ScheduledJob::class)->find($jobId);
                if ($job) {
                    $failedStatus = $this->em->getRepository(Lookup::class)->findOneBy([
                        'typeCode' => LookupService::SCHEDULED_JOBS_STATUS,
                        'lookupCode' => LookupService::SCHEDULED_JOBS_STATUS_FAILED,
                    ]);
                    $job->setStatus($failedStatus);
                    $job->setDateExecuted(new \DateTime('now', new \DateTimeZone('UTC')));
                    $this->em->flush();
                }
            } catch (\Throwable $statusError) {
                $this->logger->error('Failed to update job status after execution error', [
                    'jobId' => $jobId,
                    'error' => $statusError->getMessage(),
                ]);
            }

            $this->logger->error('Failed to execute job', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Cancel a scheduled job when it has not started execution yet.
     *
     * @param int $jobId
     *   The scheduled job id.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return bool
     *   `true` on success, otherwise `false`.
     */
    public function cancelJob(int $jobId, string $transactionBy): bool
    {
        try {
            $this->em->beginTransaction();

            $job = $this->em->getRepository(ScheduledJob::class)->find($jobId);
            if (!$job) {
                throw new \RuntimeException('Job not found: ' . $jobId);
            }

            $currentStatus = $job->getStatus()->getLookupCode();
            if (in_array($currentStatus, [
                LookupService::SCHEDULED_JOBS_STATUS_RUNNING,
                LookupService::SCHEDULED_JOBS_STATUS_DONE,
                LookupService::SCHEDULED_JOBS_STATUS_FAILED,
            ], true)) {
                throw new \RuntimeException('Job cannot be cancelled in current status: ' . $currentStatus);
            }

            $cancelledStatus = $this->lookupService->findByTypeAndCode(
                LookupService::SCHEDULED_JOBS_STATUS,
                LookupService::SCHEDULED_JOBS_STATUS_CANCELLED
            );
            $job->setStatus($cancelledStatus);
            $this->em->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                $transactionBy,
                'scheduled_jobs',
                $jobId,
                $job,
                'Job cancelled by user'
            );

            $this->invalidateJobCache($jobId);
            $this->em->commit();

            return true;
        } catch (\Throwable $e) {
            $this->em->rollback();
            $this->logger->error('Failed to cancel job', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Mark a scheduled job as deleted without physically removing it.
     *
     * @param int $jobId
     *   The scheduled job id.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return bool
     *   `true` on success, otherwise `false`.
     */
    public function deleteJob(int $jobId, string $transactionBy): bool
    {
        try {
            $this->em->beginTransaction();

            $job = $this->em->getRepository(ScheduledJob::class)->find($jobId);
            if (!$job) {
                throw new \RuntimeException('Job not found: ' . $jobId);
            }

            $deletedStatus = $this->lookupService->findByTypeAndCode(
                LookupService::SCHEDULED_JOBS_STATUS,
                LookupService::SCHEDULED_JOBS_STATUS_DELETED
            );
            $job->setStatus($deletedStatus);
            $this->em->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                $transactionBy,
                'scheduled_jobs',
                $jobId,
                false,
                'Job marked as deleted'
            );

            $this->invalidateJobCache($jobId);
            $this->em->commit();

            return true;
        } catch (\Throwable $e) {
            $this->em->rollback();
            $this->logger->error('Failed to delete job', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Schedule a standalone email job outside of the action runtime.
     *
     * @param array<string, mixed> $emailConfig
     *   The email payload to persist on the job.
     * @param \DateTimeInterface|null $dateToExecute
     *   Optional execution date; defaults to now (UTC).
     * @param int|null $userId
     *   Optional user id owning the email job.
     *
     * @return int|false
     *   The scheduled job id or `false` when scheduling fails.
     */
    public function scheduleDirectEmailJob(
        array $emailConfig,
        ?\DateTimeInterface $dateToExecute = null,
        ?int $userId = null
    ): int|false {
        $jobData = [
            'type' => LookupService::JOB_TYPES_EMAIL,
            'description' => $emailConfig['subject'] ?? 'Email job',
            'date_to_be_executed' => $dateToExecute ?? new \DateTime('now', new \DateTimeZone('UTC')),
            'email_config' => $emailConfig,
        ];

        if ($userId) {
            $jobData['users'] = [$userId];
        }

        $job = $this->scheduleJob($jobData, LookupService::TRANSACTION_BY_BY_SYSTEM);

        return $job ? $job->getId() : false;
    }

    /**
     * Create the base scheduled-job entity from normalized job data.
     *
     * @param array<string, mixed> $jobData
     *   The normalized job payload to persist.
     * @param User|null $user
     *   The resolved user entity, if any.
     *
     * @return ScheduledJob|false
     *   The persisted job or `false` when creation fails.
     */
    private function createScheduledJob(array $jobData, ?User $user): ScheduledJob|false
    {
        try {
            $jobType = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => LookupService::JOB_TYPES,
                'lookupCode' => $jobData['type'],
            ]);

            if (!$jobType) {
                throw new \RuntimeException('Invalid job type: ' . $jobData['type']);
            }

            $status = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => LookupService::SCHEDULED_JOBS_STATUS,
                'lookupCode' => LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            ]);

            $scheduledJob = new ScheduledJob();
            $scheduledJob->setUser($user);
            $scheduledJob->setJobType($jobType);
            $scheduledJob->setStatus($status);
            $scheduledJob->setDescription($jobData['description'] ?? '');
            $scheduledJob->setDateToBeExecuted($jobData['date_to_be_executed'] ?? new \DateTime('now', new \DateTimeZone('UTC')));

            if (isset($jobData['action']) && $jobData['action'] instanceof Action) {
                $scheduledJob->setAction($jobData['action']);
                $scheduledJob->setDataTable($jobData['action']->getDataTable());
            }

            if (isset($jobData['dataTable']) && $jobData['dataTable'] instanceof DataTable) {
                $scheduledJob->setDataTable($jobData['dataTable']);
            }

            if (isset($jobData['dataRow']) && $jobData['dataRow'] instanceof DataRow) {
                $scheduledJob->setDataRow($jobData['dataRow']);
            }

            $this->em->persist($scheduledJob);
            $this->em->flush();

            return $scheduledJob;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create scheduled job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Persist structured config sections and reminder metadata for a scheduled job.
     *
     * @param ScheduledJob $job
     *   The scheduled job being updated.
     * @param array<string, mixed> $jobData
     *   The normalized job payload used to derive stored config.
     */
    private function storeJobConfig(ScheduledJob $job, array $jobData): void
    {
        $config = [];

        switch ($job->getJobType()->getLookupCode()) {
            case LookupService::JOB_TYPES_EMAIL:
                $config['email'] = $jobData['email_config'] ?? [];
                break;
            case LookupService::JOB_TYPES_NOTIFICATION:
                $config['notification'] = $jobData['notification_config'] ?? [];
                break;
            case LookupService::JOB_TYPES_TASK:
                $config['task'] = $jobData['task_config'] ?? [];
                break;
        }

        if (array_key_exists('condition', $jobData)) {
            $config['condition'] = $jobData['condition'];
        }

        if (array_key_exists('schedule', $jobData)) {
            $config['schedule'] = $jobData['schedule'];
        }

        if (array_key_exists('action_job_type', $jobData)) {
            $config['action_job_type'] = $jobData['action_job_type'];
        }

        $job->setConfig($config);
        $this->storeReminderMetadata($job, $jobData);
        $this->em->flush();
    }

    /**
     * Persist reminder-only metadata in the dedicated reminder entity when present.
     *
     * @param ScheduledJob $job
     *   The reminder job that may own reminder metadata.
     * @param array<string, mixed> $jobData
     *   The normalized job payload carrying reminder lineage/window data.
     */
    private function storeReminderMetadata(ScheduledJob $job, array $jobData): void
    {
        $hasReminderMetadata =
            (isset($jobData['parent_job']) && $jobData['parent_job'] instanceof ScheduledJob) ||
            (isset($jobData['reminder_data_table']) && $jobData['reminder_data_table'] instanceof DataTable) ||
            (isset($jobData['reminder_session_start']) && $jobData['reminder_session_start'] instanceof \DateTimeInterface) ||
            (isset($jobData['reminder_session_end']) && $jobData['reminder_session_end'] instanceof \DateTimeInterface);

        if (!$hasReminderMetadata) {
            return;
        }

        $metadata = $job->getReminderMetadata() ?? new ScheduledJobReminder();
        $metadata->setScheduledJob($job);

        if (isset($jobData['parent_job']) && $jobData['parent_job'] instanceof ScheduledJob) {
            $metadata->setParentJob($jobData['parent_job']);
        }

        if (isset($jobData['reminder_data_table']) && $jobData['reminder_data_table'] instanceof DataTable) {
            $metadata->setReminderDataTable($jobData['reminder_data_table']);
        }

        if (isset($jobData['reminder_session_start']) && $jobData['reminder_session_start'] instanceof \DateTimeInterface) {
            $metadata->setSessionStartDate($jobData['reminder_session_start']);
        }

        if (isset($jobData['reminder_session_end']) && $jobData['reminder_session_end'] instanceof \DateTimeInterface) {
            $metadata->setSessionEndDate($jobData['reminder_session_end']);
        }

        $job->setReminderMetadata($metadata);
        $this->em->persist($metadata);
    }

    /**
     * Check whether a scheduled job is allowed to execute based on its stored condition.
     *
     * @param ScheduledJob $job
     *   The scheduled job being evaluated.
     *
     * @return bool
     *   `true` when no condition exists or the condition evaluates truthy.
     */
    private function canExecuteJob(ScheduledJob $job): bool
    {
        $condition = $job->getConfig()['condition'] ?? null;
        if ($condition === null || $condition === '') {
            return true;
        }

        return (bool) ($this->conditionService->evaluateCondition(
            $condition,
            $job->getUser()?->getId(),
            'scheduled-job'
        )['result'] ?? false);
    }

    /**
     * Dispatch execution to the job-type specific executor.
     *
     * @param ScheduledJob $job
     *   The scheduled job being executed.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return bool
     *   `true` when execution succeeded, otherwise `false`.
     */
    private function executeByType(ScheduledJob $job, string $transactionBy): bool
    {
        $jobType = (string) $job->getJobType()->getLookupCode();
        // Plugin-contributed job types take precedence so plugins can
        // shadow a core type when they need to (rare but allowed —
        // e.g. a survey plugin wrapping `email` with extra
        // pre/post hooks). The registry is built from services tagged
        // `selfhelp.plugin.scheduled_job_handler` so it stays empty on
        // hosts that have no plugin contributions.
        $pluginResult = $this->pluginScheduledJobs->execute($jobType, $job, $transactionBy);
        if ($pluginResult !== null) {
            return $pluginResult;
        }

        return match ($jobType) {
            LookupService::JOB_TYPES_EMAIL => $this->executeEmailJob($job, $transactionBy),
            LookupService::JOB_TYPES_NOTIFICATION => $this->executeNotificationJob($job, $transactionBy),
            LookupService::JOB_TYPES_TASK => $this->executeTaskJob($job, $transactionBy),
            default => false,
        };
    }

    /**
     * Execute a scheduled email job using the stored email config.
     *
     * @param ScheduledJob $job
     *   The scheduled email job being executed.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return bool
     *   `true` when the email send reports success, otherwise `false`.
     */
    private function executeEmailJob(ScheduledJob $job, string $transactionBy): bool
    {
        $emailConfig = $job->getConfig()['email'] ?? [];
        $recipients = trim((string) ($emailConfig['recipient_emails'] ?? $job->getUser()?->getEmail() ?? ''));
        if ($recipients === '') {
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_SEND_MAIL_FAIL,
                $transactionBy,
                'scheduled_jobs',
                $job->getId(),
                false,
                'No email recipients were resolved for the scheduled job'
            );
            return false;
        }

        $subject = (string) ($emailConfig['subject'] ?? '');
        $body = (string) ($emailConfig['body'] ?? '');
        $fromEmail = (string) ($emailConfig['from_email'] ?? MailTemplateDefaults::FROM_EMAIL);
        $fromName = (string) ($emailConfig['from_name'] ?? MailTemplateDefaults::FROM_NAME);
        $replyTo = (string) ($emailConfig['reply_to'] ?? $fromEmail);
        $isHtml = (bool) ($emailConfig['is_html'] ?? MailTemplateDefaults::IS_HTML);
        $attachments = is_array($emailConfig['attachments'] ?? null) ? $emailConfig['attachments'] : [];

        try {
            $email = (new Email())
                ->from(new Address($fromEmail, $fromName))
                ->replyTo($replyTo)
                ->to($recipients)
                ->subject($subject);

            $isHtml ? $email->html($body) : $email->text($body);

            foreach ($attachments as $attachment) {
                $path = $attachment['path'] ?? '';
                if (is_string($path) && $path !== '' && is_file($path)) {
                    $email->attachFromPath($path, $attachment['filename'] ?? null);
                }
            }

            $this->mailer->send($email);
            $success = true;
        } catch (\Throwable $e) {
            $this->logger->error('Mailer send failed', [
                'jobId' => $job->getId(),
                'error' => $e->getMessage(),
            ]);
            $success = false;
        }

        $this->transactionService->logTransaction(
            $success ? LookupService::TRANSACTION_TYPES_SEND_MAIL_OK : LookupService::TRANSACTION_TYPES_SEND_MAIL_FAIL,
            $transactionBy,
            'scheduled_jobs',
            $job->getId(),
            false,
            sprintf('Email %s to %s', $success ? 'sent' : 'failed', $recipients)
        );

        return $success;
    }

    /**
     * Execute a scheduled push-notification job using Firebase HTTP v1.
     *
     * @param ScheduledJob $job
     *   The scheduled notification job being executed.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return bool
     *   `true` when the notification request succeeds, otherwise `false`.
     */
    private function executeNotificationJob(ScheduledJob $job, string $transactionBy): bool
    {
        $notificationConfig = $job->getConfig()['notification'] ?? [];
        $user = $job->getUser();

        if (!$user || !$user->getDeviceToken()) {
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_SEND_NOTIFICATION_FAIL,
                $transactionBy,
                'scheduled_jobs',
                $job->getId(),
                false,
                'Notification failed because the user does not have a device token'
            );
            return false;
        }

        $firebaseConfig = $this->cmsPreferences->getFirebaseConfig();
        if (!$firebaseConfig) {
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_SEND_NOTIFICATION_FAIL,
                $transactionBy,
                'scheduled_jobs',
                $job->getId(),
                false,
                'Notification failed because Firebase config is not available'
            );
            return false;
        }

        $serviceAccount = $this->decodeFirebaseServiceAccount($firebaseConfig);
        if ($serviceAccount === null) {
            return false;
        }

        $accessToken = $this->createFirebaseAccessToken($serviceAccount);
        if ($accessToken === null) {
            return false;
        }

        $payload = [
            'message' => [
                'token' => $user->getDeviceToken(),
                'notification' => [
                    'title' => (string) ($notificationConfig['subject'] ?? ''),
                    'body' => (string) ($notificationConfig['body'] ?? ''),
                ],
                'data' => [
                    'url' => (string) ($notificationConfig['url'] ?? ''),
                ],
            ],
        ];

        $response = $this->postJson(
            sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $serviceAccount['project_id']),
            $payload,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );

        $result = $response['status'] >= 200 && $response['status'] < 300;

        $this->transactionService->logTransaction(
            $result ? LookupService::TRANSACTION_TYPES_SEND_NOTIFICATION_OK : LookupService::TRANSACTION_TYPES_SEND_NOTIFICATION_FAIL,
            $transactionBy,
            'scheduled_jobs',
            $job->getId(),
            false,
            sprintf('Push notification %s for user %d', $result ? 'sent' : 'failed', $user->getId())
        );

        return $result;
    }

    /**
     * Execute a scheduled task job such as add/remove group membership.
     *
     * @param ScheduledJob $job
     *   The scheduled task job being executed.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return bool
     *   `true` when task execution succeeds, otherwise `false`.
     */
    private function executeTaskJob(ScheduledJob $job, string $transactionBy): bool
    {
        return $this->taskJobExecutorService->execute($job, $transactionBy);
    }

    /**
     * @return array<string, mixed>|null
     *   Decoded Firebase service-account config or `null` when invalid.
     */
    private function decodeFirebaseServiceAccount(string $firebaseConfig): ?array
    {
        $configString = is_file($firebaseConfig) ? (string) file_get_contents($firebaseConfig) : $firebaseConfig;
        $decoded = json_decode($configString, true);

        if (!is_array($decoded) || !isset($decoded['project_id'], $decoded['client_email'], $decoded['private_key'])) {
            $this->logger->error('Invalid Firebase configuration for notification job');
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $serviceAccount
     *   The decoded Firebase service-account config.
     *
     * @return string|null
     *   An OAuth access token for Firebase Messaging, or `null` on failure.
     */
    private function createFirebaseAccessToken(array $serviceAccount): ?string
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ]));

        $unsignedJwt = $header . '.' . $payload;
        $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        if ($privateKey === false) {
            $this->logger->error('Failed to load Firebase private key');
            return null;
        }

        $signature = '';
        if (!openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            $this->logger->error('Failed to sign Firebase JWT');
            return null;
        }

        $assertion = $unsignedJwt . '.' . $this->base64UrlEncode($signature);
        $response = $this->postForm('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            $this->logger->error('Failed to exchange Firebase JWT for access token', ['response' => $response['body']]);
            return null;
        }

        return (string) $decoded['access_token'];
    }

    /**
     * Encode binary data using base64url formatting required by JWT.
     *
     * @param string $value
     *   The raw value to encode.
     *
     * @return string
     *   The base64url-encoded representation.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string, string> $data
     *   Form fields to send.
     * @return array{status: int, body: string}
     *   HTTP status and response body.
     */
    private function postForm(string $url, array $data): array
    {
        return $this->sendHttpRequest($url, http_build_query($data), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *   JSON payload to send.
     * @param string[] $headers
     *   HTTP headers to include with the request.
     * @return array{status: int, body: string}
     *   HTTP status and response body.
     */
    private function postJson(string $url, array $payload, array $headers): array
    {
        return $this->sendHttpRequest($url, (string) json_encode($payload), $headers);
    }

    /**
     * @param string[] $headers
     *   HTTP headers to include with the request.
     * @return array{status: int, body: string}
     *   HTTP status and response body.
     */
    private function sendHttpRequest(string $url, string $body, array $headers): array
    {
        $status = 0;
        $responseBody = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $responseBody = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return ['status' => $status, 'body' => $responseBody];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);
        $responseBody = (string) @file_get_contents($url, false, $context);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int) $matches[1];
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    /**
     * Invalidate scheduled-job cache entries after a state change.
     *
     * @param int $jobId
     *   The scheduled job id whose cache should be invalidated.
     */
    private function invalidateJobCache(int $jobId): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
            ->invalidateItemAndLists("scheduledJob_{$jobId}");

        $this->cache
            ->withCategory(CacheService::CATEGORY_SCHEDULED_JOBS)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_SCHEDULED_JOB, $jobId);
    }

}
