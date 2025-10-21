<?php

namespace App\Util;

/**
 * JsonNormalizer
 * 
 * Utility for normalizing JSON structures for consistent diff comparison.
 * Provides stable key ordering and consistent formatting to reduce noise in diffs.
 * 
 * @package App\Util
 */
class JsonNormalizer
{
    /**
     * Normalize a JSON structure for consistent comparison
     * 
     * Features:
     * - Stable key ordering (alphabetical)
     * - Consistent formatting (pretty-print with 2-space indent)
     * - Null value handling
     * - Empty array/object normalization
     * 
     * @param mixed $data The data to normalize (array, object, or scalar)
     * @param int $options JSON encode options
     * @return string Normalized JSON string
     */
    public static function normalize($data, int $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        // Convert to array if it's an object
        if (is_object($data)) {
            $data = json_decode(json_encode($data), true);
        }

        // Sort the data structure recursively
        $sorted = self::sortRecursive($data);

        // Encode with consistent options
        return json_encode($sorted, $options);
    }

    /**
     * Normalize and parse JSON structure
     * 
     * @param mixed $data The data to normalize
     * @return mixed Normalized data structure
     */
    public static function normalizeStructure($data)
    {
        // Convert to array if it's an object
        if (is_object($data)) {
            $data = json_decode(json_encode($data), true);
        }

        // Sort the data structure recursively
        return self::sortRecursive($data);
    }

