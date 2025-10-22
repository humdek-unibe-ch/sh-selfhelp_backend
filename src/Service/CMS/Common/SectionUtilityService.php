<?php

namespace App\Service\CMS\Common;

use App\Repository\StylesFieldRepository;
use App\Service\CMS\DataService;
use App\Service\Cache\Core\CacheService;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Common\StyleNames;

/**
 * Utility service for section-related operations
 * Provides common functionality used by both admin and frontend services
 */
class SectionUtilityService
{
    public function __construct(
        private readonly DataService $dataService,
        private readonly StylesFieldRepository $stylesFieldRepository,
        private readonly CacheService $cache,
        private readonly UserContextService $userContextService
    ) {
    }

    /**
     * Build a nested hierarchical structure from flat sections array
     *
     * @param array $sections Flat array of sections with path and level information
     * @param bool $applyData Whether to apply data to sections
     * @param int $languageId Language ID for data retrieval
     * @return array Hierarchical structure of sections
     */
    public function buildNestedSections(array $sections, bool $applyData = false, int $languageId = 1): array
    {
        // Create a map of sections by ID for quick lookup
        $sectionsById = [];
        $rootSections = [];

        // First pass: index all sections by ID
        foreach ($sections as $section) {
            $section['children'] = [];
            if ($applyData) {
                $this->applySectionData($section, $languageId);
            }
            $sectionsById[$section['id']] = $section;
        }

        // Second pass: build the hierarchy
        foreach ($sections as $section) {
            $id = $section['id'];

            // If it's a root section (level 0), add to root array
            if ($section['level'] === 0) {
                $rootSections[] = &$sectionsById[$id];
            } else {
                // Find parent using the path
                $pathParts = explode(',', $section['path']);
                if (count($pathParts) >= 2) {
                    $parentId = (int) $pathParts[count($pathParts) - 2];

                    // If parent exists, add this as its child
                    if (isset($sectionsById[$parentId])) {
                        $sectionsById[$parentId]['children'][] = &$sectionsById[$id];
                    }
                }
            }
        }

        // Recursively sort children by position
        $sortChildren = function (&$nodes) use (&$sortChildren) {
            usort($nodes, function ($a, $b) {
                return ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
            });
            foreach ($nodes as &$node) {
                if (!empty($node['children'])) {
                    $sortChildren($node['children']);
                }
            }
        };
        $sortChildren($rootSections);
        return $rootSections;
    }

    /**
     * Recursively extract all section IDs from a hierarchical sections structure
     * 
     * @param array $sections Hierarchical sections structure
     * @return array Flat array of section IDs
     */
    public function extractSectionIds(array $sections): array
    {
        $ids = [];

        foreach ($sections as $section) {
            if (isset($section['id'])) {
                $ids[] = $section['id'];
            }

            // Process children recursively
            if (!empty($section['children'])) {
                $childIds = $this->extractSectionIds($section['children']);
                $ids = array_merge($ids, $childIds);
            }
        }

        return $ids;
    }

    /**
     * Apply translations to sections recursively
     * 
     * @param array &$sections The sections to apply translations to (passed by reference)
     * @param array $translations The translations keyed by section ID
     * @param array $defaultTranslations Default language translations for fallback
     * @param array $propertyTranslations Property translations (language ID 1) for fields of type 1
     * @throws \LogicException If stylesFieldRepository is not set but style default values are needed
     */
    public function applySectionTranslations(
        array &$sections,
        array $translations,
        array $defaultTranslations = [],
        array $propertyTranslations = []
    ): void {
        // First pass: collect all unique style IDs to batch fetch default values
        $styleIds = $this->collectUniqueStyleIds($sections);

        // Batch fetch default values for all styles in one query to avoid N+1
        $defaultValuesByStyle = [];
        if (!empty($styleIds) && $this->stylesFieldRepository !== null) {
            $defaultValuesByStyle = $this->stylesFieldRepository->findDefaultValuesByStyleIds($styleIds);
        } elseif (!empty($styleIds) && $this->stylesFieldRepository === null) {
            throw new \LogicException('StylesFieldRepository is required for applying default style values');
        }

        // Second pass: apply translations and default values
        $this->applySectionTranslationsRecursive(
            $sections,
            $translations,
            $defaultTranslations,
            $propertyTranslations,
            $defaultValuesByStyle
        );
    }

