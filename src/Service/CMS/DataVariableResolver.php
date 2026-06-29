<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS;

use App\Entity\Section;
use App\Entity\SectionsHierarchy;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\CMS\GlobalVariableService;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for resolving data variables from sections
 * Generates a list of available variables for use in dropdowns and templates
 */
class DataVariableResolver extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataService $dataService,
        private readonly CacheService $cache,
        private readonly GlobalVariableService $globalVariableService
    ) {
    }

    /**
     * Get all available data variables for a section as a `token => label` map.
     *
     * The TOKEN (map key) is the interpolation key inserted into content
     * (`{{scope.token}}`). For data columns it is the immutable, opaque
     * `field_key` (issue #56 v2): core CMS forms use `section_<input id>`,
     * SurveyJS uses `question.name`. The token is rename-safe — renaming an
     * input or curating a display name only moves the LABEL, never the token, so
     * authored content never breaks. The LABEL (map value) is the human-facing
     * text shown in the editor/picker — the curated `display_name` when present,
     * otherwise the field_key. The frontend renders the label as a chip while
     * persisting the `{{scope.field_key}}` token.
     *
     * The result is cached by the caller as part of the SECTION-scoped section
     * payload ({@see \App\Service\CMS\Admin\AdminSectionService::getSection}):
     * editing a section's data_config goes through updateSection(), which
     * invalidates that SECTION scope (and this resolver's own SECTION-scoped
     * hierarchy/section-data caches), so the picker refreshes on the next load
     * after a data_config change instead of requerying on every read.
     *
     * @param array<string, mixed> $section The section data array
     * @return array<string, string> token => human label
     */
    public function getDataVariables(array $section): array
    {
        $sectionIdRaw = $section['id'] ?? null;
        if (!$sectionIdRaw) {
            return [];
        }
        $sectionId = $this->asInt($sectionIdRaw);

        $allSections = $this->getSectionHierarchy($sectionId);

        $variables = [];

        // Process variables from all sections in hierarchy (parent to child order)
        foreach ($allSections as $sectionData) {
            // Get custom variables from data_config if available
            $customVariables = $this->parseDataConfig($sectionData);
            if (!empty($customVariables)) {
                $variables = array_merge($variables, $customVariables);
            } else {
                // Get table variables if no custom variables defined
                $tableVariables = $this->getTableVariables($sectionData);
                $variables = array_merge($variables, $tableVariables);
            }
        }

        // Add system variables
        $variables = array_merge($variables, $this->getSystemVariables());

        // Map keys (tokens) are inherently unique; the merge keeps the last
        // label seen for a token. No array_unique needed for a token=>label map.
        return $variables;
    }

    /**
     * Get all sections in the hierarchy from root to current section
     *
     * @param int $sectionId The section ID to get hierarchy for
     * @return list<array<string, mixed>> Array of section data arrays from root to current section
     */
    private function getSectionHierarchy(int $sectionId): array
    {
        $cacheKey = "section_hierarchy_{$sectionId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_SECTION, $sectionId)
            ->getList($cacheKey, function () use ($sectionId) {
                $hierarchy = [];
                $currentSectionId = $sectionId;

                // Traverse up the hierarchy to get all parent sections
                while ($currentSectionId !== null) {
                    $section = $this->getSectionData($currentSectionId);
                    if (!$section) {
                        break;
                    }

                    // Add to beginning of array (root first)
                    array_unshift($hierarchy, $section);

                    // Find parent section ID
                    $currentSectionId = $this->getParentSectionId($currentSectionId);
                }

                return $hierarchy;
            });
    }

    /**
     * Get section data by ID
     *
     * @param int $sectionId The section ID
     * @return array<string, mixed>|null Section data or null if not found
     */
    private function getSectionData(int $sectionId): ?array
    {
        try {
            $cacheKey = "section_data_{$sectionId}";

            return $this->cache
                ->withCategory(CacheService::CATEGORY_SECTIONS)
                ->withEntityScope(CacheService::ENTITY_SCOPE_SECTION, $sectionId)
                ->getItem($cacheKey, function () use ($sectionId) {
                    $section = $this->entityManager->getRepository(Section::class)->find($sectionId);
                    if (!$section) {
                        return null;
                    }

                    // Return normalized section data similar to AdminSectionService
                    return [
                        'id' => $section->getId(),
                        'name' => $section->getName(),
                        'data_config' => $section->getDataConfig(),
                        'global_fields' => [
                            'condition' => $section->getCondition(),
                            'data_config' => $section->getDataConfig(),
                            'css' => $section->getCss(),
                            'css_mobile' => $section->getCssMobile(),
                            'debug' => $section->isDebug(),
                        ]
                    ];
                });
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get parent section ID for a given section
     *
     * @param int $sectionId The child section ID
     * @return int|null Parent section ID or null if no parent
     */
    private function getParentSectionId(int $sectionId): ?int
    {
        try {
            $hierarchy = $this->entityManager->getRepository(SectionsHierarchy::class)
                ->findOneBy(['childSection' => $sectionId]);

            return $hierarchy?->getParentSection()?->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse data_config to extract custom variables as a `token => label` map.
     *
     * @param array<string, mixed> $section The section data array
     * @return array<string, string> token => label
     */
    private function parseDataConfig(array $section): array
    {
        $variables = [];

        // Check if section has global_fields with data_config
        $globalFields = $section['global_fields'] ?? null;
        if (!is_array($globalFields) || !isset($globalFields['data_config'])) {
            return $variables;
        }

        // Parse JSON data_config
        $dataConfig = json_decode($this->asString($globalFields['data_config']), true);
        if (!is_array($dataConfig)) {
            return $variables;
        }

        // Process each config entry
        foreach ($dataConfig as $config) {
            if (!is_array($config) || !isset($config['scope'])) {
                continue;
            }

            $scope = $this->asString($config['scope']);

            // Custom fields are explicit columns chosen in the data-config UI;
            // `field_name` holds the immutable `field_key`. The token is the
            // rename-safe `scope.field_key`; the picker label is the column's
            // curated `display_name` (resolved from the table) when present, else
            // the field_key itself (issue #56 v2).
            if (isset($config['fields']) && is_array($config['fields'])) {
                $displayMap = [];
                if (isset($config['table'])) {
                    $dataTable = $this->dataService->getDataTableByName($this->asString($config['table']));
                    if ($dataTable) {
                        $displayMap = $this->getTableColumnNames((int) $dataTable->getId());
                    }
                }
                foreach ($config['fields'] as $field) {
                    if (is_array($field) && isset($field['field_name'])) {
                        $fieldKey = $this->asString($field['field_name']);
                        $label = $displayMap[$fieldKey] ?? $fieldKey;
                        $variables[$scope . '.' . $fieldKey] = $scope . '.' . $label;
                    }
                }
            }
            if (isset($config['table'])) {
                // If no custom fields but table is specified, get table variables
                $variables = array_merge($variables, $this->getTableVariablesFromConfig($config));
            }
        }

        return $variables;
    }

    /**
     * Get table variables from a specific data config entry as `token => label`.
     *
     * @param array<array-key, mixed> $config Single data config entry
     * @return array<string, string> token => label (scope-qualified)
     */
    private function getTableVariablesFromConfig(array $config): array
    {
        $variables = [];

        if (!isset($config['scope']) || !isset($config['table'])) {
            return $variables;
        }

        $scope = $this->asString($config['scope']);
        $tableName = $this->asString($config['table']);

        try {
            // Get data table by name
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if ($dataTable) {
                $tableId = (int) $dataTable->getId();
                $columns = $this->getTableColumnNames($tableId);

                foreach ($columns as $fieldKey => $columnLabel) {
                    // Token is the immutable, rename-safe field_key (never the
                    // mutable input name); the label is the curated display_name
                    // when present, else the field_key itself (issue #56 v2).
                    $variables[$scope . '.' . $fieldKey] = $scope . '.' . $columnLabel;
                }
            }
        } catch (\Exception $e) {
            // If there's an error getting table columns, continue without them
        }

        return $variables;
    }

    /**
     * Get table variables for a section (fallback when no custom variables).
     *
     * @param array<string, mixed> $section The section data array
     * @return array<string, string> token => label
     */
    private function getTableVariables(array $section): array
    {
        $variables = [];

        // Check if section has global_fields with data_config
        $globalFields = $section['global_fields'] ?? null;
        if (!is_array($globalFields) || !isset($globalFields['data_config'])) {
            return $variables;
        }

        // Parse JSON data_config
        $dataConfig = json_decode($this->asString($globalFields['data_config']), true);
        if (!is_array($dataConfig)) {
            return $variables;
        }

        // Process each config entry
        foreach ($dataConfig as $config) {
            if (!is_array($config)) {
                continue;
            }
            $variables = array_merge($variables, $this->getTableVariablesFromConfig($config));
        }

        return $variables;
    }

    /**
     * Get columns for a data table as `field_key => display label`.
     *
     * The map key is the immutable, opaque field key (used to build stable
     * tokens); the value is the curated `display_name` when present, else the
     * field key itself. Standard projection columns map to themselves.
     *
     * @param int $tableId Data table ID
     * @return array<string, string> field_key => display label
     */
    private function getTableColumnNames(int $tableId): array
    {
        $cacheKey = "table_columns_{$tableId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId)
            ->getList($cacheKey, function () use ($tableId) {
                try {
                    $conn = $this->entityManager->getConnection();
                    $sql = 'SELECT `field_key`, `display_name` FROM data_cols WHERE id_data_tables = :tableId ORDER BY `field_key`';
                    $stmt = $conn->prepare($sql);
                    $stmt->bindValue('tableId', $tableId, \Doctrine\DBAL\ParameterType::INTEGER);
                    $result = $stmt->executeQuery();

                    $columns = [];
                    foreach ($result->fetchAllAssociative() as $row) {
                        $fieldKey = $this->asString($row['field_key'] ?? '');
                        if ($fieldKey === '') {
                            continue;
                        }
                        $displayName = isset($row['display_name']) ? $this->asStringOrNull($row['display_name']) : null;
                        $columns[$fieldKey] = ($displayName !== null && $displayName !== '') ? $displayName : $fieldKey;
                    }

                    // Add the standard columns that always exist as variables.
                    $standardColumns = ['id_users', 'record_id', 'user_name', 'id_action_trigger_types', 'triggerType', 'entry_date', 'user_code'];
                    foreach ($standardColumns as $column) {
                        if (!array_key_exists($column, $columns)) {
                            $columns[$column] = $column;
                        }
                    }

                    return $columns;
                } catch (\Exception $e) {
                    return [];
                }
            });
    }

    /**
     * Get global variables from sh_global_values page for all languages.
     *
     * @return array<string, string> token => label (token doubles as label)
     */
    public function getGlobalVariables(): array
    {
        // Use the centralized global variable service
        return $this->globalVariableService->getGlobalVariableNames();
    }

    /**
     * Get hardcoded system variables as a `token => label` map (token == label).
     *
     * @return array<string, string> token => label
     */
    private function getSystemVariables(): array
    {
        $systemVars = [
            'user_name',
            'user_email',
            'user_code',
            'user_id',
            'page_keyword',
            'platform',
            'language',
            'user_group',
            'last_login',
            'current_date',
            'current_datetime',
            'current_time',
            'project_name',
            // Operator's maintenance note, resolved by VariableResolverService from
            // MaintenanceModeService state. Listed here so the CMS `{{ }}` editor
            // suggests `system.maintenance_message` (used by the seeded maintenance page).
            'maintenance_message'
        ];

        // Add system. prefix; token doubles as label (no curated display name).
        $variables = [];
        foreach ($systemVars as $var) {
            $token = 'system.' . $var;
            $variables[$token] = $token;
        }

        return $variables;
    }
}
