<?php

namespace App\Service\Action;

use App\Entity\Action;
use App\Entity\DataTable;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Repository\DataTableRepository;
use App\Repository\UserRepository;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;

/**
 * Expands runtime action definitions into concrete scheduled jobs.
 *
 * The scheduler iterates selected blocks, evaluates block/job/reminder
 * conditions, calculates execution times, and persists both parent jobs and
 * reminder children with the metadata needed for later cleanup.
 */
class ActionSchedulerService
{
    public function __construct(
        private readonly ActionConditionEvaluatorService $conditionEvaluator,
        private readonly ActionScheduleCalculatorService $scheduleCalculator,
        private readonly ActionConfigRuntimeService $configRuntimeService,
        private readonly JobSchedulerService $jobSchedulerService,
        private readonly UserRepository $userRepository,
        private readonly DataTableRepository $dataTableRepository,
        private readonly LookupService $lookupService
    ) {
    }

    /**
     * Schedule every job produced by a resolved action runtime configuration.
     *
     * @param array<string, mixed> $runtimeConfig
     *   The fully interpolated action configuration.
     * @param int[] $recipientUserIds
     *   The recipient user ids selected for this action execution.
     *
     * @return ScheduledJob[]
     *   All scheduled jobs created for the action, including reminders.
     */
    public function schedule(Action $action, array $runtimeConfig, ActionTriggerContext $context, array $recipientUserIds): array
    {
        $scheduledJobs = [];

        $firstJob = $runtimeConfig[ActionConfig::BLOCKS][0][ActionConfig::JOBS][0] ?? [];
        $firstJobDates = $this->scheduleCalculator->calculateDates($runtimeConfig, is_array($firstJob) ? $firstJob : []);
        $iterations = $this->configRuntimeService->getIterationCount($runtimeConfig, count($firstJobDates));

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $selectedBlocks = $this->configRuntimeService->selectBlocksForIteration($action, $runtimeConfig);

            foreach ($selectedBlocks as $block) {
                if (!$this->conditionEvaluator->passes($block[ActionConfig::CONDITION] ?? null, $context->userId, 'action.block')) {
                    continue;
                }

                foreach (($block[ActionConfig::JOBS] ?? []) as $job) {
                    if (!is_array($job)) {
                        continue;
                    }

                    foreach ($recipientUserIds as $recipientUserId) {
                        if (!$this->conditionEvaluator->passes($job[ActionConfig::CONDITION] ?? null, $recipientUserId, 'action.job')) {
                            continue;
                        }

                        $executionDates = $this->scheduleCalculator->calculateDates($runtimeConfig, $job);
                        $executionDate = $executionDates[$iteration] ?? end($executionDates);
                        if (!$executionDate instanceof \DateTimeImmutable) {
                            continue;
                        }

                        $scheduledJob = $this->scheduleSingleJob($action, $runtimeConfig, $context, $job, $recipientUserId, $executionDate);
                        if (!$scheduledJob) {
                            continue;
                        }

                        $scheduledJobs[] = $scheduledJob;
                        $scheduledJobs = array_merge(
                            $scheduledJobs,
                            $this->scheduleReminders($action, $runtimeConfig, $context, $job, $recipientUserId, $scheduledJob, $executionDate)
                        );
                    }
                }
            }
        }

