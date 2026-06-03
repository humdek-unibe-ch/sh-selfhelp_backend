<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Action;

use App\Entity\Action;
use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Entity\ScheduledJob;
use App\Service\Action\ActionCleanupService;
use App\Service\Action\ActionConditionEvaluatorService;
use App\Service\Action\ActionConfig;
use App\Service\Action\ActionConfigRuntimeService;
use App\Service\Action\ActionImmediateExecutorService;
use App\Service\Action\ActionOrchestratorService;
use App\Service\Action\ActionRecipientResolverService;
use App\Service\Action\ActionResolverService;
use App\Service\Action\ActionSchedulerService;
use App\Service\Action\ActionTriggerContext;
use App\Service\Core\LookupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unit test for the action orchestrator control flow. Every collaborator is a
 * deterministic double so the test asserts orchestration decisions only, never
 * real scheduling/DB behaviour.
 */
final class ActionOrchestratorServiceTest extends TestCase
{
    private function context(string $triggerType, ?int $userId): ActionTriggerContext
    {
        $dataTable = $this->createStub(DataTable::class);
        $dataTable->method('getId')->willReturn(100);
        $dataRow = $this->createStub(DataRow::class);
        $dataRow->method('getId')->willReturn(200);

        return new ActionTriggerContext(
            $dataTable,
            $dataRow,
            ['record_id' => 200, 'id_users' => $userId],
            $triggerType,
            $userId,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
        );
    }

    private function action(): Action
    {
        $action = $this->createStub(Action::class);
        $action->method('getId')->willReturn(1);

        return $action;
    }

