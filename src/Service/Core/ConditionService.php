<?php

namespace App\Service\Core;

use App\Repository\UserRepository;
use App\Service\Cache\Core\CacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly CacheService $cache,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly UserContextAwareService $userContextAwareService
    ) {
    }


    /**
     * Get the user's selected language ID
     *
     * @param int $userId User ID
     * @return int|null Language ID or null if not found
     */
    private function getUserLanguageId(int $userId): ?int
    {
        $cacheKey = "user_language_{$userId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_CONDITIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem($cacheKey, function () use ($userId) {
                return $this->userRepository->getUserLanguageId($userId);
            });
    }

    /**
     * Get the user's last login date
     *
     * @param int $userId User ID
     * @return string|null Last login date or null if not found
     */
    private function getUserLastLoginDate(int $userId): ?string
    {
        $cacheKey = "user_last_login_{$userId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_CONDITIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem($cacheKey, function () use ($userId) {
                return $this->userRepository->getUserLastLoginDate($userId);
            });
    }


    /**
     * Evaluate a JSON Logic condition
     *
     * @param array|string|null $condition JSON Logic condition array or JSON string
     * @param int|null $userId User ID (optional, defaults to current user)
     * @param string $section Section name for error reporting
     * @return array Result with 'result' (bool) and optional 'fields' for errors
     */
    public function evaluateCondition(array|string|null $condition, ?int $userId = null, string $section = 'system'): array
    {
        // No condition means it passes
        if (!$condition) {
            return ['result' => true];
        }

        // Get current user if not specified
        if ($userId === null) {
            $user = $this->userContextAwareService->getCurrentUser();
            $userId = $user ? $user->getId() : null;

            // If no user ID available, condition cannot be evaluated
            if ($userId === null) {
                return ['result' => false, 'fields' => 'No user context available for condition evaluation'];
            }
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

            // Extract variables needed for this condition (lazy loading)
            $requiredVariables = $this->extractVariablesFromCondition($condition);

            // Fix operator parameter order for React Query Builder compatibility
            $fixedCondition = $this->fixOperatorParameters($condition);

            // Get only the variables that are actually needed
            $variables = $this->getConditionVariables($userId, $requiredVariables);

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
     * Get condition variables for a user and current context (lazy loading)
     *
     * @param int $userId User ID
     * @param array $requiredVariables Array of variable names that are needed
     * @return array Associative array of variable names to values
     */
    private function getConditionVariables(int $userId, array $requiredVariables = []): array
    {
        $variables = [];

        // If no specific variables required, load all (backward compatibility)
        if (empty($requiredVariables)) {
            $requiredVariables = [
                'user_group',
                'language',
                'last_login',
                'current_date',
                'current_datetime',
                'current_time',
                'page_keyword',
                'platform'
            ];
        }

        // Load user-related variables only if needed
        if (in_array('user_group', $requiredVariables) || in_array('user_groups', $requiredVariables)) {
            $variables['user_group'] = $this->getUserGroups($userId);
        }

        if (in_array('language', $requiredVariables) || in_array('user_language', $requiredVariables)) {
            $languageId = $this->getUserLanguageId($userId) ?? 2;
            $variables['language'] = $languageId;
        }

        if (in_array('last_login', $requiredVariables) || in_array('user_last_login', $requiredVariables)) {
            $lastLogin = $this->getUserLastLoginDate($userId) ?? '';
            $variables['last_login'] = $lastLogin;
        }

        // Load date/time variables only if needed
        if (in_array('current_date', $requiredVariables)) {
            $variables['current_date'] = date('Y-m-d');
        }

        if (in_array('current_datetime', $requiredVariables)) {
            $variables['current_datetime'] = date('Y-m-d H:i');
        }

        if (in_array('current_time', $requiredVariables)) {
            $variables['current_time'] = date('H:i');
        }

        // Load context variables only if needed
        $request = $this->requestStack->getCurrentRequest();

        if (in_array('page_keyword', $requiredVariables)) {
            $pageKeyword = '';
            if ($request) {
                try {
                    $currentRoute = $this->router->match($request->getPathInfo());
                    $pageKeyword = $currentRoute['_route'] ?? '';
                } catch (\Exception $e) {
                    // Route matching failed, keep empty
                }
            }
            $variables['page_keyword'] = $pageKeyword;
        }

        if (in_array('platform', $requiredVariables)) {
            $platform = 'web';
            if ($request && $request->request->get('mobile')) {
                $platform = 'mobile';
            }
            $variables['platform'] = $platform;
        }

        // Add custom variables from request (supporting {{var}} syntax from frontend)
        if ($request) {
            $allRequestData = array_merge(
                $request->query->all(),
                $request->request->all()
            );

            foreach ($allRequestData as $key => $value) {
                // Check if this custom variable is required
                if (in_array($key, $requiredVariables) || in_array("{{$key}}", $requiredVariables)) {
                    $variables[$key] = $value;
                    $variables["{{$key}}"] = $value; // Also support {{var}} format
                }
            }
        }

        return $variables;
    }


    /**
     * Get all user's group names
     *
     * @param int $userId User ID
     * @return array Array of group names
     */
    private function getUserGroups(int $userId): array
    {
        $cacheKey = "user_groups_{$userId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_CONDITIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getItem($cacheKey, function () use ($userId) {
                return $this->userRepository->getUserGroupNames($userId);
            });
    }

    /**
     * Filter sections based on conditions
     *
     * @param array $sections Sections array to filter
     * @param int|null $userId User ID for condition evaluation
     * @return array Filtered sections
     */
    public function filterSectionsByConditions(array $sections, ?int $userId = null): array
    {
        return $this->filterSectionsRecursive($sections, $userId);
    }

    /**
     * Recursively filter sections, handling debug mode
     *
     * @param array $sections Sections to filter
     * @param int|null $userId User ID
     * @return array Filtered sections
     */
    private function filterSectionsRecursive(array $sections, ?int $userId): array
    {
        $filteredSections = [];

        foreach ($sections as $section) {
            // Check if section has a condition
            if (isset($section['condition']) && !empty($section['condition'])) {
                $conditionResult = $this->evaluateCondition(
                    $section['condition'],
                    $userId,
                    $section['keyword'] ?? 'unknown'
                );

                // Include the original condition as an object for easier frontend handling
                $conditionObject = $section['condition'];
                if (is_string($conditionObject)) {
                    // Handle double-encoded JSON strings
                    $conditionObject = json_decode($conditionObject, true);
                    if (is_string($conditionObject)) {
                        // If still a string, try decoding again
                        $conditionObject = json_decode($conditionObject, true);
                    }
                }
                $section['condition_debug'] =
                 [
                    "result" => $conditionResult['result'],
                    "error" => $conditionResult['fields'],
                    "variables" => $conditionResult['debug']['variables'],
                    "condition_object" => $conditionObject
                ];

                // Ensure condition is returned as proper JSON string (not escaped)
                if (is_string($section['condition'])) {
                    // Handle escaped JSON strings - decode to get proper JSON string
                    $decoded = json_decode($section['condition']);
                    if (is_string($decoded)) {
                        // If we got a string back, that's the unescaped JSON string we want
                        $section['condition'] = $decoded;
                    } elseif (is_array($decoded) || is_object($decoded)) {
                        // If we got an object/array, encode it back to string
                        $section['condition'] = json_encode($decoded);
                    }
                    // If decode failed or returned null, keep original
                } else {
                    $section['condition'] = json_encode($section['condition']);
                }

                // If condition fails and debug is NOT enabled, skip this section entirely
                if (!$conditionResult['result'] && !(isset($section['debug']) && $section['debug'])) {
                    continue;
                }

                // If condition failed but debug is enabled, remove children
                if (!$conditionResult['result'] && isset($section['debug']) && $section['debug']) {
                    $section['children'] = [];
                }
            }

            // Process children recursively
            if (isset($section['children']) && is_array($section['children'])) {
                $section['children'] = $this->filterSectionsRecursive($section['children'], $userId);
            }

            $filteredSections[] = $section;
        }

        return $filteredSections;
    }
}
