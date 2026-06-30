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
use App\Repository\ScheduledJobRepository;
use App\Entity\ScheduledJobRecipient;
use App\Entity\ScheduledJobReminder;
use App\Entity\User;
use App\Plugin\ScheduledJob\PluginScheduledJobDeliveryAwareInterface;
use App\Plugin\ScheduledJob\PluginScheduledJobDeliveryGate;
use App\Plugin\ScheduledJob\PluginScheduledJobRegistry;
use App\Service\Auth\MailHtmlRenderer;
use App\Service\Auth\MailTemplateDefaults;
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
        private readonly CmsPreferenceService $cmsPreferences,
        private readonly ConditionService $conditionService,
        private readonly TaskJobExecutorService $taskJobExecutorService,
        private readonly LoggerInterface $logger,
        private readonly CacheService $cache,
        private readonly MailerInterface $mailer,
        private readonly PluginScheduledJobRegistry $pluginScheduledJobs,
        private readonly PluginScheduledJobDeliveryGate $pluginDeliveryGate,
        private readonly ScheduledJobRepository $scheduledJobRepository,
        private readonly MailHtmlRenderer $mailHtmlRenderer,
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
                'Job scheduled: ' . $this->asString($jobData['description'] ?? $jobData['type'] ?? '')
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
                // Account validation must always be delivered (issue #29).
                'delivery_policy'  => LookupService::SCHEDULED_JOB_DELIVERY_POLICY_REQUIRED_SYSTEM,
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

            // Atomically claim the job (queued -> running, guarded by current
            // status) so overlapping scheduler ticks or manual triggers can never
            // execute the same job twice. A lost claim is not an error.
            $claimed = $this->scheduledJobRepository
                ->claimQueuedJobForExecution($jobId, new \DateTime('now', new \DateTimeZone('UTC')));
            if (!$claimed) {
                $this->em->rollback();
                $this->logger->info('Scheduled job already claimed or not queued; skipping execution', [
                    'jobId' => $jobId,
                ]);
                return false;
            }
            // Reflect the claimed running state + date_started in the managed entity.
            $this->em->refresh($job);

            $result = $this->canExecuteJob($job)
                ? $this->executeByType($job, $transactionBy)
                : ScheduledJobExecutionResult::failed('Job condition not met');

            $finalStatus = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => LookupService::SCHEDULED_JOBS_STATUS,
                'lookupCode' => $result->getFinalStatusCode(),
            ]);
            if ($finalStatus === null) {
                throw new \RuntimeException('Missing scheduled-job status lookup: ' . $result->getFinalStatusCode());
            }

            // All terminal statuses (done, failed, skipped) record an execution time.
            $job->setStatus($finalStatus);
            $job->setDateExecuted(new \DateTime('now', new \DateTimeZone('UTC')));
            $this->em->flush();

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                $transactionBy,
                'scheduled_jobs',
                $jobId,
                false,
                'Job executed: ' . $result->getFinalStatusCode()
                . ($result->getMessage() !== '' ? ' (' . $result->getMessage() . ')' : '')
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
                    if ($failedStatus !== null) {
                        $job->setStatus($failedStatus);
                        $job->setDateExecuted(new \DateTime('now', new \DateTimeZone('UTC')));
                        $this->em->flush();
                    }
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
            if ($cancelledStatus === null) {
                throw new \RuntimeException('Missing scheduled-job status lookup: ' . LookupService::SCHEDULED_JOBS_STATUS_CANCELLED);
            }
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
            if ($deletedStatus === null) {
                throw new \RuntimeException('Missing scheduled-job status lookup: ' . LookupService::SCHEDULED_JOBS_STATUS_DELETED);
            }
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

        return $job ? (int) $job->getId() : false;
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
                throw new \RuntimeException('Invalid job type: ' . $this->asString($jobData['type'] ?? ''));
            }

            $status = $this->em->getRepository(Lookup::class)->findOneBy([
                'typeCode' => LookupService::SCHEDULED_JOBS_STATUS,
                'lookupCode' => LookupService::SCHEDULED_JOBS_STATUS_QUEUED,
            ]);
            if ($status === null) {
                throw new \RuntimeException('Missing scheduled-job status lookup: ' . LookupService::SCHEDULED_JOBS_STATUS_QUEUED);
            }

            $dateToBeExecuted = $jobData['date_to_be_executed'] ?? null;

            $scheduledJob = new ScheduledJob();
            $scheduledJob->setUser($user);
            $scheduledJob->setJobType($jobType);
            $scheduledJob->setStatus($status);
            $scheduledJob->setDescription($this->asString($jobData['description'] ?? ''));
            $scheduledJob->setDateToBeExecuted(
                $dateToBeExecuted instanceof \DateTimeInterface
                    ? $dateToBeExecuted
                    : new \DateTime('now', new \DateTimeZone('UTC'))
            );

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
        $this->storeRecipients($job, $jobData);
        $this->em->flush();
    }

    /**
     * Persist per-delivery recipient snapshots for a scheduled job.
     *
     * When the caller supplies an explicit `recipients` list it is persisted
     * verbatim. Otherwise, for email jobs, a single primary recipient snapshot
     * is derived from `email_config.recipient_emails` (or the job user's email)
     * so issue-#29 preference enforcement and admin display have an
     * authoritative recipient record even for system/legacy scheduling paths.
     *
     * @param array<string, mixed> $jobData
     *   The normalized job payload.
     */
    private function storeRecipients(ScheduledJob $job, array $jobData): void
    {
        $explicit = $jobData['recipients'] ?? null;
        if (is_array($explicit) && $explicit !== []) {
            foreach ($explicit as $recipient) {
                if (is_array($recipient)) {
                    $this->persistRecipientSnapshot($job, $this->asAssocArray($recipient));
                }
            }
            return;
        }

        if ($job->getJobType()->getLookupCode() !== LookupService::JOB_TYPES_EMAIL) {
            return;
        }

        $emailConfig = $this->asAssocArray($jobData['email_config'] ?? null);
        $primaryEmail = $this->firstRecipientEmail($this->asString($emailConfig['recipient_emails'] ?? ''));
        if ($primaryEmail === '') {
            $primaryEmail = (string) ($job->getUser()?->getEmail() ?? '');
        }
        if ($primaryEmail === '') {
            return;
        }

        $policy = $this->asString($emailConfig['delivery_policy'] ?? '');
        if ($policy === '') {
            $policy = LookupService::SCHEDULED_JOB_DELIVERY_POLICY_RESPECT_USER_PREFERENCES;
        }

        $this->persistRecipientSnapshot($job, [
            'channel' => ScheduledJobRecipient::CHANNEL_EMAIL,
            'recipient_type' => ScheduledJobRecipient::RECIPIENT_TYPE_TO,
            'recipient_email' => $primaryEmail,
            'delivery_policy' => $policy,
        ]);
    }

    /**
     * Persist one recipient snapshot, resolving its linked user by id or email.
     *
     * @param array<string, mixed> $recipient
     *   The recipient descriptor (channel, recipient_type, recipient_email,
     *   user_id, delivery_policy, resolved_from).
     */
    private function persistRecipientSnapshot(ScheduledJob $job, array $recipient): void
    {
        $email = $this->asStringOrNull($recipient['recipient_email'] ?? null);
        $email = $email !== null ? trim($email) : null;

        $user = null;
        $userId = $this->asIntOrNull($recipient['user_id'] ?? null);
        if ($userId !== null && $userId > 0) {
            $user = $this->em->getRepository(User::class)->find($userId);
        }
        if ($user === null && $email !== null && $email !== '') {
            $user = $this->resolveUserByEmail($email);
        }

        $policy = $this->asString($recipient['delivery_policy'] ?? '');
        if ($policy === '') {
            $policy = LookupService::SCHEDULED_JOB_DELIVERY_POLICY_RESPECT_USER_PREFERENCES;
        }

        $resolvedFrom = $this->asStringOrNull($recipient['resolved_from'] ?? null);
        if ($resolvedFrom === null) {
            $resolvedFrom = $user instanceof User
                ? ScheduledJobRecipient::RESOLVED_FROM_USER
                : ScheduledJobRecipient::RESOLVED_FROM_EXTERNAL_EMAIL;
        }

        $snapshot = new ScheduledJobRecipient();
        $snapshot->setScheduledJob($job);
        $snapshot->setUser($user);
        $snapshot->setChannel($this->asString($recipient['channel'] ?? ScheduledJobRecipient::CHANNEL_EMAIL));
        $snapshot->setRecipientType($this->asString($recipient['recipient_type'] ?? ScheduledJobRecipient::RECIPIENT_TYPE_TO));
        $snapshot->setRecipientEmail($email === '' ? null : $email);
        $snapshot->setDeliveryPolicy($policy);
        $snapshot->setResolvedFrom($resolvedFrom);

        $job->addRecipient($snapshot);
        $this->em->persist($snapshot);
    }

    /**
     * Resolve the primary email delivery target for a scheduled job.
     *
     * Prefers the first persisted email recipient snapshot (authoritative);
     * falls back to the stored email config plus the job user for legacy rows.
     *
     * @param array<string, mixed> $emailConfig
     *   The stored email config section.
     *
     * @return array{email: string, user: ?User, policy: string}
     */
    private function resolvePrimaryEmailRecipient(ScheduledJob $job, array $emailConfig): array
    {
        foreach ($job->getRecipients() as $recipient) {
            if ($recipient->getChannel() !== ScheduledJobRecipient::CHANNEL_EMAIL) {
                continue;
            }
            $email = (string) ($recipient->getRecipientEmail() ?? $recipient->getUser()?->getEmail() ?? '');

            return [
                'email' => $email,
                'user' => $recipient->getUser() ?? $job->getUser(),
                'policy' => $recipient->getDeliveryPolicy(),
            ];
        }

        $policy = $this->asString($emailConfig['delivery_policy'] ?? '');
        if ($policy === '') {
            $policy = LookupService::SCHEDULED_JOB_DELIVERY_POLICY_RESPECT_USER_PREFERENCES;
        }

        // Use only the explicitly configured recipient address. We intentionally
        // do NOT fall back to the job user's email: a job created with an empty
        // recipient list is a misconfiguration that must fail deterministically,
        // and every real scheduling path (actions, admin, system mail) populates
        // recipient_emails or a recipient snapshot.
        $email = $this->firstRecipientEmail($this->asString($emailConfig['recipient_emails'] ?? ''));

        // Only treat the job user as the preference owner when the resolved
        // address actually belongs to them (or no explicit address was given).
        $user = $job->getUser();
        if ($user instanceof User) {
            $userEmail = (string) ($user->getEmail() ?? '');
            if ($email !== '' && strcasecmp($email, $userEmail) !== 0) {
                $user = $this->resolveUserByEmail($email);
            }
        } elseif ($email !== '') {
            $user = $this->resolveUserByEmail($email);
        }

        return ['email' => $email, 'user' => $user, 'policy' => $policy];
    }

    /**
     * Extract the first address from a comma/semicolon separated recipient list.
     */
    private function firstRecipientEmail(string $recipientEmails): string
    {
        $parts = preg_split('/[;,]/', $recipientEmails) ?: [];
        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Resolve a user by email address (case-insensitive via the DB collation).
     */
    private function resolveUserByEmail(string $email): ?User
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
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
        if (!is_array($condition) && !is_string($condition)) {
            return false;
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
     * @return ScheduledJobExecutionResult
     *   The typed execution outcome used to pick the terminal job status.
     */
    private function executeByType(ScheduledJob $job, string $transactionBy): ScheduledJobExecutionResult
    {
        $jobType = (string) $job->getJobType()->getLookupCode();
        // Plugin-contributed job types take precedence so plugins can
        // shadow a core type when they need to (rare but allowed —
        // e.g. a survey plugin wrapping `email` with extra
        // pre/post hooks). The registry is built from services tagged
        // `selfhelp.plugin.scheduled_job_handler` so it stays empty on
        // hosts that have no plugin contributions. Plugin handlers keep the
        // legacy bool contract; the bool is wrapped into a typed result here.
        $pluginHandler = $this->pluginScheduledJobs->get($jobType);
        if ($pluginHandler !== null) {
            // Issue #36: a plugin job that declares it delivers user-facing
            // email/notifications is held to the SAME communication-preference
            // contract as core email/notification jobs. A disabled channel is
            // an audited skip and the handler is never invoked, so a plugin can
            // never silently bypass receivesEmails()/receivesNotifications().
            $blockedChannel = $this->pluginDeliveryGate->blockedChannel($pluginHandler, $job);
            if ($blockedChannel !== null) {
                return $this->skipPluginDeliveryForPreference($job, $transactionBy, $blockedChannel);
            }

            return $pluginHandler->execute($job, $transactionBy)
                ? ScheduledJobExecutionResult::done('Executed by plugin handler')
                : ScheduledJobExecutionResult::failed('Plugin handler reported failure');
        }

        return match ($jobType) {
            LookupService::JOB_TYPES_EMAIL => $this->executeEmailJob($job, $transactionBy),
            LookupService::JOB_TYPES_NOTIFICATION => $this->executeNotificationJob($job, $transactionBy),
            LookupService::JOB_TYPES_TASK => $this->executeTaskJob($job, $transactionBy)
                ? ScheduledJobExecutionResult::done()
                : ScheduledJobExecutionResult::failed('Task execution failed'),
            default => ScheduledJobExecutionResult::failed('Unknown job type: ' . $jobType),
        };
    }

    /**
     * Turn a blocked plugin delivery channel into the same audited `skipped_*`
     * outcome a core email/notification job produces when the recipient disabled
     * the channel (issue #36). The plugin handler is NOT invoked.
     */
    private function skipPluginDeliveryForPreference(
        ScheduledJob $job,
        string $transactionBy,
        string $blockedChannel,
    ): ScheduledJobExecutionResult {
        if ($blockedChannel === PluginScheduledJobDeliveryAwareInterface::CHANNEL_NOTIFICATION) {
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_SEND_NOTIFICATION_SKIPPED,
                $transactionBy,
                'scheduled_jobs',
                $job->getId(),
                false,
                sprintf('Plugin notification skipped: user %d disabled notifications', (int) $job->getUser()?->getId())
            );

            return ScheduledJobExecutionResult::skipped(
                LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_NOTIFICATIONS,
                'Recipient disabled notifications'
            );
        }

        $this->transactionService->logTransaction(
            LookupService::TRANSACTION_TYPES_SEND_MAIL_SKIPPED,
            $transactionBy,
            'scheduled_jobs',
            $job->getId(),
            false,
            sprintf('Plugin email skipped: user %d disabled emails', (int) $job->getUser()?->getId())
        );

        return ScheduledJobExecutionResult::skipped(
            LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS,
            'Recipient disabled emails'
        );
    }

    /**
     * Execute a scheduled email job using the stored email config.
     *
     * @param ScheduledJob $job
     *   The scheduled email job being executed.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return ScheduledJobExecutionResult
     *   `done` on success, `failed` on send error, or a skipped result when
     *   the recipient disabled emails and the policy respects preferences.
     */
    private function executeEmailJob(ScheduledJob $job, string $transactionBy): ScheduledJobExecutionResult
    {
        $config = $job->getConfig() ?? [];
        $emailConfig = $this->asAssocArray($config['email'] ?? null);

        $target = $this->resolvePrimaryEmailRecipient($job, $emailConfig);
        $recipients = trim($target['email']);
        if ($recipients === '') {
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_SEND_MAIL_FAIL,
                $transactionBy,
                'scheduled_jobs',
                $job->getId(),
                false,
                'No email recipients were resolved for the scheduled job'
            );
            return ScheduledJobExecutionResult::failed('No email recipients resolved');
        }

        // Issue #29: respect the recipient's email preference unless the job is
        // explicitly flagged as required system mail (account/security).
        if (
            $target['policy'] !== LookupService::SCHEDULED_JOB_DELIVERY_POLICY_REQUIRED_SYSTEM
            && $target['user'] instanceof User
            && !$target['user']->receivesEmails()
        ) {
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_SEND_MAIL_SKIPPED,
                $transactionBy,
                'scheduled_jobs',
                $job->getId(),
                false,
                sprintf('Email skipped: user %d disabled emails', (int) $target['user']->getId())
            );
            return ScheduledJobExecutionResult::skipped(
                LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_EMAILS,
                'Recipient disabled emails'
            );
        }

        $subject = $this->asString($emailConfig['subject'] ?? '');
        $body = $this->asString($emailConfig['body'] ?? '');
        $fromEmail = $this->asString($emailConfig['from_email'] ?? MailTemplateDefaults::FROM_EMAIL);
        $fromName = $this->asString($emailConfig['from_name'] ?? MailTemplateDefaults::FROM_NAME);
        $replyTo = $this->asString($emailConfig['reply_to'] ?? $fromEmail);
        $isHtml = (bool) ($emailConfig['is_html'] ?? MailTemplateDefaults::IS_HTML);
        $attachments = is_array($emailConfig['attachments'] ?? null) ? $emailConfig['attachments'] : [];

        try {
            $email = (new Email())
                ->from(new Address($fromEmail, $fromName))
                ->replyTo($replyTo)
                ->to($recipients)
                ->subject($subject);

            // HTML mail is rendered into the branded, email-client-safe shell
            // (inline styles + the named email-style presets); plain text is
            // sent verbatim (issue #56 mail editor).
            $isHtml ? $email->html($this->mailHtmlRenderer->render($body)) : $email->text($body);

            foreach ($attachments as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }
                $path = $attachment['path'] ?? '';
                if (is_string($path) && $path !== '' && is_file($path)) {
                    $filename = $attachment['filename'] ?? null;
                    $email->attachFromPath($path, is_string($filename) ? $filename : null);
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

        return $success
            ? ScheduledJobExecutionResult::done(sprintf('Email sent to %s', $recipients))
            : ScheduledJobExecutionResult::failed(sprintf('Email send failed to %s', $recipients));
    }

    /**
     * Execute a scheduled push-notification job using Firebase HTTP v1.
     *
     * @param ScheduledJob $job
     *   The scheduled notification job being executed.
     * @param string $transactionBy
     *   The transaction origin recorded in the audit trail.
     *
     * @return ScheduledJobExecutionResult
     *   `done` on success, `failed` on error, or a skipped result when the
     *   target user disabled notifications.
     */
    private function executeNotificationJob(ScheduledJob $job, string $transactionBy): ScheduledJobExecutionResult
    {
        $config = $job->getConfig() ?? [];
        $notificationConfig = isset($config['notification']) && is_array($config['notification']) ? $config['notification'] : [];
        $user = $job->getUser();

        // Issue #29: never push to a known user who disabled notifications.
        // Push notifications have no `required_system` escape hatch.
        if ($user instanceof User && !$user->receivesNotifications()) {
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_SEND_NOTIFICATION_SKIPPED,
                $transactionBy,
                'scheduled_jobs',
                $job->getId(),
                false,
                sprintf('Notification skipped: user %d disabled notifications', (int) $user->getId())
            );
            return ScheduledJobExecutionResult::skipped(
                LookupService::SCHEDULED_JOBS_STATUS_SKIPPED_USER_DISABLED_NOTIFICATIONS,
                'Recipient disabled notifications'
            );
        }

        if (!$user || !$user->getDeviceToken()) {
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_SEND_NOTIFICATION_FAIL,
                $transactionBy,
                'scheduled_jobs',
                $job->getId(),
                false,
                'Notification failed because the user does not have a device token'
            );
            return ScheduledJobExecutionResult::failed('User has no device token');
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
            return ScheduledJobExecutionResult::failed('Firebase config unavailable');
        }

        $serviceAccount = $this->decodeFirebaseServiceAccount($firebaseConfig);
        if ($serviceAccount === null) {
            return ScheduledJobExecutionResult::failed('Invalid Firebase service account');
        }

        $accessToken = $this->createFirebaseAccessToken($serviceAccount);
        if ($accessToken === null) {
            return ScheduledJobExecutionResult::failed('Failed to obtain Firebase access token');
        }

        $payload = [
            'message' => [
                'token' => $user->getDeviceToken(),
                'notification' => [
                    'title' => $this->asString($notificationConfig['subject'] ?? ''),
                    'body' => $this->asString($notificationConfig['body'] ?? ''),
                ],
                'data' => [
                    'url' => $this->asString($notificationConfig['url'] ?? ''),
                ],
            ],
        ];

        $response = $this->postJson(
            sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $this->asString($serviceAccount['project_id'] ?? '')),
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

        return $result
            ? ScheduledJobExecutionResult::done(sprintf('Notification sent to user %d', (int) $user->getId()))
            : ScheduledJobExecutionResult::failed(sprintf('Notification failed for user %d', (int) $user->getId()));
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

        /** @var array<string, mixed> $decoded */
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
        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode((string) json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ]));

        $unsignedJwt = $header . '.' . $payload;
        $privateKey = openssl_pkey_get_private($this->asString($serviceAccount['private_key'] ?? ''));
        if ($privateKey === false) {
            $this->logger->error('Failed to load Firebase private key');
            return null;
        }

        $signature = '';
        if (!openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            $this->logger->error('Failed to sign Firebase JWT');
            return null;
        }

        $assertion = $unsignedJwt . '.' . $this->base64UrlEncode($this->asString($signature));
        $response = $this->postForm('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            $this->logger->error('Failed to exchange Firebase JWT for access token', ['response' => $response['body']]);
            return null;
        }

        return $this->asString($decoded['access_token']);
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
     * @param list<string> $headers
     *   HTTP headers to include with the request.
     * @return array{status: int, body: string}
     *   HTTP status and response body.
     */
    private function postJson(string $url, array $payload, array $headers): array
    {
        return $this->sendHttpRequest($url, (string) json_encode($payload), $headers);
    }

    /**
     * @param list<string> $headers
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
