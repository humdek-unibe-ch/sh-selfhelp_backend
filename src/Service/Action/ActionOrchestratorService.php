<?php

namespace App\Service\Action;

use App\Service\Core\LookupService;
use Psr\Log\LoggerInterface;

/**
 * Coordinates end-to-end action processing for create, update, and delete triggers.
 *
 * The orchestrator resolves matching actions, prepares runtime configuration,
 * applies cleanup rules, schedules jobs, and executes any jobs that are due now.
 */
class ActionOrchestratorService
{
    public function __construct(
        private readonly ActionResolverService $resolverService,
        private readonly ActionConfigRuntimeService $configRuntimeService,
        private readonly ActionRecipientResolverService $recipientResolverService,
        private readonly ActionConditionEvaluatorService $conditionEvaluator,
        private readonly ActionCleanupService $cleanupService,
        private readonly ActionSchedulerService $schedulerService,
        private readonly ActionImmediateExecutorService $immediateExecutorService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle a normalized action trigger emitted by the data layer.
     *
     * Reminder cleanup for completed forms is intentionally performed before new
     * action jobs are created so the fresh reminder chain is not immediately deleted.
     *
     * @param ActionTriggerContext $context
     *   The trigger context describing the saved, updated, or deleted record event.
     */
    public function handle(ActionTriggerContext $context): void
    {
        if (
            $context->triggerType === LookupService::ACTION_TRIGGER_TYPES_FINISHED &&
            $context->userId !== null
        ) {
            $this->cleanupService->deleteQueuedReminderJobsForUserAndTable(
                $context->userId,
                $context->dataTable->getId(),
                LookupService::TRANSACTION_BY_BY_SYSTEM
            );
        }

        foreach ($this->resolverService->resolve($context->dataTable, $context->triggerType) as $action) {
            try {
                $runtimeConfig = $this->configRuntimeService->buildRuntimeConfig($action, $context->submittedValues);
                if ($runtimeConfig === []) {
                    continue;
                }

                if (!$this->conditionEvaluator->passes($runtimeConfig[ActionConfig::CONDITION] ?? null, $context->userId, 'action.root')) {
                    continue;
                }

                if (
                    ($runtimeConfig[ActionConfig::CLEAR_EXISTING_JOBS_FOR_RECORD_AND_ACTION] ?? false) === true &&
                    $context->triggerType === LookupService::ACTION_TRIGGER_TYPES_FINISHED
                ) {
                    $this->cleanupService->deleteQueuedJobsForRecordAndAction(
                        $action,
                        $context->dataRow->getId(),
                        LookupService::TRANSACTION_BY_BY_SYSTEM
                    );
                }

                if (($runtimeConfig[ActionConfig::CLEAR_EXISTING_JOBS_FOR_ACTION] ?? false) === true) {
                    $this->cleanupService->deleteQueuedJobsForAction(
                        $action,
                        $context->userId,
                        LookupService::TRANSACTION_BY_BY_SYSTEM
                    );
                }

                $recipientUserIds = $this->recipientResolverService->resolve($runtimeConfig, $context->submittedValues, $context->userId);
                if ($recipientUserIds === []) {
                    continue;
                }

                $scheduledJobs = $this->schedulerService->schedule($action, $runtimeConfig, $context, $recipientUserIds);
                $this->immediateExecutorService->executeDueNow($scheduledJobs, LookupService::TRANSACTION_BY_BY_SYSTEM);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to orchestrate action scheduling', [
                    'actionId' => $action->getId(),
                    'dataTableId' => $context->dataTable->getId(),
                    'dataRowId' => $context->dataRow->getId(),
                    'triggerType' => $context->triggerType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($context->triggerType === LookupService::ACTION_TRIGGER_TYPES_DELETED) {
            $this->cleanupService->deleteQueuedJobsForRecord(
                $context->dataRow->getId(),
                LookupService::TRANSACTION_BY_BY_SYSTEM
            );
        }
    }
}
