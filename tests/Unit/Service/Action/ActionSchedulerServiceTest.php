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
use App\Repository\DataTableRepository;
use App\Repository\UserRepository;
use App\Service\Action\ActionConditionEvaluatorService;
use App\Service\Action\ActionConfig;
use App\Service\Action\ActionConfigRuntimeService;
use App\Service\Action\ActionScheduleCalculatorService;
use App\Service\Action\ActionSchedulerService;
use App\Service\Action\ActionTriggerContext;
use App\Service\Core\JobSchedulerService;
use App\Service\Core\LookupService;
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
                ActionConfig::RECIPIENT => '@user',
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

        $scheduler = new ActionSchedulerService(
            $conditionEvaluator,
            $scheduleCalculator,
            $configRuntime,
            $jobScheduler,
            $userRepository,
            $this->createStub(DataTableRepository::class),
        );

        $result = $scheduler->schedule($action, $runtimeConfig, $context, [42]);

        self::assertSame([$scheduledJob], $result);
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

        $scheduler = new ActionSchedulerService(
            $conditionEvaluator,
            $scheduleCalculator,
            $configRuntime,
            $jobScheduler,
            $userRepository,
            $this->createStub(DataTableRepository::class),
        );

        self::assertSame([], $scheduler->schedule($action, $runtimeConfig, $context, [999]));
    }
}
