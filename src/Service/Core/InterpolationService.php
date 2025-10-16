<?php

namespace App\Service\Core;

use App\Service\Core\BaseService;

/**
 * Service for handling variable interpolation in content fields
 *
 * Replaces {{variable_name}} patterns with actual values from provided data arrays.
 * Supports nested data structures and multiple data sources.
 */
class InterpolationService extends BaseService
{
    /**
     * Replace {{variable_name}} patterns in content with values from provided data
     *
     * @param string $content The content containing {{variable}} patterns
     * @param array $dataArrays One or more arrays containing variable => value mappings
     * @return string The content with variables replaced
     */
    public function interpolate(string $content, array ...$dataArrays): string
    {
        if (empty($content)) {
            return $content;
        }

        // Merge all data arrays into one, with later arrays taking precedence
        $variables = [];
        foreach ($dataArrays as $dataArray) {
            $variables = array_merge($variables, $this->flattenArray($dataArray));
        }

        // Find all {{variable}} patterns
        preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches);

        if (empty($matches[1])) {
            return $content;
        }

        $result = $content;

        // Replace each variable
        foreach ($matches[1] as $variable) {
            $variable = trim($variable); // Remove any whitespace

            if (isset($variables[$variable])) {
                $value = $variables[$variable];

                // Convert arrays/objects to JSON strings for replacement
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif ($value === null) {
                    $value = '';
                } else {
                    $value = (string) $value;
                }

                $result = str_replace('{{' . $variable . '}}', $value, $result);
            }
            // If variable not found, leave it as-is (don't remove the {{variable}})
        }

        return $result;
    }

    /**
     * Interpolate variables in an array of content fields recursively
     *
     * @param array $contentArray Array containing content fields to interpolate
     * @param array $dataArrays One or more arrays containing variable => value mappings
     * @return array The content array with variables replaced
     */
    public function interpolateArray(array $contentArray, array ...$dataArrays): array
    {
        $result = [];

        foreach ($contentArray as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->interpolate($value, ...$dataArrays);
            } elseif (is_array($value)) {
                $result[$key] = $this->interpolateArray($value, ...$dataArrays);
            } elseif (is_object($value)) {
                // For objects, we'll convert to array, interpolate, then back to object
                $arrayValue = json_decode(json_encode($value), true);
                $interpolatedArray = $this->interpolateArray($arrayValue, ...$dataArrays);
                $result[$key] = json_decode(json_encode($interpolatedArray));
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Interpolate condition with debug support
     *
     * Preserves the original condition as 'condition_original' before interpolating.
     * Handles JSON decoding for proper storage of the original condition.
     *
     * @param array &$section The section array (passed by reference to modify condition_original)
     * @param array $dataArrays One or more arrays containing variable => value mappings
     * @return string The interpolated condition
     */
    public function interpolateConditionWithDebug(array &$section, array ...$dataArrays): string
    {
        // Get the original condition
        $originalCondition = $section['condition'] ?? '';

        // Preserve the original condition (properly decoded)
        if (!empty($originalCondition)) {
            // Handle JSON decoding similar to ConditionService to avoid escaped strings
            $conditionOriginal = $originalCondition;
            if (is_string($conditionOriginal)) {
                // Handle double-encoded JSON strings - decode to get proper JSON string
                $decoded = json_decode($conditionOriginal, true);
                if (is_string($decoded)) {
                    // If we got a string back, that's the unescaped JSON string we want
                    $conditionOriginal = $decoded;
                } elseif (is_array($decoded) || is_object($decoded)) {
                    // If we got an object/array, encode it back to string
                    $conditionOriginal = json_encode($decoded);
                }
                // If decode failed or returned null, keep original
            } else {
                $conditionOriginal = json_encode($conditionOriginal);
            }
            $section['condition_original'] = $conditionOriginal;
        }

        // Perform normal interpolation
        return $this->interpolate($originalCondition, ...$dataArrays);
    }

    /**
     * Flatten a nested array into dot-notation keys for easier variable access
     *
     * @param array $array The array to flatten
     * @param string $prefix Prefix for nested keys
     * @return array Flattened array with dot-notation keys
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $fullKey));
            } else {
                $result[$fullKey] = $value;
            }

            // Also keep the original key for direct access
            if (!isset($result[$key])) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
