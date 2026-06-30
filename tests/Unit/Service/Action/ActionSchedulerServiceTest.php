<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Entity\ScheduledJob;
use App\Entity\User;
use App\Repository\ActionTranslationRepository;
use App\Repository\DataTableRepository;
use App\Repository\UserRepository;
use App\Service\Action\ActionConditionEvaluatorService;
use App\Service\CMS\Admin\AdminActionTranslationService;
use App\Service\Action\ActionConfig;
use App\Service\Action\ActionConfigRuntimeService;
use App\Service\Action\ActionScheduleCalculatorService;
use App\Service\Action\ActionSchedulerService;
use App\Service\Action\ActionTemplateContextBuilder;
use App\Service\Action\ActionTriggerContext;
use App\Service\Auth\MailTemplateService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\InterpolationService;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
use App\Service\Core\VariableResolverService;
use App\Tests\Support\NarrowsJson;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the action scheduler. With all calculators/evaluators stubbed,
 * the scheduler must map an email-notification action job into an `email`-typed
 * scheduled job for each resolved recipient.
 */
final class ActionSchedulerServiceTest extends TestCase
{
    use NarrowsJson;

    public function testEmailNotificationJobIsScheduledAsEmailTypeForEachRecipient(): void
    {
        $executionDate = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));

        $job = [
            ActionConfig::JOB_NAME => 'qa_email_job',
            ActionConfig::NOTIFICATION => [
                ActionConfig::NOTIFICATION_TYPES => LookupService::NOTIFICATION_TYPES_EMAIL,
                ActionConfig::SUBJECT => 'QA subject',
                ActionConfig::BODY => 'QA body',
                ActionConfig::RECIPIENT => '{{recipient.email}}',
            ],
        ];
        $block = [ActionConfig::JOBS => [$job]];
        $runtimeConfig = [ActionConfig::BLOCKS => [$block]];

        $dataTable = $this->createStub(DataTable::class);
        $dataRow = $this->createStub(DataRow::class);
        $context = new ActionTriggerContext(
            $dataTable,
            $dataRow,
            ['record_id' => 200],
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            42,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
        );
        $action = $this->createStub(\App\Entity\Action::class);
        $action->method('getId')->willReturn(1);
        $action->method('getName')->willReturn('qa_action');

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $scheduleCalculator = $this->createStub(ActionScheduleCalculatorService::class);
        $scheduleCalculator->method('calculateDates')->willReturn([$executionDate]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('getIterationCount')->willReturn(1);
        $configRuntime->method('selectBlocksForIteration')->willReturn([$block]);

        $user = $this->createStub(User::class);
        $user->method('getEmail')->willReturn('qa.recipient@selfhelp.test');
        $user->method('getName')->willReturn('QA Recipient');
        $user->method('getValidationCodes')->willReturn(new ArrayCollection());

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);

        $scheduledJob = $this->createStub(ScheduledJob::class);

        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->expects(self::once())
            ->method('scheduleJob')
            ->with(
                self::callback(function (array $jobData) use ($action, $context): bool {
                    return $jobData['type'] === LookupService::JOB_TYPES_EMAIL
                        && $jobData['user_id'] === 42
                        && $jobData['action'] === $action
                        && $jobData['dataTable'] === $context->dataTable
                        && $jobData['dataRow'] === $context->dataRow
                        && (self::asArray($jobData['email_config'] ?? [])['recipient_emails'] ?? null) === 'qa.recipient@selfhelp.test';
                }),
                LookupService::TRANSACTION_BY_BY_SYSTEM,
            )
            ->willReturn($scheduledJob);

        $cmsPreferences = $this->createStub(CmsPreferenceService::class);
        $cmsPreferences->method('getDefaultTimezoneCode')->willReturn('UTC');

        $scheduler = new ActionSchedulerService(
            $conditionEvaluator,
            $scheduleCalculator,
            $configRuntime,
            $jobScheduler,
            $userRepository,
            $this->createStub(DataTableRepository::class),
            new ActionTemplateContextBuilder(new InterpolationService()),
            $cmsPreferences,
            $this->createStub(MailTemplateService::class),
            $this->createStub(ActionTranslationRepository::class),
            $this->createStub(VariableResolverService::class),
        );

        $result = $scheduler->schedule($action, $runtimeConfig, $context, [42]);

        self::assertSame([$scheduledJob], $result);
    }

    public function testMultipleRecipientsFanOutIntoOneJobPerRecipient(): void
    {
        $executionDate = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));

        $job = [
            ActionConfig::JOB_NAME => 'qa_email_job',
            ActionConfig::NOTIFICATION => [
                ActionConfig::NOTIFICATION_TYPES => LookupService::NOTIFICATION_TYPES_EMAIL,
                ActionConfig::SUBJECT => 'QA subject',
                ActionConfig::BODY => 'Hello {{recipient.name}}',
                ActionConfig::RECIPIENT => '{{recipient.email}}',
            ],
        ];
        $block = [ActionConfig::JOBS => [$job]];
        $runtimeConfig = [ActionConfig::BLOCKS => [$block]];

        $context = new ActionTriggerContext(
            $this->createStub(DataTable::class),
            $this->createStub(DataRow::class),
            [],
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            42,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
        );
        $action = $this->createStub(\App\Entity\Action::class);
        $action->method('getName')->willReturn('qa_action');

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $scheduleCalculator = $this->createStub(ActionScheduleCalculatorService::class);
        $scheduleCalculator->method('calculateDates')->willReturn([$executionDate]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('getIterationCount')->willReturn(1);
        $configRuntime->method('selectBlocksForIteration')->willReturn([$block]);

        // Two distinct recipient users resolve by id.
        $userA = $this->createStub(User::class);
        $userA->method('getEmail')->willReturn('qa.a@selfhelp.test');
        $userA->method('getName')->willReturn('QA A');
        $userA->method('getValidationCodes')->willReturn(new ArrayCollection());
        $userB = $this->createStub(User::class);
        $userB->method('getEmail')->willReturn('qa.b@selfhelp.test');
        $userB->method('getName')->willReturn('QA B');
        $userB->method('getValidationCodes')->willReturn(new ArrayCollection());

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturnCallback(
            static function (mixed $id) use ($userA, $userB): ?User {
                if ($id === 42) {
                    return $userA;
                }
                if ($id === 43) {
                    return $userB;
                }

                return null;
            }
        );

        $scheduledJob = $this->createStub(ScheduledJob::class);

        // One scheduleJob() call per recipient, each carrying that recipient's email.
        $capturedRecipients = [];
        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->expects(self::exactly(2))
            ->method('scheduleJob')
            ->with(self::callback(function (array $jobData) use (&$capturedRecipients): bool {
                $capturedRecipients[] = self::asArray($jobData['email_config'] ?? [])['recipient_emails'] ?? null;

                return $jobData['type'] === LookupService::JOB_TYPES_EMAIL;
            }))
            ->willReturn($scheduledJob);

        $cmsPreferences = $this->createStub(CmsPreferenceService::class);
        $cmsPreferences->method('getDefaultTimezoneCode')->willReturn('UTC');

        $scheduler = new ActionSchedulerService(
            $conditionEvaluator,
            $scheduleCalculator,
            $configRuntime,
            $jobScheduler,
            $userRepository,
            $this->createStub(DataTableRepository::class),
            new ActionTemplateContextBuilder(new InterpolationService()),
            $cmsPreferences,
            $this->createStub(MailTemplateService::class),
            $this->createStub(ActionTranslationRepository::class),
            $this->createStub(VariableResolverService::class),
        );

        $result = $scheduler->schedule($action, $runtimeConfig, $context, [42, 43]);

        self::assertCount(2, $result, 'Two recipients must fan out into two scheduled jobs.');
        self::assertEqualsCanonicalizing(
            ['qa.a@selfhelp.test', 'qa.b@selfhelp.test'],
            $capturedRecipients,
            'Each fanned-out job must target exactly one recipient email.'
        );
    }

    public function testUnknownRecipientIsSkippedWithoutScheduling(): void
    {
        $executionDate = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));
        $job = [ActionConfig::JOB_NAME => 'qa_job', ActionConfig::NOTIFICATION => []];
        $block = [ActionConfig::JOBS => [$job]];
        $runtimeConfig = [ActionConfig::BLOCKS => [$block]];

        $context = new ActionTriggerContext(
            $this->createStub(DataTable::class),
            $this->createStub(DataRow::class),
            [],
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            42,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
        );
        $action = $this->createStub(\App\Entity\Action::class);
        $action->method('getName')->willReturn('qa_action');

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $scheduleCalculator = $this->createStub(ActionScheduleCalculatorService::class);
        $scheduleCalculator->method('calculateDates')->willReturn([$executionDate]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('getIterationCount')->willReturn(1);
        $configRuntime->method('selectBlocksForIteration')->willReturn([$block]);

        // Recipient id resolves to no user -> scheduleSingleJob returns false.
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn(null);

        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->expects(self::never())->method('scheduleJob');

        $cmsPreferences = $this->createStub(CmsPreferenceService::class);
        $cmsPreferences->method('getDefaultTimezoneCode')->willReturn('UTC');

        $scheduler = new ActionSchedulerService(
            $conditionEvaluator,
            $scheduleCalculator,
            $configRuntime,
            $jobScheduler,
            $userRepository,
            $this->createStub(DataTableRepository::class),
            new ActionTemplateContextBuilder(new InterpolationService()),
            $cmsPreferences,
            $this->createStub(MailTemplateService::class),
            $this->createStub(ActionTranslationRepository::class),
            $this->createStub(VariableResolverService::class),
        );

        self::assertSame([], $scheduler->schedule($action, $runtimeConfig, $context, [999]));
    }

    public function testLiteralExtraAddressFansOutIntoOwnUnlinkedEmailJob(): void
    {
        $executionDate = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));

        // Mirrors the reported case: "{{recipient.email}};qa.therapist@selfhelp.test".
        $job = [
            ActionConfig::JOB_NAME => 'qa_email_job',
            ActionConfig::NOTIFICATION => [
                ActionConfig::NOTIFICATION_TYPES => LookupService::NOTIFICATION_TYPES_EMAIL,
                ActionConfig::SUBJECT => 'QA subject',
                ActionConfig::BODY => 'QA body',
                ActionConfig::RECIPIENT => '{{recipient.email}};qa.therapist@selfhelp.test',
            ],
        ];
        $block = [ActionConfig::JOBS => [$job]];
        $runtimeConfig = [ActionConfig::BLOCKS => [$block]];

        $context = new ActionTriggerContext(
            $this->createStub(DataTable::class),
            $this->createStub(DataRow::class),
            [],
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            42,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
        );
        $action = $this->createStub(\App\Entity\Action::class);
        $action->method('getName')->willReturn('qa_action');

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $scheduleCalculator = $this->createStub(ActionScheduleCalculatorService::class);
        $scheduleCalculator->method('calculateDates')->willReturn([$executionDate]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('getIterationCount')->willReturn(1);
        $configRuntime->method('selectBlocksForIteration')->willReturn([$block]);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getEmail')->willReturn('qa.recipient@selfhelp.test');
        $user->method('getName')->willReturn('QA Recipient');
        $user->method('getValidationCodes')->willReturn(new ArrayCollection());

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);
        // The extra hardcoded address belongs to no user.
        $userRepository->method('findOneBy')->willReturn(null);

        $scheduledJob = $this->createStub(ScheduledJob::class);

        $captured = [];
        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->expects(self::exactly(2))
            ->method('scheduleJob')
            ->with(self::callback(function (array $jobData) use (&$captured): bool {
                $emailConfig = self::asArray($jobData['email_config'] ?? []);
                $recipients = self::asList($jobData['recipients'] ?? []);
                $first = self::asArray($recipients[0] ?? []);
                $captured[] = [
                    'email' => $emailConfig['recipient_emails'] ?? null,
                    'user_id' => $jobData['user_id'] ?? null,
                    'snapshot_user_id' => $first['user_id'] ?? null,
                    'resolved_from' => $first['resolved_from'] ?? null,
                ];

                return $jobData['type'] === LookupService::JOB_TYPES_EMAIL;
            }))
            ->willReturn($scheduledJob);

        $cmsPreferences = $this->createStub(CmsPreferenceService::class);
        $cmsPreferences->method('getDefaultTimezoneCode')->willReturn('UTC');

        $scheduler = new ActionSchedulerService(
            $conditionEvaluator,
            $scheduleCalculator,
            $configRuntime,
            $jobScheduler,
            $userRepository,
            $this->createStub(DataTableRepository::class),
            new ActionTemplateContextBuilder(new InterpolationService()),
            $cmsPreferences,
            $this->createStub(MailTemplateService::class),
            $this->createStub(ActionTranslationRepository::class),
            $this->createStub(VariableResolverService::class),
        );

        $result = $scheduler->schedule($action, $runtimeConfig, $context, [42]);

        self::assertCount(2, $result, 'Recipient template + one hardcoded address must fan out into two jobs.');

        $primary = self::asArray($captured[0] ?? null);
        $extra = self::asArray($captured[1] ?? null);

        // {{recipient.email}} must NOT collapse to empty; the leading address is
        // the resolved recipient, the second is the external therapist mailbox.
        self::assertSame('qa.recipient@selfhelp.test', $primary['email'] ?? null);
        self::assertSame(42, $primary['user_id'] ?? null, 'Primary recipient job keeps the action recipient user id.');
        self::assertSame(42, $primary['snapshot_user_id'] ?? null);
        self::assertSame('user_email', $primary['resolved_from'] ?? null);

        self::assertSame('qa.therapist@selfhelp.test', $extra['email'] ?? null);
        self::assertNull($extra['user_id'], 'External address job is unlinked from any user.');
        self::assertNull($extra['snapshot_user_id']);
        self::assertSame('external_email', $extra['resolved_from'] ?? null);
    }

    public function testSenderIdentityFallsBackToMailConfigNotHardcodedDefaults(): void
    {
        $executionDate = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));

        $job = [
            ActionConfig::JOB_NAME => 'qa_email_job',
            ActionConfig::NOTIFICATION => [
                ActionConfig::NOTIFICATION_TYPES => LookupService::NOTIFICATION_TYPES_EMAIL,
                ActionConfig::SUBJECT => 'QA subject',
                ActionConfig::BODY => 'QA body',
                ActionConfig::RECIPIENT => '{{recipient.email}}',
                // from_email/from_name/reply_to intentionally omitted.
            ],
        ];
        $block = [ActionConfig::JOBS => [$job]];
        $runtimeConfig = [ActionConfig::BLOCKS => [$block]];

        $context = new ActionTriggerContext(
            $this->createStub(DataTable::class),
            $this->createStub(DataRow::class),
            [],
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            42,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
        );
        $action = $this->createStub(\App\Entity\Action::class);
        $action->method('getName')->willReturn('qa_action');

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $scheduleCalculator = $this->createStub(ActionScheduleCalculatorService::class);
        $scheduleCalculator->method('calculateDates')->willReturn([$executionDate]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('getIterationCount')->willReturn(1);
        $configRuntime->method('selectBlocksForIteration')->willReturn([$block]);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getEmail')->willReturn('qa.recipient@selfhelp.test');
        $user->method('getValidationCodes')->willReturn(new ArrayCollection());

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);

        $captured = [];
        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->method('scheduleJob')
            ->with(self::callback(function (array $jobData) use (&$captured): bool {
                $captured = self::asArray($jobData['email_config'] ?? []);

                return true;
            }))
            ->willReturn($this->createStub(ScheduledJob::class));

        $cmsPreferences = $this->createStub(CmsPreferenceService::class);
        $cmsPreferences->method('getDefaultTimezoneCode')->willReturn('UTC');

        // Admin-configured mail config (sh-mail-config page) drives From/Reply-To.
        $mailTemplate = $this->createStub(MailTemplateService::class);
        $mailTemplate->method('resolveGlobalConfig')->willReturn([
            'from_email' => 'qa.clinic@selfhelp.test',
            'from_name' => 'Clinic Team',
            'reply_to' => 'qa.support@selfhelp.test',
            'is_html' => true,
        ]);

        $scheduler = new ActionSchedulerService(
            $conditionEvaluator,
            $scheduleCalculator,
            $configRuntime,
            $jobScheduler,
            $userRepository,
            $this->createStub(DataTableRepository::class),
            new ActionTemplateContextBuilder(new InterpolationService()),
            $cmsPreferences,
            $mailTemplate,
            $this->createStub(ActionTranslationRepository::class),
            $this->createStub(VariableResolverService::class),
        );

        $scheduler->schedule($action, $runtimeConfig, $context, [42]);

        // From/Reply-To come from the admin mail config, NOT the hardcoded
        // MailTemplateDefaults sender (MailTemplateDefaults::FROM_EMAIL / FROM_NAME).
        $emailConfig = self::asArray($captured);
        self::assertSame('qa.clinic@selfhelp.test', $emailConfig['from_email'] ?? null);
        self::assertSame('Clinic Team', $emailConfig['from_name'] ?? null);
        self::assertSame('qa.support@selfhelp.test', $emailConfig['reply_to'] ?? null);
    }

    public function testEmailSubjectAndBodyAreResolvedFromTranslationKeys(): void
    {
        $executionDate = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));

        // The admin UI stores subject/body as CMS translation keys, not text.
        $job = [
            ActionConfig::JOB_NAME => 'qa_email_job',
            ActionConfig::NOTIFICATION => [
                ActionConfig::NOTIFICATION_TYPES => LookupService::NOTIFICATION_TYPES_EMAIL,
                ActionConfig::SUBJECT => 'block_0.job_0.notification.subject',
                ActionConfig::BODY => 'block_0.job_0.notification.body',
                ActionConfig::RECIPIENT => '{{recipient.email}}',
            ],
        ];
        $block = [ActionConfig::JOBS => [$job]];
        $runtimeConfig = [ActionConfig::BLOCKS => [$block]];

        $context = new ActionTriggerContext(
            $this->createStub(DataTable::class),
            $this->createStub(DataRow::class),
            [],
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            42,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
        );
        $action = $this->createStub(\App\Entity\Action::class);
        $action->method('getId')->willReturn(7);
        $action->method('getName')->willReturn('qa_action');

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $scheduleCalculator = $this->createStub(ActionScheduleCalculatorService::class);
        $scheduleCalculator->method('calculateDates')->willReturn([$executionDate]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('getIterationCount')->willReturn(1);
        $configRuntime->method('selectBlocksForIteration')->willReturn([$block]);

        $language = $this->createStub(\App\Entity\Language::class);
        $language->method('getId')->willReturn(2);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getEmail')->willReturn('qa.recipient@selfhelp.test');
        $user->method('getLanguage')->willReturn($language);
        $user->method('getValidationCodes')->willReturn(new ArrayCollection());

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);

        $translationRepo = $this->createStub(ActionTranslationRepository::class);
        $translationRepo->method('findByActionKeyAndLanguage')->willReturnCallback(
            static function (int $actionId, string $key, int $languageId): ?\App\Entity\ActionTranslation {
                $map = [
                    'block_0.job_0.notification.subject' => 'Welcome to the clinic',
                    'block_0.job_0.notification.body' => 'Your appointment is confirmed.',
                ];
                if ($actionId === 7 && $languageId === 2 && isset($map[$key])) {
                    return (new \App\Entity\ActionTranslation())->setContent($map[$key]);
                }

                return null;
            }
        );

        $captured = [];
        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->method('scheduleJob')
            ->with(self::callback(function (array $jobData) use (&$captured): bool {
                $captured = self::asArray($jobData['email_config'] ?? []);

                return true;
            }))
            ->willReturn($this->createStub(ScheduledJob::class));

        $cmsPreferences = $this->createStub(CmsPreferenceService::class);
        $cmsPreferences->method('getDefaultTimezoneCode')->willReturn('UTC');

        $scheduler = new ActionSchedulerService(
            $conditionEvaluator,
            $scheduleCalculator,
            $configRuntime,
            $jobScheduler,
            $userRepository,
            $this->createStub(DataTableRepository::class),
            new ActionTemplateContextBuilder(new InterpolationService()),
            $cmsPreferences,
            $this->createStub(MailTemplateService::class),
            $translationRepo,
            $this->createStub(VariableResolverService::class),
        );

        $scheduler->schedule($action, $runtimeConfig, $context, [42]);

        // The persisted email config carries the resolved translated text, not
        // the raw "block_0.job_0.notification.*" keys.
        $emailConfig = self::asArray($captured);
        self::assertSame('Welcome to the clinic', $emailConfig['subject'] ?? null);
        self::assertSame('Your appointment is confirmed.', $emailConfig['body'] ?? null);
    }

    /**
     * Issue #56 v2 golden test: an action email authored with the picker's
     * `record.<field_key>` and `system.*` tokens must actually resolve at render
     * time, not just `recipient.*`. `record.*` comes from the trigger's submitted
     * values (keyed by the immutable field_key) and `system.*` from the shared
     * VariableResolverService, so every token the action picker offers renders.
     */
    public function testEmailSubjectAndBodyResolveRecordAndSystemScopes(): void
    {
        $executionDate = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));

        $job = [
            ActionConfig::JOB_NAME => 'qa_email_job',
            ActionConfig::NOTIFICATION => [
                ActionConfig::NOTIFICATION_TYPES => LookupService::NOTIFICATION_TYPES_EMAIL,
                ActionConfig::SUBJECT => 'Re: {{record.section_230}}',
                ActionConfig::BODY => 'Hi {{recipient.name}}, you logged "{{record.section_230}}" on {{system.project_name}}.',
                ActionConfig::RECIPIENT => '{{recipient.email}}',
            ],
        ];
        $block = [ActionConfig::JOBS => [$job]];
        $runtimeConfig = [ActionConfig::BLOCKS => [$block]];

        // Submitted values are keyed by the immutable storage field_key, exactly
        // matching the picker's `record.<field_key>` tokens.
        $context = new ActionTriggerContext(
            $this->createStub(DataTable::class),
            $this->createStub(DataRow::class),
            ['section_230' => 'Felt calm today', 'record_id' => 200],
            LookupService::ACTION_TRIGGER_TYPES_FINISHED,
            42,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
        );
        $action = $this->createStub(\App\Entity\Action::class);
        $action->method('getId')->willReturn(1);
        $action->method('getName')->willReturn('qa_action');

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $scheduleCalculator = $this->createStub(ActionScheduleCalculatorService::class);
        $scheduleCalculator->method('calculateDates')->willReturn([$executionDate]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('getIterationCount')->willReturn(1);
        $configRuntime->method('selectBlocksForIteration')->willReturn([$block]);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('getEmail')->willReturn('qa.recipient@selfhelp.test');
        $user->method('getName')->willReturn('QA Recipient');
        $user->method('getValidationCodes')->willReturn(new ArrayCollection());

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('find')->willReturn($user);

        $captured = [];
        $jobScheduler = $this->createMock(JobSchedulerService::class);
        $jobScheduler->method('scheduleJob')
            ->with(self::callback(function (array $jobData) use (&$captured): bool {
                $captured = self::asArray($jobData['email_config'] ?? []);

                return true;
            }))
            ->willReturn($this->createStub(ScheduledJob::class));

        $cmsPreferences = $this->createStub(CmsPreferenceService::class);
        $cmsPreferences->method('getDefaultTimezoneCode')->willReturn('UTC');

        // system.* resolves through the same service a page/section render uses.
        $variableResolver = $this->createStub(VariableResolverService::class);
        $variableResolver->method('getAllVariables')->willReturn([
            'project_name' => 'QA Clinic',
            'current_datetime' => '2026-01-01 10:00:00',
        ]);

        $scheduler = new ActionSchedulerService(
            $conditionEvaluator,
            $scheduleCalculator,
            $configRuntime,
            $jobScheduler,
            $userRepository,
            $this->createStub(DataTableRepository::class),
            new ActionTemplateContextBuilder(new InterpolationService()),
            $cmsPreferences,
            $this->createStub(MailTemplateService::class),
            $this->createStub(ActionTranslationRepository::class),
            $variableResolver,
        );

        $scheduler->schedule($action, $runtimeConfig, $context, [42]);

        $emailConfig = self::asArray($captured);
        self::assertSame(
            'Re: Felt calm today',
            $emailConfig['subject'] ?? null,
            '{{record.<field_key>}} must resolve in the action email subject.',
        );
        self::assertSame(
            'Hi QA Recipient, you logged "Felt calm today" on QA Clinic.',
            $emailConfig['body'] ?? null,
            'recipient.*, record.<field_key> and system.* must all resolve in the action email body.',
        );
    }
}