        return $scheduledJobs;
    }

    /**
     * Create a single scheduled job entity from an action job definition.
     *
     * @param array<string, mixed> $runtimeConfig
     *   The fully interpolated action configuration.
     * @param array<string, mixed> $job
     *   The action job definition being materialized.
     *
     * @return ScheduledJob|false
     *   The created scheduled job or `false` when the job could not be created.
     */
    private function scheduleSingleJob(
        Action $action,
        array $runtimeConfig,
        ActionTriggerContext $context,
        array $job,
        int $recipientUserId,
        \DateTimeImmutable $executionDate,
        ?ScheduledJob $parentJob = null,
        ?DataTable $reminderDataTable = null,
        ?\DateTimeImmutable $reminderSessionStart = null,
        ?\DateTimeImmutable $reminderSessionEnd = null
    ): ScheduledJob|false {
        $user = $this->userRepository->find($recipientUserId);
        if (!$user instanceof User) {
            return false;
        }

        $actionJobType = (string) ($job[ActionConfig::JOB_TYPE] ?? '');
        $notificationConfig = is_array($job[ActionConfig::NOTIFICATION] ?? null) ? $job[ActionConfig::NOTIFICATION] : [];

        $jobData = [
            'type' => $this->resolveScheduledJobType($job, $notificationConfig),
            'description' => (string) ($job[ActionConfig::JOB_NAME] ?? sprintf('Scheduled job for action %s', $action->getName())),
            'date_to_be_executed' => $executionDate,
            'user_id' => $recipientUserId,
            'action' => $action,
            'dataTable' => $context->dataTable,
            'dataRow' => $context->dataRow,
            'parent_job' => $parentJob,
            'reminder_data_table' => $reminderDataTable,
            'reminder_session_start' => $reminderSessionStart,
            'reminder_session_end' => $reminderSessionEnd,
            'condition' => $this->normalizeExecutionCondition($job[ActionConfig::ON_JOB_EXECUTE][ActionConfig::CONDITION] ?? null),
            'schedule' => $job[ActionConfig::SCHEDULE_TIME] ?? [],
            'action_job_type' => $actionJobType,
        ];

        if ($jobData['type'] === LookupService::JOB_TYPES_TASK) {
            $jobData['task_config'] = [
                'task_type' => $actionJobType,
                'groups' => $job[ActionConfig::JOB_ADD_REMOVE_GROUPS] ?? [],
                'reason' => $action->getName(),
            ];
        } elseif ($jobData['type'] === LookupService::JOB_TYPES_EMAIL) {
            $jobData['email_config'] = $this->buildEmailConfig($runtimeConfig, $notificationConfig, $user);
        } else {
            $jobData['notification_config'] = $this->buildNotificationConfig($notificationConfig, $user);
        }

        return $this->jobSchedulerService->scheduleJob($jobData, $context->transactionBy);
    }

    /**
     * Schedule reminder jobs that belong to a previously scheduled parent job.
     *
     * @param array<string, mixed> $runtimeConfig
     *   The fully interpolated action configuration.
     * @param array<string, mixed> $job
     *   The parent job definition containing reminder settings.
     *
     * @return ScheduledJob[]
     *   The reminder jobs created for the parent job.
     */
    private function scheduleReminders(
        Action $action,
        array $runtimeConfig,
        ActionTriggerContext $context,
        array $job,
        int $recipientUserId,
        ScheduledJob $parentJob,
        \DateTimeImmutable $parentExecutionDate
    ): array {
        $scheduledJobs = [];
        $reminders = $job[ActionConfig::REMINDERS] ?? [];
        if (!is_array($reminders) || $reminders === []) {
            return [];
        }

        $reminderDataTable = $this->resolveReminderDataTable($job[ActionConfig::REMINDER_FORM_ID] ?? null);

        foreach ($reminders as $reminder) {
            if (!is_array($reminder)) {
                continue;
            }

            if (!$this->conditionEvaluator->passes($reminder[ActionConfig::CONDITION] ?? null, $recipientUserId, 'action.reminder')) {
                continue;
            }

            $schedule = is_array($reminder[ActionConfig::SCHEDULE_TIME] ?? null) ? $reminder[ActionConfig::SCHEDULE_TIME] : [];
            $executionDate = $this->scheduleCalculator->calculateReminderDate($parentExecutionDate, $schedule);
            $sessionWindow = $this->scheduleCalculator->calculateReminderSessionWindow($parentExecutionDate, $executionDate, $schedule);

            $reminderJob = $this->scheduleSingleJob(
                $action,
                $runtimeConfig,
                $context,
                $reminder,
                $recipientUserId,
                $executionDate,
                $parentJob,
                $reminderDataTable,
                $sessionWindow['start'],
                $sessionWindow['end']
            );

            if ($reminderJob) {
                $scheduledJobs[] = $reminderJob;
            }
        }

        return $scheduledJobs;
    }

    /**
     * Map an action job definition to the persisted scheduled-job type code.
     *
     * @param array<string, mixed> $job
     *   The raw action job definition.
     * @param array<string, mixed> $notificationConfig
     *   The normalized notification section of the job definition.
     *
     * @return string
     *   The lookup code for the scheduled job type.
     */
    private function resolveScheduledJobType(array $job, array $notificationConfig): string
    {
        $actionJobType = $job[ActionConfig::JOB_TYPE] ?? '';

        if (in_array($actionJobType, [ActionConfig::JOB_TYPE_ADD_GROUP, ActionConfig::JOB_TYPE_REMOVE_GROUP], true)) {
            return LookupService::JOB_TYPES_TASK;
        }

        if (($notificationConfig[ActionConfig::NOTIFICATION_TYPES] ?? null) === LookupService::NOTIFICATION_TYPES_EMAIL) {
            return LookupService::JOB_TYPES_EMAIL;
        }

        return LookupService::JOB_TYPES_NOTIFICATION;
    }

    /**
     * Build the persisted email configuration for a scheduled email job.
     *
     * @param array<string, mixed> $runtimeConfig
     *   The fully interpolated action configuration.
     * @param array<string, mixed> $notificationConfig
     *   The action notification section for the email job.
     *
     * @return array<string, mixed>
     *   The normalized email configuration stored on the scheduled job.
     */
    private function buildEmailConfig(array $runtimeConfig, array $notificationConfig, User $recipient): array
    {
        $recipientEmails = (string) ($notificationConfig[ActionConfig::RECIPIENT] ?? '');
        if (($runtimeConfig[ActionConfig::TARGET_GROUPS] ?? false) === true) {
            $recipientEmails = (string) $recipient->getEmail();
        } else {
            $recipientEmails = str_replace('@user', (string) $recipient->getEmail(), $recipientEmails);
        }

        $userCode = $this->getActiveValidationCode($recipient);
        $body = str_replace(
            ['@user_name', '@user_code'],
            [(string) ($recipient->getName() ?? ''), $userCode],
            (string) ($notificationConfig[ActionConfig::BODY] ?? '')
        );

        $subject = str_replace(
            ['@user_name', '@user_code'],
            [(string) ($recipient->getName() ?? ''), $userCode],
            (string) ($notificationConfig[ActionConfig::SUBJECT] ?? '')
        );

        return [
            'from_email' => (string) ($notificationConfig[ActionConfig::FROM_EMAIL] ?? 'noreply@example.com'),
            'from_name' => (string) ($notificationConfig[ActionConfig::FROM_NAME] ?? 'SelfHelp'),
            'reply_to' => (string) ($notificationConfig[ActionConfig::REPLY_TO] ?? ($notificationConfig[ActionConfig::FROM_EMAIL] ?? 'noreply@example.com')),
            'recipient_emails' => $recipientEmails,
            'subject' => $subject,
            'body' => $body,
            'attachments' => $this->normalizeAttachments($notificationConfig[ActionConfig::ATTACHMENTS] ?? []),
            'is_html' => true,
        ];
    }

    /**
     * Build the persisted notification configuration for a scheduled notification job.
     *
     * @param array<string, mixed> $notificationConfig
     *   The action notification section for the job.
     *
     * @return array<string, mixed>
     *   The normalized notification configuration stored on the scheduled job.
     */
    private function buildNotificationConfig(array $notificationConfig, User $recipient): array
    {
        return [
            'subject' => str_replace('@user_name', (string) ($recipient->getName() ?? ''), (string) ($notificationConfig[ActionConfig::SUBJECT] ?? '')),
            'body' => str_replace('@user_name', (string) ($recipient->getName() ?? ''), (string) ($notificationConfig[ActionConfig::BODY] ?? '')),
            'url' => (string) ($notificationConfig[ActionConfig::REDIRECT_URL] ?? ''),
            'notification_type' => (string) ($notificationConfig[ActionConfig::NOTIFICATION_TYPES] ?? LookupService::NOTIFICATION_TYPES_PUSH_NOTIFICATION),
        ];
    }

    /**
     * Normalize attachment inputs into persisted attachment descriptors.
     *
     * @param mixed $attachments
     *   The raw attachment payload from the action config.
     *
     * @return array<int, array<string, string>>
     *   A list of attachment metadata arrays keyed by filename and path.
     */
    private function normalizeAttachments(mixed $attachments): array
    {
        if (!is_array($attachments)) {
            return [];
        }

        $normalized = [];
        foreach ($attachments as $attachment) {
            if (!is_string($attachment) || trim($attachment) === '') {
                continue;
            }

            $normalized[] = [
                'filename' => basename($attachment),
                'path' => $attachment,
            ];
        }

        return $normalized;
    }

    /**
     * Fetch the first active validation code for the given recipient.
     *
     * @param User $recipient
     *   The user whose validation codes should be inspected.
     *
     * @return string
     *   The first unconsumed validation code, or an empty string if none exist.
     */
    private function getActiveValidationCode(User $recipient): string
    {
        foreach ($recipient->getValidationCodes() as $validationCode) {
            if ($validationCode->getConsumed() === null) {
                return (string) $validationCode->getCode();
            }
        }

        return '';
    }

    /**
     * Resolve the reminder target data table referenced by an action job.
     *
     * @param mixed $value
     *   The reminder-form identifier from the action config.
     *
     * @return DataTable|null
     *   The matching data table entity, or `null` when no match is found.
     */
    private function resolveReminderDataTable(mixed $value): ?DataTable
    {
        if (is_numeric($value)) {
            return $this->dataTableRepository->find((int) $value);
        }

        if (is_string($value) && trim($value) !== '') {
            return $this->dataTableRepository->findOneBy(['name' => $value])
                ?? $this->dataTableRepository->findOneBy(['displayName' => $value]);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|string|null $condition
     *   The raw `on_job_execute.condition` payload from action config.
     *
     * @return array<string, mixed>|string|null
     *   A normalized condition value suitable for persistence and later execution checks.
     */
    private function normalizeExecutionCondition(array|string|null $condition): array|string|null
    {
        if ($condition === null) {
            return null;
        }

        if (is_string($condition)) {
            $condition = trim($condition);
            return $condition === '' ? null : $condition;
        }

        if ($condition === []) {
            return null;
        }

        return $condition[ActionConfig::JSON_LOGIC] ?? $condition;
    }
}
