<?php

namespace App\Service\Action;

use App\Service\Core\ConditionService;

/**
 * Evaluates action-runtime conditions across root actions, blocks, jobs, and reminders.
 *
 * This service keeps legacy condition payloads compatible with the Symfony
 * scheduler by accepting empty strings, raw JSON strings, direct JsonLogic
 * arrays, and wrapped `jsonLogic` payloads.
 */
class ActionConditionEvaluatorService
{
    public function __construct(
        private readonly ConditionService $conditionService
    ) {
    }

    /**
     * Determine whether a configured condition passes for the supplied user context.
     *
     * @param array<string, mixed>|string|null $condition
     *   The condition payload read from action configuration or scheduled-job metadata.
     * @param int|null $userId
     *   The user id used to resolve condition variables.
     * @param string $section
     *   A descriptive section label used in downstream debug/error reporting.
     *
     * @return bool
     *   `true` when the condition is empty or evaluates truthy, otherwise `false`.
     */
    public function passes(array|string|null $condition, ?int $userId, string $section): bool
    {
        if ($condition === null) {
            return true;
        }

        if (is_string($condition)) {
            $condition = trim($condition);
            if ($condition === '') {
                return true;
            }

            $decoded = json_decode($condition, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $condition = $decoded;
            } else {
                return (bool) ($this->conditionService->evaluateCondition($condition, $userId, $section)['result'] ?? false);
            }
        }

        if (!is_array($condition)) {
            return (bool) ($this->conditionService->evaluateCondition($condition, $userId, $section)['result'] ?? false);
        }

        if ($condition === []) {
            return true;
        }

        $jsonLogic = $condition[ActionConfig::JSON_LOGIC] ?? $condition;
        if ($jsonLogic === null || $jsonLogic === '' || $jsonLogic === []) {
            return true;
        }

        return (bool) ($this->conditionService->evaluateCondition($jsonLogic, $userId, $section)['result'] ?? false);
    }
}