    /**
     * Collect all unique style IDs from sections recursively
     * 
     * @param array $sections The sections to collect style IDs from
     * @return array Array of unique style IDs
     */
    private function collectUniqueStyleIds(array $sections): array
    {
        $styleIds = [];

        foreach ($sections as $section) {
            $styleId = $section['id_styles'] ?? null;
            if ($styleId !== null) {
                $styleIds[$styleId] = true; // Use array key to ensure uniqueness
            }

            // Process children recursively
            if (isset($section['children']) && is_array($section['children'])) {
                $childStyleIds = $this->collectUniqueStyleIds($section['children']);
                foreach ($childStyleIds as $childStyleId) {
                    $styleIds[$childStyleId] = true;
                }
            }
        }

        return array_keys($styleIds);
    }

    /**
     * Apply translations to sections recursively with pre-fetched default values
     * 
     * @param array &$sections The sections to apply translations to (passed by reference)
     * @param array $translations The translations keyed by section ID
     * @param array $defaultTranslations Default language translations for fallback
     * @param array $propertyTranslations Property translations (language ID 1) for fields of type 1
     * @param array $defaultValuesByStyle Pre-fetched default values organized by style ID
     */
    private function applySectionTranslationsRecursive(
        array &$sections,
        array $translations,
        array $defaultTranslations = [],
        array $propertyTranslations = [],
        array $defaultValuesByStyle = []
    ): void {
        foreach ($sections as &$section) {
            $sectionId = $section['id'] ?? null;

            if ($sectionId) {
                // Get the section's style ID to fetch default values if needed
                $styleId = $section['id_styles'] ?? null;

                // First apply property translations (for fields of type 1)
                if (isset($propertyTranslations[$sectionId])) {
                    $section = array_merge($section, $propertyTranslations[$sectionId]);
                }

                // Then apply default language translations as fallback
                if (isset($defaultTranslations[$sectionId])) {
                    $section = array_merge($section, $defaultTranslations[$sectionId]);
                }

                // Finally apply requested language translations (overriding any fallbacks)
                if (isset($translations[$sectionId])) {
                    $section = array_merge($section, $translations[$sectionId]);
                }

                // For any fields that still don't have values, use pre-fetched default values
                if ($styleId && isset($defaultValuesByStyle[$styleId])) {
                    $stylesFields = $defaultValuesByStyle[$styleId];

                    // Apply default values for fields that don't have translations
                    foreach ($stylesFields as $fieldName => $defaultValue) {
                        // Only apply default value if the field doesn't already have a value
                        // Check for null or empty string, not empty() which considers '0' as empty
                        if (!isset($section[$fieldName]) ||
                            !is_array($section[$fieldName]) ||
                            $section[$fieldName]['content'] === null ||
                            $section[$fieldName]['content'] === '') {
                            $section[$fieldName] = [
                                'content' => $defaultValue,
                                'meta' => null
                            ];
                        }
                    }
                }
            }

            // Process children recursively
            if (isset($section['children']) && is_array($section['children'])) {
                $this->applySectionTranslationsRecursive(
                    $section['children'],
                    $translations,
                    $defaultTranslations,
                    $propertyTranslations,
                    $defaultValuesByStyle
                );
            }
        }
    }

    /**
     * Normalize a Section entity for API response
     * 
     * @param object $section Section entity or array with section data
     * @return array Normalized section data
     */
    public function normalizeSection($section): array
    {
        if (is_object($section) && method_exists($section, 'getId')) {
            // It's an entity, convert to array
            return [
                'id' => $section->getId(),
                'name' => $section->getName(),
                'id_styles' => $section->getStyle() ? $section->getStyle()->getId() : null,
                'style_name' => $section->getStyle() ? $section->getStyle()->getName() : null,
            ];
        } else if (is_array($section)) {
            // It's already an array, ensure it has the expected structure
            return array_merge([
                'id' => $section['id'] ?? null,
                'name' => $section['name'] ?? null,
                'id_styles' => $section['id_styles'] ?? null,
                'style_name' => $section['style_name'] ?? null,
            ], $section);
        }

        // Fallback for unexpected input
        return [];
    }

