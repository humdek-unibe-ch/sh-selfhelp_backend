<?php

namespace App\Service\CMS;

use App\Entity\Field;
use App\Entity\Page;
use App\Repository\DataTableRepository;
use App\Repository\PageRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\Core\BaseService;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for resolving data variables from sections
 * Generates a list of available variables for use in dropdowns and templates
 */
class DataVariableResolver extends BaseService
{
    private const SH_GLOBAL_VALUES_KEYWORD = 'sh_global_values';
    private const PF_GLOBAL_VALUES = 'global_values'; // Page field name for global values

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataTableRepository $dataTableRepository,
        private readonly PageRepository $pageRepository,
        private readonly DataService $dataService,
        private readonly CacheService $cache
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
        $variables = [];

        // Get custom variables from data_config if available
        $customVariables = $this->parseDataConfig($section);
        if (!empty($customVariables)) {
            $variables = array_merge($variables, $customVariables);
        } else {
            // Get table variables if no custom variables defined
            $tableVariables = $this->getTableVariables($section);
            $variables = array_merge($variables, $tableVariables);
        }

        // Add global variables
        $globalVariables = $this->getGlobalVariables();
        $variables = array_merge($variables, $globalVariables);

        // Add system variables
        $systemVariables = $this->getSystemVariables();
        $variables = array_merge($variables, $systemVariables);

        return $variables;
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
     * Get global variables from sh_global_values page
     *
     * @return array List of global variable names with 'global.' prefix
     */
    private function getGlobalVariables(): array
    {
        $cacheKey = "global_variables";
        $globalPage = $this->pageRepository->findOneBy(['keyword' => self::SH_GLOBAL_VALUES_KEYWORD]);

        return $this->cache        
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $globalPage->getId())
            ->getList($cacheKey, function () use ($globalPage) {
                $variables = [];

                try {
                    // Find the sh_global_values page
                    if (!$globalPage) {
                        return $variables;
                    }

                    // Get the global values from page fields
                    // This follows the pattern from the old code
                    $globalValuesJson = $this->getPageFieldValue($globalPage, self::PF_GLOBAL_VALUES);
                    if ($globalValuesJson) {
                        $globalValues = json_decode($globalValuesJson, true);
                        if (is_array($globalValues)) {
                            foreach (array_keys($globalValues) as $key) {
                                $variables[] = 'global.' . $key;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // If there's an error getting global values, continue without them
                }

                return $variables;
            });
    }

    /**
     * Get page field value by field name
     *
     * @param Page $page The page entity
     * @param string $fieldName The field name to look for
     * @return string|null The field value or null if not found
     */
    private function getPageFieldValue(Page $page, string $fieldName): ?string
    {
        try {
            $conn = $this->entityManager->getConnection();
            $sql = "
                SELECT pft.content
                FROM pages_fields_translation pft
                INNER JOIN fields f ON pft.id_fields = f.id
                WHERE pft.id_pages = :pageId
                AND f.name = :fieldName
                AND pft.id_languages = 1
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('pageId', $page->getId(), \PDO::PARAM_INT);
            $stmt->bindValue('fieldName', $fieldName, \PDO::PARAM_STR);
            $result = $stmt->executeQuery();

            $row = $result->fetchAssociative();
            return $row ? $row['content'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get hardcoded system variables
     *
     * @return array List of system variable names (without scope prefix)
     */
    private function getSystemVariables(): array
    {
        return [
            'user_name',
            'user_email',
            'user_code',
            'platform',
            'language',
            'current_date',
            'current_datetime',
            'project_name'
        ];
    }
}
