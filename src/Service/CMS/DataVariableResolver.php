<?php

namespace App\Service\CMS;

use App\Entity\Field;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\SectionsHierarchy;
use App\Repository\DataTableRepository;
use App\Repository\LanguageRepository;
use App\Repository\PageRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\CMS\GlobalVariableService;
use App\Service\Core\BaseService;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for resolving data variables from sections
 * Generates a list of available variables for use in dropdowns and templates
 */
class DataVariableResolver extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataTableRepository $dataTableRepository,
        private readonly LanguageRepository $languageRepository,
        private readonly PageRepository $pageRepository,
        private readonly DataService $dataService,
        private readonly CacheService $cache,
        private readonly GlobalVariableService $globalVariableService
    ) {
    }

    /**
     * Get all available data variables for a section
     *
     * @param array $section The section data array
     * @return array List of variable names
     */
    public function getDataVariables(array $section): array
    {
        $sectionId = $section['id'] ?? null;
        if (!$sectionId) {
            return [];
        }

        $cacheKey = "section_data_variables_{$sectionId}";

        // Get all sections in hierarchy first to extract dependencies
        $allSections = $this->getSectionHierarchy($sectionId);
        $dataTableIds = $this->extractDataTableDependencies($allSections);

        // Build cache service with all dependencies
        $cacheService = $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_SECTION, $sectionId);

        // Add data table dependencies to cache scope
        foreach ($dataTableIds as $dataTableId) {
            $cacheService = $cacheService->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId);
        }

        // Note: Global variable dependencies are handled by GlobalVariableService

        return $cacheService->getList($cacheKey, function () use ($allSections) {
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
            $systemVariables = $this->getSystemVariables();
            $variables = array_merge($variables, $systemVariables);

            // Remove duplicates while preserving order
            return array_unique($variables);
        });
    }

    /**
     * Extract all data table IDs that sections in the hierarchy depend on
     *
     * @param array $sections Array of section data arrays
     * @return array Array of unique data table IDs
     */
    private function extractDataTableDependencies(array $sections): array
    {
        $dataTableIds = [];

        foreach ($sections as $section) {
            if (!isset($section['global_fields']['data_config'])) {
                continue;
            }

            $dataConfigJson = $section['global_fields']['data_config'];
            $dataConfig = json_decode($dataConfigJson, true);

            if (!is_array($dataConfig)) {
                continue;
            }

            // Process each config entry
            foreach ($dataConfig as $config) {
                if (isset($config['table'])) {
                    try {
                        $dataTable = $this->dataService->getDataTableByName($config['table']);
                        if ($dataTable) {
                            $dataTableIds[] = $dataTable->getId();
                        }
                    } catch (\Exception $e) {
                        // Continue if data table not found
                    }
                }
            }
        }

        return array_unique($dataTableIds);
    }

    /**
     * Get all sections in the hierarchy from root to current section
     *
     * @param int $sectionId The section ID to get hierarchy for
     * @return array Array of section data arrays from root to current section
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
     * @return array|null Section data or null if not found
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

            return $hierarchy ? $hierarchy->getParentSection()->getId() : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse data_config to extract custom variables
     *
     * @param array $section The section data array
     * @return array List of custom variable names
     */
    private function parseDataConfig(array $section): array
    {
        $variables = [];

        // Check if section has global_fields with data_config
        if (!isset($section['global_fields']['data_config'])) {
            return $variables;
        }

        $dataConfigJson = $section['global_fields']['data_config'];

        // Parse JSON data_config
        $dataConfig = json_decode($dataConfigJson, true);
        if (!is_array($dataConfig)) {
            return $variables;
        }

        // Process each config entry
        foreach ($dataConfig as $config) {
            if (isset($config['scope'])) {
                $scope = $config['scope'];

                // Check if custom fields are defined
                if (isset($config['fields']) && is_array($config['fields'])) {
                    foreach ($config['fields'] as $field) {
                        if (isset($field['field_name'])) {
                            $variables[] = $scope . '.' . $field['field_name'];
                        }
                    }
                }
                if (isset($config['table'])) {
                    // If no custom fields but table is specified, get table variables
                    $tableVariables = $this->getTableVariablesFromConfig($config);
                    $variables = array_merge($variables, $tableVariables);
                }
            }
        }

        return $variables;
    }

    /**
     * Get table variables from a specific data config entry
     *
     * @param array $config Single data config entry
     * @return array List of variable names with scope prefix
     */
    private function getTableVariablesFromConfig(array $config): array
    {
        $variables = [];

        if (!isset($config['scope']) || !isset($config['table'])) {
            return $variables;
        }

        $scope = $config['scope'];
        $tableName = $config['table'];

        try {
            // Get data table by name
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if ($dataTable) {
                $tableId = $dataTable->getId();
                $columnNames = $this->getTableColumnNames($tableId);

                foreach ($columnNames as $columnName) {
                    $variables[] = $scope . '.' . $columnName;
                }
            }
        } catch (\Exception $e) {
            // If there's an error getting table columns, continue without them
        }

        return $variables;
    }

    /**
     * Get table variables for a section (fallback when no custom variables)
     *
     * @param array $section The section data array
     * @return array List of variable names with scope prefix
     */
    private function getTableVariables(array $section): array
    {
        $variables = [];

        // Check if section has global_fields with data_config
        if (!isset($section['global_fields']['data_config'])) {
            return $variables;
        }

        $dataConfigJson = $section['global_fields']['data_config'];

        // Parse JSON data_config
        $dataConfig = json_decode($dataConfigJson, true);
        if (!is_array($dataConfig)) {
            return $variables;
        }

        // Process each config entry
        foreach ($dataConfig as $config) {
            $tableVariables = $this->getTableVariablesFromConfig($config);
            $variables = array_merge($variables, $tableVariables);
        }

        return $variables;
    }

    /**
     * Get column names for a data table
     *
     * @param int $tableId Data table ID
     * @return array List of column names
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
                    $sql = 'SELECT DISTINCT `name` FROM dataCols WHERE id_dataTables = :tableId ORDER BY `name`';
                    $stmt = $conn->prepare($sql);
                    $stmt->bindValue('tableId', $tableId, \PDO::PARAM_INT);
                    $result = $stmt->executeQuery();

                    $columnNames = [];
                    foreach ($result->fetchAllAssociative() as $row) {
                        $columnNames[] = $row['name'];
                    }

                    // Add the standard columns that always exist as variables
                    $standardColumns = ['id_users', 'record_id', 'user_name', 'id_actionTriggerTypes', 'triggerType', 'entry_date', 'user_code'];
                    foreach ($standardColumns as $column) {
                        if (!in_array($column, $columnNames)) {
                            $columnNames[] = $column;
                        }
                    }

                    return $columnNames;
                } catch (\Exception $e) {
                    return [];
                }
            });
    }

    /**
     * Get global variables from sh_global_values page for all languages
     *
     * @return array List of global variable names with 'global.' prefix
     */
    public function getGlobalVariables(): array
    {
        // Use the centralized global variable service
        return $this->globalVariableService->getGlobalVariableNames();
    }

    /**
     * Get hardcoded system variables
     *
     * @return array List of system variable names with 'system.' prefix
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
            'project_name'
        ];

        // Add system. prefix to all variables
        return array_map(fn($var) => 'system.' . $var, $systemVars);
    }
}