    /**
     * Retrieve data based on JSON configuration
     *
     * @param array $dataConfig JSON structure defining data source
     * @param array $params Parameters to replace in the config
     * @param int $languageId Language ID for data retrieval
     * @return array Retrieved data or empty array if failed
     */
    public function retrieveData(array $dataConfig, array $params = [], int $languageId = 1): array
    {
        $parsedConfig = $this->parseParams($dataConfig, $params);
        if (!$parsedConfig) {
            return [];
        }

        return $this->fetchData($parsedConfig, $languageId);
    }

    /**
     * Parse parameters in data config and replace placeholders
     *
     * @param array $dataConfig The JSON config structure
     * @param array $params Parameters to replace (#param_name with actual values)
     * @return array Parsed config with parameters replaced
     */
    private function parseParams(array $dataConfig, array $params = []): array
    {
        $strData = json_encode($dataConfig);
        if (!$strData) {
            return $dataConfig;
        }

        // Replace #param_name with actual parameter values
        preg_match_all('~#\w+\b~', $strData, $matches);
        foreach ($matches as $matchGroup) {
            foreach ($matchGroup as $paramPlaceholder) {
                $paramName = str_replace('#', '', $paramPlaceholder);
                if (isset($params[$paramName])) {
                    $strData = str_replace($paramPlaceholder, $params[$paramName], $strData);
                }
            }
        }

        $parsed = json_decode($strData, true);
        return $parsed !== null ? $parsed : $dataConfig;
    }

    /**
     * Fetch data based on parsed data configuration
     *
     * @param array $dataConfig Parsed data configuration
     * @param int $languageId Language ID for data retrieval
     * @return array Retrieved data
     */
    private function fetchData(array $dataConfig, int $languageId): array
    {
        if (!isset($dataConfig['table'])) {
            return [];
        }

        $tableName = $dataConfig['table'];
        $retrieve = $dataConfig['retrieve'] ?? 'all';
        $filter = $dataConfig['filter'] ?? '';
        $currentUser = $dataConfig['current_user'] ?? true;
        $allFields = $dataConfig['all_fields'] ?? true;

        // Get data table
        $dataTable = $this->dataService->getDataTableByName($tableName);
        if (!$dataTable) {
            return [];
        }

        $dataTableId = $dataTable->getId();

        // Determine user filtering
        $userId = null;
        $ownEntriesOnly = $currentUser;
        if ($currentUser) {
            $currentUserObj = $this->userContextService->getCurrentUser();
            $userId = $currentUserObj ? $currentUserObj->getId() : -1;
        }

        // Build filter based on retrieve type
        $additionalFilter = '';
        switch ($retrieve) {
            case 'first':
                $additionalFilter = 'ORDER BY record_id ASC LIMIT 1';
                break;
            case 'last':
                $additionalFilter = 'ORDER BY record_id DESC LIMIT 1';
                break;
            case 'all':
                // No additional filter
                break;
            case 'all_as_array':
                // No additional filter, will be handled in post-processing
                break;
            case 'JSON':
                // No additional filter, will be handled in post-processing
                break;
        }

        // Combine filters
        $combinedFilter = trim($filter . ' ' . $additionalFilter);

        // Get data
        $data = $this->dataService->getData(
            $dataTableId,
            $combinedFilter,
            $ownEntriesOnly,
            $userId,
            false, // dbFirst - we'll handle this ourselves
            true,  // excludeDeleted
            $languageId
        );

        // Post-process based on retrieve type
        switch ($retrieve) {
            case 'first':
            case 'last':
                // For first/last, we have a single record (due to LIMIT 1), apply same processing as processAll for single records
                if (isset($data[0])) {
                    $record = $data[0];
                    $allFields = $dataConfig['all_fields'] ?? true;

                    if (!$allFields) {
                        // Filter to only specified fields and apply field configurations
                        $fields = $dataConfig['fields'] ?? [];
                        if (!empty($fields)) {
                            $processedRecord = [];
                            foreach ($fields as $fieldConfig) {
                                $fieldName = $fieldConfig['field_name'];
                                $fieldHolder = $fieldConfig['field_holder'] ?? $fieldName;
                                $notFoundText = $fieldConfig['not_found_text'] ?? '';

                                // Check if field has no value (empty, null, or not set)
                                $value = $record[$fieldName] ?? '';
                                if (empty($value)) {
                                    $value = $notFoundText;
                                }

                                $processedRecord[$fieldHolder] = $value;
                            }
                            return $processedRecord;
                        }
                    }

                    return $record;
                }
                return [];
            case 'all_as_array':
                return $this->processAllAsArray($data, $dataConfig);
            case 'JSON':
                return $this->processJSON($data, $dataConfig);
            case 'all':
            default:
                return $this->processAll($data, $dataConfig);
        }
    }