    public function testFinishedTriggerCleansRemindersSchedulesAndExecutesDueJobs(): void
    {
        $context = $this->context(LookupService::ACTION_TRIGGER_TYPES_FINISHED, 42);
        $action = $this->action();
        $job = $this->createStub(ScheduledJob::class);

        $resolver = $this->createStub(ActionResolverService::class);
        $resolver->method('resolve')->willReturn([$action]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('buildRuntimeConfig')->willReturn(['anything' => true]);

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $recipientResolver = $this->createStub(ActionRecipientResolverService::class);
        $recipientResolver->method('resolve')->willReturn([42]);

        $cleanup = $this->createMock(ActionCleanupService::class);
        $cleanup->expects(self::once())
            ->method('deleteQueuedReminderJobsForUserAndTable')
            ->with(42, 100, LookupService::TRANSACTION_BY_BY_SYSTEM);
        $cleanup->expects(self::never())->method('deleteQueuedJobsForRecord');

        $scheduler = $this->createMock(ActionSchedulerService::class);
        $scheduler->expects(self::once())
            ->method('schedule')
            ->with($action, ['anything' => true], $context, [42])
            ->willReturn([$job]);

        $immediate = $this->createMock(ActionImmediateExecutorService::class);
        $immediate->expects(self::once())
            ->method('executeDueNow')
            ->with([$job], LookupService::TRANSACTION_BY_BY_SYSTEM);

        $this->orchestrator($resolver, $configRuntime, $recipientResolver, $conditionEvaluator, $cleanup, $scheduler, $immediate)
            ->handle($context);
    }

    public function testDeletedTriggerDeletesQueuedJobsForRecordAndDoesNotSchedule(): void
    {
        $context = $this->context(LookupService::ACTION_TRIGGER_TYPES_DELETED, null);

        $resolver = $this->createStub(ActionResolverService::class);
        $resolver->method('resolve')->willReturn([]);

        $cleanup = $this->createMock(ActionCleanupService::class);
        $cleanup->expects(self::once())
            ->method('deleteQueuedJobsForRecord')
            ->with(200, LookupService::TRANSACTION_BY_BY_SYSTEM);
        $cleanup->expects(self::never())->method('deleteQueuedReminderJobsForUserAndTable');

        $scheduler = $this->createMock(ActionSchedulerService::class);
        $scheduler->expects(self::never())->method('schedule');

        $this->orchestrator(
            $resolver,
            $this->createStub(ActionConfigRuntimeService::class),
            $this->createStub(ActionRecipientResolverService::class),
            $this->createStub(ActionConditionEvaluatorService::class),
            $cleanup,
            $scheduler,
            $this->createStub(ActionImmediateExecutorService::class),
        )->handle($context);
    }

    public function testEmptyRuntimeConfigSkipsScheduling(): void
    {
        $context = $this->context(LookupService::ACTION_TRIGGER_TYPES_UPDATED, 42);

        $resolver = $this->createStub(ActionResolverService::class);
        $resolver->method('resolve')->willReturn([$this->action()]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('buildRuntimeConfig')->willReturn([]);

        $scheduler = $this->createMock(ActionSchedulerService::class);
        $scheduler->expects(self::never())->method('schedule');

        $this->orchestrator(
            $resolver,
            $configRuntime,
            $this->createStub(ActionRecipientResolverService::class),
            $this->createStub(ActionConditionEvaluatorService::class),
            $this->createStub(ActionCleanupService::class),
            $scheduler,
            $this->createStub(ActionImmediateExecutorService::class),
        )->handle($context);
    }

    public function testFailingRootConditionSkipsScheduling(): void
    {
        $context = $this->context(LookupService::ACTION_TRIGGER_TYPES_UPDATED, 42);

        $resolver = $this->createStub(ActionResolverService::class);
        $resolver->method('resolve')->willReturn([$this->action()]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('buildRuntimeConfig')->willReturn([ActionConfig::CONDITION => 'age > 18']);

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(false);

        $scheduler = $this->createMock(ActionSchedulerService::class);
        $scheduler->expects(self::never())->method('schedule');

        $this->orchestrator(
            $resolver,
            $configRuntime,
            $this->createStub(ActionRecipientResolverService::class),
            $conditionEvaluator,
            $this->createStub(ActionCleanupService::class),
            $scheduler,
            $this->createStub(ActionImmediateExecutorService::class),
        )->handle($context);
    }

    public function testEmptyRecipientListSkipsScheduling(): void
    {
        $context = $this->context(LookupService::ACTION_TRIGGER_TYPES_UPDATED, 42);

        $resolver = $this->createStub(ActionResolverService::class);
        $resolver->method('resolve')->willReturn([$this->action()]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('buildRuntimeConfig')->willReturn(['anything' => true]);

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $recipientResolver = $this->createStub(ActionRecipientResolverService::class);
        $recipientResolver->method('resolve')->willReturn([]);

        $scheduler = $this->createMock(ActionSchedulerService::class);
        $scheduler->expects(self::never())->method('schedule');

        $this->orchestrator(
            $resolver,
            $configRuntime,
            $recipientResolver,
            $conditionEvaluator,
            $this->createStub(ActionCleanupService::class),
            $scheduler,
            $this->createStub(ActionImmediateExecutorService::class),
        )->handle($context);
    }

    public function testOrchestrationExceptionIsLoggedAndSwallowed(): void
    {
        $context = $this->context(LookupService::ACTION_TRIGGER_TYPES_UPDATED, 42);

        $resolver = $this->createStub(ActionResolverService::class);
        $resolver->method('resolve')->willReturn([$this->action()]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('buildRuntimeConfig')->willThrowException(new \RuntimeException('boom'));

        $scheduler = $this->createMock(ActionSchedulerService::class);
        $scheduler->expects(self::never())->method('schedule');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $this->orchestrator(
            $resolver,
            $configRuntime,
            $this->createStub(ActionRecipientResolverService::class),
            $this->createStub(ActionConditionEvaluatorService::class),
            $this->createStub(ActionCleanupService::class),
            $scheduler,
            $this->createStub(ActionImmediateExecutorService::class),
            $logger,
        )->handle($context);
    }

    public function testClearExistingJobsForActionFlagTriggersActionCleanup(): void
    {
        $context = $this->context(LookupService::ACTION_TRIGGER_TYPES_FINISHED, 42);
        $action = $this->action();

        $resolver = $this->createStub(ActionResolverService::class);
        $resolver->method('resolve')->willReturn([$action]);

        $configRuntime = $this->createStub(ActionConfigRuntimeService::class);
        $configRuntime->method('buildRuntimeConfig')->willReturn([ActionConfig::CLEAR_EXISTING_JOBS_FOR_ACTION => true]);

        $conditionEvaluator = $this->createStub(ActionConditionEvaluatorService::class);
        $conditionEvaluator->method('passes')->willReturn(true);

        $recipientResolver = $this->createStub(ActionRecipientResolverService::class);
        $recipientResolver->method('resolve')->willReturn([42]);

        $cleanup = $this->createMock(ActionCleanupService::class);
        $cleanup->expects(self::once())
            ->method('deleteQueuedJobsForAction')
            ->with($action, 42, LookupService::TRANSACTION_BY_BY_SYSTEM);

        $scheduler = $this->createStub(ActionSchedulerService::class);
        $scheduler->method('schedule')->willReturn([]);

        $this->orchestrator(
            $resolver,
            $configRuntime,
            $recipientResolver,
            $conditionEvaluator,
            $cleanup,
            $scheduler,
            $this->createStub(ActionImmediateExecutorService::class),
        )->handle($context);
    }

    private function orchestrator(
        ActionResolverService $resolver,
        ActionConfigRuntimeService $configRuntime,
        ActionRecipientResolverService $recipientResolver,
        ActionConditionEvaluatorService $conditionEvaluator,
        ActionCleanupService $cleanup,
        ActionSchedulerService $scheduler,
        ActionImmediateExecutorService $immediate,
        ?LoggerInterface $logger = null,
    ): ActionOrchestratorService {
        return new ActionOrchestratorService(
            $resolver,
            $configRuntime,
            $recipientResolver,
            $conditionEvaluator,
            $cleanup,
            $scheduler,
            $immediate,
            $logger ?? new NullLogger(),
        );
    }
}
