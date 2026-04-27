<?php

namespace App\Service\Core;

use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\VariableResolverService;
use App\Service\Core\UserContextAwareService;

/**
 * Service for evaluating JSON Logic conditions for sections
 *
 * This service handles condition checking based on JSON Logic with support for:
 * - User group membership checks
 * - Date/time comparisons
 * - Platform detection
 * - Route-based conditions
 * - Custom variables ({{var}} syntax)
 * - Caching of condition evaluation results
 *
 * Supports all fields from the frontend condition builder:
 * - user_group: array of user's group names
 * - current_date: current date (Y-m-d)
 * - current_datetime: current datetime (Y-m-d H:i)
 * - current_time: current time (H:i)
 * - page_keyword: current route name
 * - platform: 'web' or 'mobile'
 * - language: user's language ID
 * - last_login: user's last login datetime
 */
class ConditionService
{
    public function __construct(
        private readonly VariableResolverService $variableResolverService,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly CmsPreferenceService $cmsPreferenceService
    ) {
    }




    /**
     * Evaluate a JSON Logic condition
     *
     * @param array|string|null $condition JSON Logic condition array or JSON string
     * @param int|null $userId User ID (optional, defaults to current user)
     * @param string $section Section name for error reporting
     * @param int|null $languageId Language ID of the current request — used so conditions
     *                        like `{"==": [{"var":"language"}, 3]}` match the page
     *                        render language, not a hard-coded default. Anonymous
     *                        users have `language` resolved from this value.
     *                        Pass `null` (or omit) when there is no request-scoped
     *                        language (action runtime, scheduled jobs, CLI commands)
     *                        and the CMS-default language should be used instead.
     * @return array Result with 'result' (bool) and optional 'fields' for errors
     */
    public function evaluateCondition(array|string|null $condition, ?int $userId = null, string $section = 'system', ?int $languageId = null): array
    {
        // Non-page contexts (actions, scheduled jobs, CLI) don't carry a
        // request language — fall back to the CMS preference, then to 1
        // (the seeded "English" id) if no preference exists yet.
        if ($languageId === null) {
            $languageId = $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;
        }
        // No condition means it passes
        if (!$condition) {
            return ['result' => true];
        }

        // Get current user if not specified. Anonymous visitors are allowed —
        // open-access pages must still be able to evaluate conditions that use
        // system variables such as `language`, `current_date`, `platform`, etc.
        // When $userId stays null, VariableResolverService falls back to safe
        // defaults (empty user_group, languageId from request, empty user_name/email),
        // so the condition simply evaluates with an anonymous-user variable set
        // instead of crashing the whole page.
        if ($userId === null) {
            $user = $this->userContextAwareService->getCurrentUser();
            $userId = $user ? $user->getId() : null;
        }

        $result = ['result' => false, 'fields' => []];

        // Store original condition for debugging
        $originalCondition = is_string($condition) ? $condition : json_encode($condition);

        try {
            // Convert string condition to array if needed
            if (is_string($condition)) {
                $originalString = $condition;
                $decoded = json_decode($condition, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return ['result' => false, 'fields' => "Invalid JSON condition in section '{$section}': " . json_last_error_msg() . " (input: {$originalString})"];
                }

                // Handle multiple levels of encoded JSON strings (common in database storage)
                $condition = $decoded;
                $decodeAttempts = 0;
                $maxDecodeAttempts = 5; // Prevent infinite loops

                while (is_string($condition) && $decodeAttempts < $maxDecodeAttempts) {
                    $nextDecode = json_decode($condition, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // If this decode failed, keep the previous result
                        break;
                    }
                    $condition = $nextDecode;
                    $decodeAttempts++;
                }

                if (!is_array($condition) && !is_object($condition)) {
                    // Handle primitive JSON values that can be valid conditions
                    if (is_bool($condition)) {
                        // Boolean conditions: true = always pass, false = always fail
                        return ['result' => $condition];
                    }
                    // For other primitive types (string, number, null), treat as invalid
                    return ['result' => false, 'fields' => "Condition must be a JSON object/array in section '{$section}' (got primitive type '" . gettype($condition) . "' with value: " . json_encode($condition) . ", original: {$originalString})"];
                }
            }

            // Ensure condition is an array for processing (JsonLogic can handle both)
            if (is_object($condition)) {
                $condition = (array) $condition;
            }

            // Extract variables referenced by the condition. Used for debug output;
            // we always load the full anonymous-safe variable set so JsonLogic
            // can resolve nested expressions reliably.
            $requiredVariables = $this->extractVariablesFromCondition($condition);

            // Fix operator parameter order for React Query Builder compatibility
            $fixedCondition = $this->fixOperatorParameters($condition);

            $variables = $this->getConditionVariables($userId, $languageId);

            // Apply JsonLogic with the fixed condition and loaded variables
            $logicResult = \JWadhams\JsonLogic::apply($fixedCondition, $variables);
            $result['result'] = \JWadhams\JsonLogic::truthy($logicResult);

            // Add debugging information
            $result['debug'] = [
                'original_condition' => $originalCondition,
                'fixed_condition' => $fixedCondition,
                'logic_result' => $logicResult,
                'required_variables' => $requiredVariables,
                'variables' => $variables,
                'literal_values_detected' => $this->detectLiteralValuesInCondition($condition)
            ];

        } catch (\Exception | \ArgumentCountError $e) {
            $result['fields'] = "JsonLogic evaluation failed in section '{$section}': " . $e->getMessage();
        }

        return $result;
    }