    /**
     * Process data for 'all' retrieve type
     *
     * @param array $data Raw data from database
     * @param array $dataConfig Data configuration
     * @return array Processed data
     */
    private function processAll(array $data, array $dataConfig): array
    {
        // If no data, return empty array
        if (empty($data)) {
            return [];
        }

        // If only one record, return it as-is
        if (count($data) === 1) {
            $record = $data[0];
            $allFields = $dataConfig['all_fields'] ?? true;

            if (!$allFields) {
                // Filter to only specified fields and apply field configurations
                $fields = $dataConfig['fields'] ?? [];
                if (!empty($fields)) {
                    $processedRecord = [];
                    foreach ($fields as $fieldConfig) {
                        $fieldName = $fieldConfig['field_name'];
                        $fieldHolder = $fieldConfig['field_holder'] ?? $fieldName;
                        $notFoundText = $fieldConfig['not_found_text'] ?? '';

                        // Check if field has no value (empty, null, or not set)
                        $value = $record[$fieldName] ?? '';
                        if (empty($value)) {
                            $value = $notFoundText;
                        }

                        $processedRecord[$fieldHolder] = $value;
                    }
                    return $processedRecord;
                }
            }

            return $record;
        }

        // Multiple records: return each field as comma-separated values
        $result = [];
        $allFields = $dataConfig['all_fields'] ?? true;

        if ($allFields) {
            // Use all fields from the first record as template
            $fieldNames = array_keys($data[0]);
            // For each field, collect values from all records and join with commas
            foreach ($fieldNames as $fieldName) {
                $values = [];
                foreach ($data as $record) {
                    $values[] = $record[$fieldName] ?? '';
                }
                $result[$fieldName] = implode(',', $values);
            }
        } else {
            // Filter to only specified fields and apply field configurations
            $fields = $dataConfig['fields'] ?? [];
            if (empty($fields)) {
                $fieldNames = array_keys($data[0]);
                // For each field, collect values from all records and join with commas
                foreach ($fieldNames as $fieldName) {
                    $values = [];
                    foreach ($data as $record) {
                        $values[] = $record[$fieldName] ?? '';
                    }
                    $result[$fieldName] = implode(',', $values);
                }
            } else {
                // Apply field configurations for specified fields
                foreach ($fields as $fieldConfig) {
                    $fieldName = $fieldConfig['field_name'];
                    $fieldHolder = $fieldConfig['field_holder'] ?? $fieldName;
                    $notFoundText = $fieldConfig['not_found_text'] ?? '';

                    $values = [];
                    foreach ($data as $record) {
                        // Check if field has no value (empty, null, or not set)
                        $value = $record[$fieldName] ?? '';
                        if (empty($value)) {
                            $value = $notFoundText;
                        }
                        $values[] = $value;
                    }
                    $result[$fieldHolder] = implode(',', $values);
                }
            }
        }

        return $result;
    }

