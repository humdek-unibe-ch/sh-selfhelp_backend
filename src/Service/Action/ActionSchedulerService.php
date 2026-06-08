<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\Action;

use App\Entity\Action;
use App\Entity\DataTable;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Repository\ActionTranslationRepository;
use App\Repository\DataTableRepository;
use App\Repository\UserRepository;
use App\Service\Auth\MailTemplateDefaults;
use App\Service\Auth\MailTemplateService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\BaseService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;

/**
 * Expands runtime action definitions into concrete scheduled jobs.
 *
 * The scheduler iterates selected blocks, evaluates block/job/reminder
 * conditions, calculates execution times, and persists both parent jobs and
 * reminder children with the metadata needed for later cleanup.
 */
class ActionSchedulerService extends BaseService
{
    public function __construct(
        private readonly ActionConditionEvaluatorService $conditionEvaluator,
        private readonly ActionScheduleCalculatorService $scheduleCalculator,
        private readonly ActionConfigRuntimeService $configRuntimeService,
        private readonly JobSchedulerService $jobSchedulerService,
        private readonly UserRepository $userRepository,
        private readonly DataTableRepository $dataTableRepository,
        private readonly ActionTemplateContextBuilder $templateContextBuilder,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly MailTemplateService $mailTemplateService,
        private readonly ActionTranslationRepository $actionTranslationRepository
    ) {
    }

    /**
     * Resolve an action subject/body value.
     *
     * Admin-authored subjects/bodies are stored as CMS translation keys (e.g.
     * `block_0.job_0.notification.subject`); this maps the key to the
     * recipient's language content (falling back to the CMS default language).
     * Literal text and `{{...}}` placeholders that are not translation keys
     * pass through unchanged, so the value is always safe to render afterwards.
     */
    private function resolveActionText(Action $action, string $value, User $recipient): string
    {
        if ($value === '') {
            return $value;
        }

        $actionId = $action->getId();
        if ($actionId === null) {
            return $value;
        }

        $languageIds = [];
        foreach ([$recipient->getLanguage()?->getId(), $this->cmsPreferenceService->getDefaultLanguageId()] as $languageId) {
            if (is_int($languageId) && $languageId > 0 && !in_array($languageId, $languageIds, true)) {
                $languageIds[] = $languageId;
            }
        }

        foreach ($languageIds as $languageId) {
            $translation = $this->actionTranslationRepository->findByActionKeyAndLanguage($actionId, $value, $languageId);
            $content = $translation?->getContent();
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }

        return $value;
    }

    /**
     * Resolve the timezone identifier used to interpret a recipient's
     * wall-clock action schedules. Falls back to the CMS default, then UTC.
     */
    private function resolveRecipientTimezoneId(?User $user): string
    {
        $userTz = $user?->getTimezone()?->getLookupCode();
        if (is_string($userTz) && $userTz !== '') {
            return $userTz;
        }

        $default = $this->cmsPreferenceService->getDefaultTimezoneCode();

        return $default !== '' ? $default : 'UTC';
    }