    /**
     * Extract all variable names referenced in a condition
     *
     * @param mixed $condition The condition to analyze (array expected)
     * @return array Array of variable names used in the condition
     */
    private function extractVariablesFromCondition($condition): array
    {
        $variables = [];

        // Ensure condition is an array
        if (!is_array($condition)) {
            return $variables;
        }

        $this->extractVariablesRecursive($condition, $variables);

        return array_unique($variables);
    }

    /**
     * Recursively extract variable names from condition
     *
     * @param mixed $condition The condition element to process
     * @param array &$variables Reference to array that collects variable names
     */
    private function extractVariablesRecursive($condition, array &$variables): void
    {
        if (is_array($condition)) {
            foreach ($condition as $key => $value) {
                if ($key === 'var' && is_string($value)) {
                    // Skip literal values that look like numbers or interpolated values
                    // These should not be treated as variable names to look up
                    if ($this->isLiteralValue($value)) {
                        // Don't add literal values to variables list
                        continue;
                    }

                    // Handle wrapped variables like {{var}}
                    if (preg_match('/^\{\{(.+)\}\}$/', $value, $matches)) {
                        $variables[] = $matches[1]; // Extract inner variable name
                        $variables[] = $value; // Also include the wrapped version
                    } else {
                        $variables[] = $value;
                    }
                } else {
                    $this->extractVariablesRecursive($value, $variables);
                }
            }
        }
    }

    /**
     * Check if a value should be treated as a literal value rather than a variable name
     *
     * @param string $value The value to check
     * @return bool True if this should be treated as a literal value
     */
    private function isLiteralValue(string $value): bool
    {
        // Check if it's a number (integer or float)
        if (is_numeric($value)) {
            return true;
        }

        // Check if it looks like an interpolated record ID or similar
        // This handles cases where {{record_id}} gets replaced with "38"
        // We consider it literal if it doesn't match known variable patterns
        $knownVariables = [
            'user_group', 'user_groups', 'language', 'user_language', 'last_login', 'user_last_login',
            'current_date', 'current_datetime', 'current_time', 'page_keyword', 'platform'
        ];

        return !in_array($value, $knownVariables) && !preg_match('/^\{\{(.+)\}\}$/', $value);
    }

    /**
     * Detect literal values in a condition for debugging purposes
     *
     * @param mixed $condition The condition to analyze
     * @return array Array of literal values found in the condition
     */
    private function detectLiteralValuesInCondition($condition): array
    {
        $literalValues = [];

        if (is_array($condition)) {
            foreach ($condition as $key => $value) {
                if ($key === 'var' && is_string($value) && $this->isLiteralValue($value)) {
                    $literalValues[] = $value;
                } elseif (is_array($value)) {
                    $literalValues = array_merge($literalValues, $this->detectLiteralValuesInCondition($value));
                }
            }
        }

        return array_unique($literalValues);
    }