    /**
     * Process data for 'all_as_array' retrieve type
     *
     * @param array $data Raw data from database
     * @param array $dataConfig Data configuration
     * @return array Processed data as array
     */
    private function processAllAsArray(array $data, array $dataConfig): array
    {
        // If no data, return empty array
        if (empty($data)) {
            return [];
        }

        $result = [];
        $allFields = $dataConfig['all_fields'] ?? true;

        if ($allFields) {
            // Use all fields from the first record as template
            $fieldNames = array_keys($data[0]);
            // For each field, collect values from all records into arrays
            foreach ($fieldNames as $fieldName) {
                $values = [];
                foreach ($data as $record) {
                    $values[] = $record[$fieldName] ?? null;
                }
                $result[$fieldName] = $values;
            }
        } else {
            // Filter to only specified fields and apply field configurations
            $fields = $dataConfig['fields'] ?? [];
            if (empty($fields)) {
                $fieldNames = array_keys($data[0]);
                // For each field, collect values from all records into arrays
                foreach ($fieldNames as $fieldName) {
                    $values = [];
                    foreach ($data as $record) {
                        $values[] = $record[$fieldName] ?? null;
                    }
                    $result[$fieldName] = $values;
                }
            } else {
                // Apply field configurations for specified fields
                foreach ($fields as $fieldConfig) {
                    $fieldName = $fieldConfig['field_name'];
                    $fieldHolder = $fieldConfig['field_holder'] ?? $fieldName;
                    $notFoundText = $fieldConfig['not_found_text'] ?? '';

                    $values = [];
                    foreach ($data as $record) {
                        // Check if field has no value (empty, null, or not set)
                        $value = $record[$fieldName] ?? '';
                        if (empty($value)) {
                            $value = $notFoundText;
                        }
                        $values[] = $value;
                    }
                    $result[$fieldHolder] = $values;
                }
            }
        }

        return $result;
    }

    /**
     * Process data for 'JSON' retrieve type
     *
     * @param array $data Raw data from database
     * @param array $dataConfig Data configuration
     * @return array Processed data as JSON structure
     */
    private function processJSON(array $data, array $dataConfig): array
    {
        $result = [];

        foreach ($data as $record) {
            $processedRecord = [];

            // Apply field mappings if specified
            $mapFields = $dataConfig['map_fields'] ?? [];
            if (!empty($mapFields)) {
                foreach ($mapFields as $mapping) {
                    $fieldName = $mapping['field_name'];
                    $newName = $mapping['field_new_name'];

                    if (isset($record[$fieldName])) {
                        $processedRecord[$newName] = $record[$fieldName];
                    }
                }
            }

            // Apply field configurations
            $fields = $dataConfig['fields'] ?? [];
            if (!empty($fields)) {
                // If fields are specified, use only those fields
                foreach ($fields as $fieldConfig) {
                    $fieldName = $fieldConfig['field_name'];
                    $fieldHolder = $fieldConfig['field_holder'] ?? $fieldName;
                    $notFoundText = $fieldConfig['not_found_text'] ?? '';

                    // Check if field has no value (empty, null, or not set)
                    $value = $record[$fieldName] ?? '';
                    if (empty($value)) {
                        $value = $notFoundText;
                    }
                    $processedRecord[$fieldHolder] = $value;
                }
            } else {
                // If no fields are specified, return all fields from the record
                $processedRecord = $record;
            }

            $result[] = $processedRecord;
        }

        return $result;
    }



    /**
     * Apply data to a section
     *
     * @param array &$section The section to apply data to (passed by reference)
     * @param int $languageId Language ID for data retrieval
     */
    public function applySectionData(array &$section, int $languageId = 1): void
    {
        $section['section_data'] = [];

        // Handle form record data
        if ($section['style_name'] == StyleNames::STYLE_FORM_RECORD) {
            $section['section_data'] = $this->dataService->getFormRecordDataWithAllLanguages($section['id']);
        }

        // Handle data_config field - parse and retrieve data without replacing content
        if (isset($section['data_config']) && $section['data_config'] !== null) {
            // Parse data_config as JSON string to array
            $dataConfigArray = is_string($section['data_config'])
                ? json_decode($section['data_config'], true)
                : $section['data_config'];

            if (is_array($dataConfigArray)) {
                // data_config is an array of configuration objects, process each one
                $retrievedData = [];
                foreach ($dataConfigArray as $configIndex => $config) {
                    $configData = $this->retrieveData($config, [], $languageId);
                    // Use the scope as key if available, otherwise use index
                    $key = isset($config['scope']) ? $config['scope'] : $configIndex;
                    $retrievedData[$key] = $configData;
                }
                // Add retrieved data as a new field
                $section['retrieved_data'] = $retrievedData;
                $section['data_config'] = $dataConfigArray;
            }
        }
    }
}