    /**
     * Coerce a mixed JSON-config section into a string-keyed array.
     *
     * Action configuration sections are decoded JSON objects, so their keys are
     * always strings at runtime; this normalizes PHPStan's view accordingly and
     * returns an empty array for non-array inputs.
     *
     * @return array<string, mixed>
     */
    private function toConfigArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * Coerce a mixed condition payload into the shape accepted by the evaluator.
     *
     * @return array<string, mixed>|string|null
     */
    private function conditionArg(mixed $condition): array|string|null
    {
        if ($condition === null || is_string($condition)) {
            return $condition;
        }

        return is_array($condition) ? $this->toConfigArray($condition) : null;
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

        $blocksConfig = $this->asArray($runtimeConfig[ActionConfig::BLOCKS] ?? null);
        $firstBlock = $this->asArray($blocksConfig[0] ?? null);
        $firstBlockJobs = $this->asArray($firstBlock[ActionConfig::JOBS] ?? null);
        $firstJob = $this->toConfigArray($firstBlockJobs[0] ?? null);
        $firstJobDates = $this->scheduleCalculator->calculateDates($runtimeConfig, $firstJob);
        $iterations = $this->configRuntimeService->getIterationCount($runtimeConfig, count($firstJobDates));

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $selectedBlocks = $this->configRuntimeService->selectBlocksForIteration($action, $runtimeConfig);

            foreach ($selectedBlocks as $block) {
                if (!$this->conditionEvaluator->passes($this->conditionArg($block[ActionConfig::CONDITION] ?? null), $context->userId, 'action.block')) {
                    continue;
                }

                foreach ($this->asArray($block[ActionConfig::JOBS] ?? null) as $job) {
                    if (!is_array($job)) {
                        continue;
                    }
                    $job = $this->toConfigArray($job);

                    foreach ($recipientUserIds as $recipientUserId) {
                        if (!$this->conditionEvaluator->passes($this->conditionArg($job[ActionConfig::CONDITION] ?? null), $recipientUserId, 'action.job')) {
                            continue;
                        }

                        $recipientUser = $this->userRepository->find($recipientUserId);
                        $scheduleContext = ActionScheduleContext::forTimezone(
                            $this->resolveRecipientTimezoneId($recipientUser instanceof User ? $recipientUser : null)
                        );

                        $executionDates = $this->scheduleCalculator->calculateDates($runtimeConfig, $job, $scheduleContext);
                        $executionDate = $executionDates[$iteration] ?? end($executionDates);
                        if (!$executionDate instanceof \DateTimeImmutable) {
                            continue;
                        }

                        $createdJobs = $this->scheduleSingleJob($action, $runtimeConfig, $context, $job, $recipientUserId, $executionDate, scheduleContext: $scheduleContext);
                        if ($createdJobs === []) {
                            continue;
                        }

                        foreach ($createdJobs as $createdJob) {
                            $scheduledJobs[] = $createdJob;
                        }

                        // Reminders attach to the primary (first) materialized
                        // job; fanned-out hardcoded recipients do not get their
                        // own reminder chains.
                        $scheduledJobs = array_merge(
                            $scheduledJobs,
                            $this->scheduleReminders($action, $runtimeConfig, $context, $job, $recipientUserId, $createdJobs[0], $executionDate)
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
     * @return ScheduledJob[]
     *   The created scheduled jobs. Email jobs fan out into one job per
     *   resolved recipient address; all other job types yield a single job.
     *   Empty when nothing could be scheduled.
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
        ?\DateTimeImmutable $reminderSessionEnd = null,
        ?ActionScheduleContext $scheduleContext = null
    ): array {
        $user = $this->userRepository->find($recipientUserId);
        if (!$user instanceof User) {
            return [];
        }

        $actionJobType = $this->asString($job[ActionConfig::JOB_TYPE] ?? '');
        $notificationConfig = $this->toConfigArray($job[ActionConfig::NOTIFICATION] ?? null);
        $onJobExecute = $this->toConfigArray($job[ActionConfig::ON_JOB_EXECUTE] ?? null);

        $jobData = [
            'type' => $this->resolveScheduledJobType($job, $notificationConfig),
            'description' => $this->asString($job[ActionConfig::JOB_NAME] ?? sprintf('Scheduled job for action %s', $action->getName())),
            'date_to_be_executed' => $executionDate,
            'user_id' => $recipientUserId,
            'action' => $action,
            'dataTable' => $context->dataTable,
            'dataRow' => $context->dataRow,
            'parent_job' => $parentJob,
            'reminder_data_table' => $reminderDataTable,
            'reminder_session_start' => $reminderSessionStart,
            'reminder_session_end' => $reminderSessionEnd,
            'condition' => $this->normalizeExecutionCondition($this->conditionArg($onJobExecute[ActionConfig::CONDITION] ?? null)),
            'schedule' => $this->buildScheduleMetadata($job, $user, $scheduleContext, $executionDate),
            'action_job_type' => $actionJobType,
        ];

        if ($jobData['type'] === LookupService::JOB_TYPES_EMAIL) {
            return $this->scheduleEmailJobs($action, $jobData, $runtimeConfig, $notificationConfig, $user, $recipientUserId, $context->transactionBy);
        }

        if ($jobData['type'] === LookupService::JOB_TYPES_TASK) {
            $jobData['task_config'] = [
                'task_type' => $actionJobType,
                'groups' => $job[ActionConfig::JOB_ADD_REMOVE_GROUPS] ?? [],
                'reason' => $action->getName(),
            ];
        } else {
            $jobData['notification_config'] = $this->buildNotificationConfig($action, $notificationConfig, $user);
        }

        $scheduledJob = $this->jobSchedulerService->scheduleJob($jobData, $context->transactionBy);

        return $scheduledJob instanceof ScheduledJob ? [$scheduledJob] : [];
    }

    /**
     * Materialize one email scheduled job per resolved recipient address.
     *
     * The recipient field is rendered against the action recipient, then split
     * on `;`/`,` so an admin can address several mailboxes (e.g.
     * `{{recipient.email}};therapist@example.org`). Each address becomes its own
     * job with its own recipient snapshot, and is linked to a SelfHelp user when
     * the address matches one (so issue-#29 preference enforcement applies per
     * address). External addresses are scheduled with no linked user.
     *
     * @param array<string, mixed> $baseJobData
     *   The shared job payload (type/description/schedule/condition/links).
     * @param array<string, mixed> $runtimeConfig
     *   The fully interpolated action configuration.
     * @param array<string, mixed> $notificationConfig
     *   The action notification section for the email job.
     * @param int $recipientUserId
     *   The action recipient user id (owner of the `{{recipient.*}}` context).
     *
     * @return ScheduledJob[]
     *   One scheduled job per resolved address (empty when none resolve).
     */
    private function scheduleEmailJobs(
        Action $action,
        array $baseJobData,
        array $runtimeConfig,
        array $notificationConfig,
        User $recipient,
        int $recipientUserId,
        string $transactionBy
    ): array {
        $emailConfig = $this->buildEmailConfig($action, $notificationConfig, $recipient);
        $addresses = $this->resolveRecipientAddresses($runtimeConfig, $notificationConfig, $recipient);
        if ($addresses === []) {
            return [];
        }

        $policy = $this->asString($emailConfig['delivery_policy'] ?? LookupService::SCHEDULED_JOB_DELIVERY_POLICY_RESPECT_USER_PREFERENCES);
        $recipientEmail = (string) ($recipient->getEmail() ?? '');

        $jobs = [];
        foreach ($addresses as $address) {
            // The action recipient's own mailbox keeps their user id; any extra
            // hardcoded address is matched to a user by email (preference
            // enforcement) or scheduled unlinked when it is external.
            if ($recipientEmail !== '' && strcasecmp($address, $recipientEmail) === 0) {
                $addressUserId = $recipientUserId;
            } else {
                $addressUser = $this->userRepository->findOneBy(['email' => $address]);
                $addressUserId = $addressUser instanceof User ? (int) $addressUser->getId() : null;
            }
            $hasUser = $addressUserId !== null && $addressUserId > 0;

            $jobData = $baseJobData;
            $jobData['email_config'] = $emailConfig + ['recipient_emails' => $address];

            if ($hasUser) {
                $jobData['user_id'] = $addressUserId;
            } else {
                // External address: keep the job unlinked so it is delivered
                // unconditionally (no user preference to honour).
                unset($jobData['user_id']);
            }

            $jobData['recipients'] = [[
                'channel' => 'email',
                'recipient_type' => 'to',
                'recipient_email' => $address,
                'user_id' => $hasUser ? $addressUserId : null,
                'delivery_policy' => $policy,
                'resolved_from' => $hasUser ? 'user_email' : 'external_email',
            ]];

            $scheduledJob = $this->jobSchedulerService->scheduleJob($jobData, $transactionBy);
            if ($scheduledJob instanceof ScheduledJob) {
                $jobs[] = $scheduledJob;
            }
        }

        return $jobs;
    }

    /**
     * Resolve the concrete delivery addresses for an email job.
     *
     * For group/user-targeted actions each recipient user is already iterated,
     * so we deliver to that user's own mailbox. Otherwise the configured
     * recipient template is rendered per recipient and split into addresses.
     *
     * @param array<string, mixed> $runtimeConfig
     *   The fully interpolated action configuration.
     * @param array<string, mixed> $notificationConfig
     *   The action notification section for the email job.
     *
     * @return list<string>
     *   The trimmed, de-duplicated, non-empty recipient addresses.
     */
    private function resolveRecipientAddresses(array $runtimeConfig, array $notificationConfig, User $recipient): array
    {
        if (($runtimeConfig[ActionConfig::TARGET_GROUPS] ?? false) === true) {
            return $this->splitAddresses((string) ($recipient->getEmail() ?? ''));
        }

        $context = $this->templateContextBuilder->buildContext(
            $recipient,
            $this->getActiveValidationCode($recipient)
        );
        $rendered = $this->templateContextBuilder->render(
            $this->asString($notificationConfig[ActionConfig::RECIPIENT] ?? ''),
            $context
        );

        return $this->splitAddresses($rendered);
    }

    /**
     * Split a `;`/`,` separated recipient string into clean, unique addresses.
     *
     * @return list<string>
     */
    private function splitAddresses(string $value): array
    {
        $parts = preg_split('/[;,]/', $value) ?: [];
        $seen = [];
        $addresses = [];
        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate === '') {
                continue;
            }
            $key = strtolower($candidate);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $addresses[] = $candidate;
        }

        return $addresses;
    }

    /**
     * Build the persisted schedule metadata for a scheduled job.
     *
     * Stores the wall-clock intent (timezone + rule) so queued future jobs can
     * be recalculated when the recipient changes their timezone (Slice T).
     *
     * @param array<string, mixed> $job
     *   The action job definition being materialized.
     * @param \DateTimeImmutable $executionDate
     *   The resolved UTC execution instant, used to derive the wall-clock
     *   `local_datetime` intent for the recipient timezone.
     *
     * @return array<string, mixed>
     *   The schedule metadata stored under `config.schedule`.
     */
    private function buildScheduleMetadata(array $job, User $user, ?ActionScheduleContext $scheduleContext, \DateTimeImmutable $executionDate): array
    {
        $scheduleRule = $this->toConfigArray($job[ActionConfig::SCHEDULE_TIME] ?? null);
        $timezone = $scheduleContext->timezone ?? new \DateTimeZone('UTC');
        $userTz = $user->getTimezone()?->getLookupCode();
        $timezoneSource = (is_string($userTz) && $userTz !== '') ? 'user' : 'cms_default';
        $isWallClock = $scheduleContext !== null && $this->scheduleCalculator->isWallClockSchedule($scheduleRule);

        return [
            'wall_clock' => $isWallClock,
            'local_datetime' => $executionDate->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
            'timezone' => $timezone->getName(),
            'timezone_source' => $timezoneSource,
            'rule' => $scheduleRule,
        ];
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
            $reminder = $this->toConfigArray($reminder);

            if (!$this->conditionEvaluator->passes($this->conditionArg($reminder[ActionConfig::CONDITION] ?? null), $recipientUserId, 'action.reminder')) {
                continue;
            }

            $schedule = $this->toConfigArray($reminder[ActionConfig::SCHEDULE_TIME] ?? null);
            $executionDate = $this->scheduleCalculator->calculateReminderDate($parentExecutionDate, $schedule);
            $sessionWindow = $this->scheduleCalculator->calculateReminderSessionWindow($parentExecutionDate, $executionDate, $schedule);

            $reminderJobs = $this->scheduleSingleJob(
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

            foreach ($reminderJobs as $reminderJob) {
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
     * @param array<string, mixed> $notificationConfig
     *   The action notification section for the email job.
     *
     * @return array<string, mixed>
     *   The shared email configuration (without `recipient_emails`, which is set
     *   per address by {@see scheduleEmailJobs()}).
     */
    private function buildEmailConfig(Action $action, array $notificationConfig, User $recipient): array
    {
        $context = $this->templateContextBuilder->buildContext(
            $recipient,
            $this->getActiveValidationCode($recipient)
        );

        // Sender identity defaults to the CMS mail config (sh-mail-config page)
        // so admins control From/Reply-To centrally; the action may still
        // override per notification. Hardcoded fallbacks are a last resort only.
        $global = $this->mailTemplateService->resolveGlobalConfig();
        $fromEmail = $this->firstNonEmpty(
            $this->asString($notificationConfig[ActionConfig::FROM_EMAIL] ?? ''),
            $this->asString($global['from_email'] ?? ''),
            MailTemplateDefaults::FROM_EMAIL
        );
        $fromName = $this->firstNonEmpty(
            $this->asString($notificationConfig[ActionConfig::FROM_NAME] ?? ''),
            $this->asString($global['from_name'] ?? ''),
            MailTemplateDefaults::FROM_NAME
        );
        $replyTo = $this->firstNonEmpty(
            $this->asString($notificationConfig[ActionConfig::REPLY_TO] ?? ''),
            $this->asString($global['reply_to'] ?? ''),
            $fromEmail
        );

        $body = $this->templateContextBuilder->render(
            $this->resolveActionText($action, $this->asString($notificationConfig[ActionConfig::BODY] ?? ''), $recipient),
            $context
        );
        $subject = $this->templateContextBuilder->render(
            $this->resolveActionText($action, $this->asString($notificationConfig[ActionConfig::SUBJECT] ?? ''), $recipient),
            $context
        );

        return [
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to' => $replyTo,
            'subject' => $subject,
            'body' => $body,
            'attachments' => $this->normalizeAttachments($notificationConfig[ActionConfig::ATTACHMENTS] ?? []),
            'is_html' => true,
            // Normal action mail respects recipient communication preferences (issue #29).
            'delivery_policy' => LookupService::SCHEDULED_JOB_DELIVERY_POLICY_RESPECT_USER_PREFERENCES,
        ];
    }

    /**
     * Return the first non-empty trimmed value, or an empty string.
     */
    private function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
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
    private function buildNotificationConfig(Action $action, array $notificationConfig, User $recipient): array
    {
        $context = $this->templateContextBuilder->buildContext(
            $recipient,
            $this->getActiveValidationCode($recipient)
        );

        return [
            'subject' => $this->templateContextBuilder->render(
                $this->resolveActionText($action, $this->asString($notificationConfig[ActionConfig::SUBJECT] ?? ''), $recipient),
                $context
            ),
            'body' => $this->templateContextBuilder->render(
                $this->resolveActionText($action, $this->asString($notificationConfig[ActionConfig::BODY] ?? ''), $recipient),
                $context
            ),
            'url' => $this->asString($notificationConfig[ActionConfig::REDIRECT_URL] ?? ''),
            'notification_type' => $this->asString($notificationConfig[ActionConfig::NOTIFICATION_TYPES] ?? LookupService::NOTIFICATION_TYPES_PUSH_NOTIFICATION),
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

        $jsonLogic = $condition[ActionConfig::JSON_LOGIC] ?? null;
        if (is_array($jsonLogic)) {
            return $this->toConfigArray($jsonLogic);
        }
        if (is_string($jsonLogic)) {
            return $jsonLogic;
        }

        return $condition;
    }
}