    /**
     * Recursively sort arrays and objects by key
     * 
     * @param mixed $data The data to sort
     * @return mixed Sorted data
     */
    private static function sortRecursive($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        // Check if this is an associative array (object)
        $isAssoc = self::isAssociativeArray($data);

        if ($isAssoc) {
            // Sort associative arrays by key
            ksort($data);
        }

        // Recursively sort nested structures
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortRecursive($value);
            }
        }

        return $data;
    }

    /**
     * Check if an array is associative
     * 
     * @param array $arr The array to check
     * @return bool True if associative, false if indexed
     */
    private static function isAssociativeArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Create a semantic diff-friendly representation
     * Groups related content for better diff visualization
     * 
     * @param array $pageData The page data structure
     * @return array Grouped structure
     */
    public static function createDiffFriendlyStructure(array $pageData): array
    {
        $grouped = [
            'page_metadata' => [],
            'page_fields' => [],
            'sections' => [],
            'translations' => [],
            'styles' => [],
            'conditions' => [],
            'data_configs' => []
        ];

        // Group page metadata
        $metadataKeys = ['id', 'keyword', 'url', 'protocol', 'parent', 'id_type', 'is_headless', 'is_open_access', 'is_system', 'nav_position', 'footer_position'];
        foreach ($metadataKeys as $key) {
            if (isset($pageData[$key])) {
                $grouped['page_metadata'][$key] = $pageData[$key];
            }
        }

        // Group page fields
        if (isset($pageData['fields'])) {
            $grouped['page_fields'] = $pageData['fields'];
        }

        // Group sections
        if (isset($pageData['sections'])) {
            $grouped['sections'] = $pageData['sections'];
        }

        // Extract translations from sections
        if (isset($pageData['sections']) && is_array($pageData['sections'])) {
            $grouped['translations'] = self::extractTranslations($pageData['sections']);
        }

        // Extract styles from sections
        if (isset($pageData['sections']) && is_array($pageData['sections'])) {
            $grouped['styles'] = self::extractStyles($pageData['sections']);
        }

        // Extract conditions from sections
        if (isset($pageData['sections']) && is_array($pageData['sections'])) {
            $grouped['conditions'] = self::extractConditions($pageData['sections']);
        }

        // Extract data configurations from sections
        if (isset($pageData['sections']) && is_array($pageData['sections'])) {
            $grouped['data_configs'] = self::extractDataConfigs($pageData['sections']);
        }

        return $grouped;
    }

    /**
     * Extract translations from section structure
     * 
     * @param array $sections Section array
     * @return array Extracted translations
     */
    private static function extractTranslations(array $sections): array
    {
        $translations = [];

        foreach ($sections as $section) {
            if (isset($section['fields']) && is_array($section['fields'])) {
                foreach ($section['fields'] as $field) {
                    if (isset($field['value'])) {
                        $translations[] = [
                            'section_id' => $section['id'] ?? null,
                            'field_id' => $field['id'] ?? null,
                            'value' => $field['value']
                        ];
                    }
                }
            }

            // Recursively process child sections
            if (isset($section['children']) && is_array($section['children'])) {
                $childTranslations = self::extractTranslations($section['children']);
                $translations = array_merge($translations, $childTranslations);
            }
        }

        return $translations;
    }

    /**
     * Extract styles from section structure
     * 
     * @param array $sections Section array
     * @return array Extracted styles
     */
    private static function extractStyles(array $sections): array
    {
        $styles = [];

        foreach ($sections as $section) {
            if (isset($section['styles'])) {
                $styles[] = [
                    'section_id' => $section['id'] ?? null,
                    'styles' => $section['styles']
                ];
            }

            // Recursively process child sections
            if (isset($section['children']) && is_array($section['children'])) {
                $childStyles = self::extractStyles($section['children']);
                $styles = array_merge($styles, $childStyles);
            }
        }

        return $styles;
    }

    /**
     * Extract conditions from section structure
     * 
     * @param array $sections Section array
     * @return array Extracted conditions
     */
    private static function extractConditions(array $sections): array
    {
        $conditions = [];

        foreach ($sections as $section) {
            if (isset($section['condition'])) {
                $conditions[] = [
                    'section_id' => $section['id'] ?? null,
                    'condition' => $section['condition']
                ];
            }

            // Recursively process child sections
            if (isset($section['children']) && is_array($section['children'])) {
                $childConditions = self::extractConditions($section['children']);
                $conditions = array_merge($conditions, $childConditions);
            }
        }

        return $conditions;
    }

    /**
     * Extract data configurations from section structure
     * 
     * @param array $sections Section array
     * @return array Extracted data configs
     */
    private static function extractDataConfigs(array $sections): array
    {
        $configs = [];

        foreach ($sections as $section) {
            if (isset($section['data'])) {
                $configs[] = [
                    'section_id' => $section['id'] ?? null,
                    'data_config' => $section['data']
                ];
            }

            // Recursively process child sections
            if (isset($section['children']) && is_array($section['children'])) {
                $childConfigs = self::extractDataConfigs($section['children']);
                $configs = array_merge($configs, $childConfigs);
            }
        }

        return $configs;
    }

    /**
     * Compare two JSON structures and return a summary of differences
     * 
     * @param mixed $data1 First data structure
     * @param mixed $data2 Second data structure
     * @return array Summary of differences
     */
    public static function getDifferenceSummary($data1, $data2): array
    {
        $normalized1 = self::normalizeStructure($data1);
        $normalized2 = self::normalizeStructure($data2);

        return [
            'are_equal' => $normalized1 === $normalized2,
            'changes' => self::findChanges($normalized1, $normalized2)
        ];
    }

    /**
     * Recursively find changes between two structures
     * 
     * @param mixed $data1 First structure
     * @param mixed $data2 Second structure
     * @param string $path Current path in structure
     * @return array List of changes
     */
    private static function findChanges($data1, $data2, string $path = ''): array
    {
        $changes = [];

        // If types differ
        if (gettype($data1) !== gettype($data2)) {
            $changes[] = [
                'path' => $path,
                'type' => 'type_change',
                'old_type' => gettype($data1),
                'new_type' => gettype($data2)
            ];
            return $changes;
        }

        // If not arrays, compare values
        if (!is_array($data1) || !is_array($data2)) {
            if ($data1 !== $data2) {
                $changes[] = [
                    'path' => $path,
                    'type' => 'value_change',
                    'old_value' => $data1,
                    'new_value' => $data2
                ];
            }
            return $changes;
        }

        // Find added keys
        $keys1 = array_keys($data1);
        $keys2 = array_keys($data2);
        $addedKeys = array_diff($keys2, $keys1);
        $removedKeys = array_diff($keys1, $keys2);
        $commonKeys = array_intersect($keys1, $keys2);

        foreach ($addedKeys as $key) {
            $changes[] = [
                'path' => $path . '.' . $key,
                'type' => 'addition',
                'value' => $data2[$key]
            ];
        }

        foreach ($removedKeys as $key) {
            $changes[] = [
                'path' => $path . '.' . $key,
                'type' => 'removal',
                'value' => $data1[$key]
            ];
        }

        foreach ($commonKeys as $key) {
            $newPath = $path ? $path . '.' . $key : $key;
            $nestedChanges = self::findChanges($data1[$key], $data2[$key], $newPath);
            $changes = array_merge($changes, $nestedChanges);
        }

        return $changes;
    }
}