    /**
     * Fix JsonLogic operators that have parameter order differences with React Query Builder
     *
     * @param array $condition The condition array to fix
     * @return mixed The fixed condition (array or literal value)
     */
    private function fixOperatorParameters(array $condition): mixed
    {
        // Special case: if this is a single {"var": "literal_value"} structure
        if (count($condition) === 1 && isset($condition['var']) && is_string($condition['var']) && $this->isLiteralValue($condition['var'])) {
            // Return the literal value directly instead of the var structure
            return $condition['var'];
        }

        $result = [];

        foreach ($condition as $key => $value) {
            if (($key === 'in' || $key === 'notIn') && is_array($value) && count($value) === 2) {
                // React Query Builder generates ["in", [field_value, [selected_value]]]
                // For array fields like user_group, we want to check if selected_value is in field_value
                // JsonLogic expects ["in", [selected_value, field_value]]

                $fieldValue = $value[0];      // e.g., {"var": "user_group"}
                $selectedValues = $value[1];  // e.g., ["admin"]

                // If selectedValues is an array, we need to check if any selected value is in the field
                // For now, handle the common case where selectedValues is a single-element array
                if (is_array($selectedValues) && count($selectedValues) === 1) {
                    $result[$key] = [
                        is_array($selectedValues[0]) ? $this->fixOperatorParameters($selectedValues[0]) : $selectedValues[0],
                        is_array($fieldValue) ? $this->fixOperatorParameters($fieldValue) : $fieldValue
                    ];
                } elseif (is_string($selectedValues)) {
                    // If selectedValues is a string, use it directly
                    $result[$key] = [
                        $selectedValues,
                        is_array($fieldValue) ? $this->fixOperatorParameters($fieldValue) : $fieldValue
                    ];
                } else {
                    // Fallback: swap parameters with recursive processing
                    $result[$key] = [
                        is_array($value[1]) ? $this->fixOperatorParameters($value[1]) : $value[1],
                        is_array($value[0]) ? $this->fixOperatorParameters($value[0]) : $value[0]
                    ];
                }
            } elseif ($key === 'var' && is_string($value)) {
                // Handle var operators - check if this is a literal value that shouldn't be looked up
                if ($this->isLiteralValue($value)) {
                    // For literal values, return the value directly instead of the var structure
                    // But we need to handle this at the parent level, so we'll keep the var structure for now
                    // and let the special case at the top of this method handle it
                    $result[$key] = $value;
                } else {
                    // For actual variables, keep the var structure
                    $result[$key] = $value;
                }
            } elseif (is_array($value)) {
                $result[$key] = $this->fixOperatorParameters($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get condition variables for a user and current context.
     *
     * @param int|null $userId User ID (null for anonymous visitors on open-access pages)
     * @param int $languageId Language of the current request; used for the `language` system variable.
     * @return array Associative array of variable names to values
     */
    private function getConditionVariables(?int $userId, int $languageId = 1): array
    {
        // VariableResolverService handles userId === null by returning anonymous-safe defaults.
        // Pass $languageId so conditions that compare against `language` evaluate
        // against the page-render language, not a hard-coded default of 1.
        return $this->variableResolverService->getAllVariables($userId, $languageId, false);
    }

    /**
     * Build the `condition_debug` payload that the frontend renders below
     * a section when `debug` is enabled.
     *
     * Single source of truth — `PageService::evaluateSectionCondition()` calls
     * this so the wire shape is identical wherever a condition is evaluated.
     *
     * Note: `evaluateCondition()` may return early without a `debug` key
     * (invalid JSON, boolean primitive, exception). The null-coalesces below
     * guard those paths so open-access / anonymous hits never 500 under
     * `APP_DEBUG=1`.
     *
     * @param array{result: bool, fields?: mixed, debug?: array<string, mixed>} $conditionResult
     * @param array|string|null $rawCondition The original condition value off the section
     * @return array{result: bool, error: mixed, variables: array<string, mixed>, condition_object: mixed}
     */
    public function buildConditionDebug(array $conditionResult, array|string|null $rawCondition): array
    {
        $conditionObject = $rawCondition;
        if (is_string($conditionObject)) {
            // Handle double-encoded JSON strings
            $conditionObject = json_decode($conditionObject, true);
            if (is_string($conditionObject)) {
                $conditionObject = json_decode($conditionObject, true);
            }
        }

        return [
            'result' => $conditionResult['result'],
            'error' => $conditionResult['fields'] ?? [],
            'variables' => $conditionResult['debug']['variables'] ?? [],
            'condition_object' => $conditionObject,
        ];
    }
}
