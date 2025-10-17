<?php

namespace App\Service\CMS\Frontend;

use App\Entity\CmsPreference;
use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Repository\SectionsFieldsTranslationRepository;
use App\Repository\StylesFieldRepository;
use App\Repository\PagesFieldsTranslationRepository;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Service\CMS\Common\SectionUtilityService;
use App\Service\Core\BaseService;
use App\Service\Core\ConditionService;
use App\Service\Core\InterpolationService;
use App\Service\Core\UserContextAwareService;
use Doctrine\ORM\EntityManagerInterface;

class PageService extends BaseService
{
    // Default values for language
    private const PROPERTY_LANGUAGE_ID = 1; // Language ID 1 is for properties, not a real language

    public function __construct(
        private readonly SectionRepository $sectionRepository,
        private readonly LookupService $lookupService,
        private readonly ACLService $aclService,
        private readonly PageRepository $pageRepository,
        private readonly SectionsFieldsTranslationRepository $translationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StylesFieldRepository $stylesFieldRepository,
        private readonly SectionUtilityService $sectionUtilityService,
        private readonly PagesFieldsTranslationRepository $pagesFieldsTranslationRepository,
        private readonly CacheService $cache,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly DataService $dataService,
        private readonly InterpolationService $interpolationService,
        private readonly ConditionService $conditionService
    ) {
    }

    /**
     * Recursively sorts pages by nav_position
     * Pages with null nav_position will be placed at the end and sorted alphabetically by keyword
     */
    private function sortPagesRecursively(array &$pages): void
    {
        usort($pages, function ($a, $b) {
            // If both positions are null, sort alphabetically by keyword
            if ($a['nav_position'] === null && $b['nav_position'] === null) {
                return strcasecmp($a['keyword'] ?? '', $b['keyword'] ?? '');
            }

            // If only a's position is null, it should go after b
            if ($a['nav_position'] === null) {
                return 1;
            }

            // If only b's position is null, it should go after a
            if ($b['nav_position'] === null) {
                return -1;
            }

            // If both have positions, compare them normally
            return $a['nav_position'] <=> $b['nav_position'];
        });

        foreach ($pages as &$page) {
            if (!empty($page['children'])) {
                $this->sortPagesRecursively($page['children']);
            }
        }
    }

    /**
     * Get all published pages for the current user, filtered by mode and ACL
     *
     * @param string $mode Either 'web' or 'mobile'
     * @param int|null $language_id Optional language ID for translations
     * @return array
     */
    public function getAllAccessiblePagesForUser(string $mode, bool $admin, ?int $language_id = null): array
    {
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = 1; // guest user
        if ($user) {
            $userId = $user->getId();
        }

        // Determine which language ID to use for translations
        $languageId = $this->determineLanguageId($language_id);

        // Try to get from cache first
        $cacheKey = "pages_{$mode}_{$admin}_{$languageId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $languageId)
            ->getList($cacheKey, function () use ($mode, $admin, $languageId, $userId) {
                // Get all pages with ACL for the user using the ACLService (cached)
                $allPages = $this->aclService->getAllUserAcls($userId);

                // Determine which type to remove based on mode
                $removeType = $mode === LookupService::PAGE_ACCESS_TYPES_MOBILE ? LookupService::PAGE_ACCESS_TYPES_WEB : LookupService::PAGE_ACCESS_TYPES_MOBILE;
                $removeTypeId = $this->lookupService->getLookupIdByCode(LookupService::PAGE_ACCESS_TYPES, $removeType);

                // If mode is both, do not remove any type
                $filteredPages = array_values(array_filter($allPages, function ($item) use ($removeTypeId, $mode, $admin) {

                    // Base ACL check
                    if ($item['acl_select'] != 1) {
                        return false;
                    }

                    // If admin is true, then all pages (normal filtering)
                    // If not admin, then only pages with id_type = 2 or 3 (core and experiment pages)
                    if (!$admin && isset($item['id_type']) && !in_array($item['id_type'], [2, 3])) {
                        return false;
                    }

                    // Apply mode-based filtering
                    if ($mode === LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB) {
                        return true;
                    }

                    return $item['id_pageAccessTypes'] != $removeTypeId;
                }));

                // Get default language ID for fallback translations
                $defaultLanguageId = null;
                try {
                    $cmsPreference = $this->entityManager->getRepository(CmsPreference::class)->findOneBy([]);
                    if ($cmsPreference && $cmsPreference->getDefaultLanguage()) {
                        $defaultLanguageId = $cmsPreference->getDefaultLanguage()->getId();
                    }
                } catch (\Exception $e) {
                    // If there's an error getting the default language, continue without fallback
                }

                // Extract page IDs for fetching translations
                $pageIds = array_column($filteredPages, 'id_pages');

                // Fetch all page title translations in one query
                $pageTitleTranslations = [];
                if (!empty($pageIds)) {
                    $pageTitleTranslations = $this->pagesFieldsTranslationRepository->fetchTitleTranslationsWithFallback(
                        $pageIds,
                        $languageId,
                        $defaultLanguageId
                    );
                }

                // Create a map of pages by their ID for quick lookup
                $pagesMap = [];
                foreach ($filteredPages as &$page) {

                    // Add title translations to page
                    $pageId = $page['id_pages'];
                    $page['title'] = null; // Default title
                    if (isset($pageTitleTranslations[$pageId])) {
                        // Look for a 'title' field first, otherwise take the first available field
                        if (isset($pageTitleTranslations[$pageId]['title'])) {
                            $page['title'] = $pageTitleTranslations[$pageId]['title'];
                        } else {
                            // Take the first available translation field as title
                            $page['title'] = reset($pageTitleTranslations[$pageId]) ?: null;
                        }
                    }

                    $page['children'] = []; // Initialize children array
                    $pagesMap[$page['id_pages']] = &$page;
                }
                unset($page); // Break the reference
    
                // Build the hierarchy
                $nestedPages = [];
                foreach ($pagesMap as $id => &$page) {
                    if (isset($page['parent']) && $page['parent'] !== null && isset($pagesMap[$page['parent']])) {
                        // This is a child page, add it to its parent's children array
                        $pagesMap[$page['parent']]['children'][] = &$page;
                    } else {
                        // This is a root level page
                        $nestedPages[] = &$page;
                    }
                }
                unset($page); // Break the reference
    
                // Optional: Sort children by nav_position if needed
                $this->sortPagesRecursively($nestedPages);

                // Cache the result for this user
                return $nestedPages;
            });
    }

    /**
     * Get page by ID with translated sections
     * 
     * @param int $page_id The page ID
     * @param int|null $language_id Optional language ID for translations
     * @return array The page object with translated sections
     * @throws \App\Exception\ServiceException If page not found or access denied
     */
    public function getPage(int $page_id, ?int $language_id = null): array
    {
        // Determine which language ID to use for translations
        $languageId = $this->determineLanguageId($language_id);

        // Get current user for caching
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? $user->getId() : 1; // guest user

        // Try to get from cache first
        $cacheKey = "page_{$page_id}_{$languageId}";

        // First get the page to get its ID for entity scope
        $page = $this->pageRepository->find($page_id);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        // Get flat sections to extract data table dependencies for page-level cache
        $flatSections = $this->sectionRepository->fetchSectionsHierarchicalByPageId($page_id);
        $dataTableConfigs = $this->extractDataTableDependencies($flatSections, $page_id);

        // Build cache service with entity scopes including data table dependencies
        $cacheService = $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $languageId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $page->getId());

        // Add data table entity scopes for each data table this page depends on
        foreach ($dataTableConfigs as $dataTableId => $config) {
            // Always add data table scope for global configs (current_user: false)
            if ($config['has_global_config']) {
                $cacheService = $cacheService->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId);
            }

            // For user-specific configs (current_user: true), add user-data-table combined scope
            if ($config['has_current_user_config']) {
                $cacheService = $cacheService
                    ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId)
                    ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
            }
        }

        return $cacheService->getItem($cacheKey, function () use ($page_id, $languageId, $page) {
            // Check if user has access to the page
            $this->userContextAwareService->checkAccess($page->getKeyword(), 'select');

            $pageData = [
                'page' => [
                    'id' => $page->getId(),
                    'keyword' => $page->getKeyword(),
                    'url' => $page->getUrl(),
                    'parent_page_id' => $page->getParentPage()?->getId(),
                    'is_headless' => $page->isHeadless(),
                    'nav_position' => $page->getNavPosition(),
                    'footer_position' => $page->getFooterPosition(),
                    'sections' => $this->getPageSections($page->getId(), $languageId)
                ]
            ];

            return $pageData;
        });
    }

    /**
     * Extract data table dependencies from sections (with caching)
     *
     * @param array $flatSections Flat sections array from repository
     * @param int $pageId The page ID for caching key
     * @return array Associative array with data table IDs as keys and config info as values
     */
    private function extractDataTableDependencies(array $flatSections, int $pageId): array
    {
        $cacheKey = "page_data_table_deps_{$pageId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId)
            ->getList($cacheKey, function () use ($flatSections) {
                $dataTableConfigs = [];

                foreach ($flatSections as $section) {
                    if (isset($section['data_config']) && $section['data_config'] !== null) {
                        // Parse data_config as JSON string to array
                        $dataConfigArray = is_string($section['data_config'])
                            ? json_decode($section['data_config'], true)
                            : $section['data_config'];

                        if (is_array($dataConfigArray)) {
                            // data_config is an array of configuration objects, process each one
                            foreach ($dataConfigArray as $config) {
                                if (isset($config['table'])) {
                                    $tableName = $config['table'];

                                    // Get data table by name to get its ID
                                    try {
                                        $dataTable = $this->dataService->getDataTableByName($tableName);
                                        if ($dataTable) {
                                            $dataTableId = $dataTable->getId();
                                            $currentUser = $config['current_user'] ?? true; // Default to true

                                            // If we haven't seen this data table before, initialize it
                                            if (!isset($dataTableConfigs[$dataTableId])) {
                                                $dataTableConfigs[$dataTableId] = [
                                                    'has_current_user_config' => false,
                                                    'has_global_config' => false
                                                ];
                                            }

                                            // Track if this table has current_user configurations
                                            if ($currentUser) {
                                                $dataTableConfigs[$dataTableId]['has_current_user_config'] = true;
                                            } else {
                                                $dataTableConfigs[$dataTableId]['has_global_config'] = true;
                                            }
                                        }
                                    } catch (\Exception $e) {
                                        // If there's an error getting the data table, continue without it
                                        // This prevents cache failures due to missing/invalid data tables
                                    }
                                }
                            }
                        }
                    }
                }

                return $dataTableConfigs;
            });
    }

    /**
     * Get page sections with translations
     * 
     * @param int $page_id The page ID
     * @param int $languageId The language ID for translations
     * @return array The page sections in a hierarchical structure with translations
     */
    public function getPageSections(int $page_id, int $languageId): array
    {
        // Get current user for caching
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? $user->getId() : 1; // guest user

        $cacheKey = "page_sections_{$page_id}_{$languageId}";

        // Get flat sections first to extract data table dependencies
        $flatSections = $this->sectionRepository->fetchSectionsHierarchicalByPageId($page_id);

        // Extract data table dependencies for cache scoping
        $dataTableConfigs = $this->extractDataTableDependencies($flatSections, $page_id);

        // Build cache service with entity scopes
        $cacheService = $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $page_id);

        // Add data table entity scopes for each data table this page depends on
        foreach ($dataTableConfigs as $dataTableId => $config) {
            // Always add data table scope for global configs (current_user: false)
            if ($config['has_global_config']) {
                $cacheService = $cacheService->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId);
            }

            // For user-specific configs (current_user: true), add user-data-table combined scope
            if ($config['has_current_user_config']) {
                $cacheService = $cacheService
                    ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId)
                    ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
            }
        }

        return $cacheService->getList($cacheKey, function () use ($flatSections, $languageId) {
            // Build nested hierarchical structure (without applying data initially)
            $sections = $this->sectionUtilityService->buildNestedSections($flatSections, false, $languageId);

            // Extract all section IDs from the hierarchical structure
            $sectionIds = $this->sectionUtilityService->extractSectionIds($sections);

            // Get default language ID for fallback translations
            $defaultLanguageId = null;
            try {
                $cmsPreference = $this->entityManager->getRepository(CmsPreference::class)->findOneBy([]);
                if ($cmsPreference && $cmsPreference->getDefaultLanguage()) {
                    $defaultLanguageId = $cmsPreference->getDefaultLanguage()->getId();
                }
            } catch (\Exception $e) {
                // If there's an error getting the default language, continue without fallback
            }

            // Fetch all translations for these sections with fallback to default language
            $translations = $this->translationRepository->fetchTranslationsForSectionsWithFallback(
                $sectionIds,
                $languageId,
                $defaultLanguageId
            );

            // Fetch property translations (language ID 1) for fields of type 1
            $propertyTranslations = $this->translationRepository->fetchTranslationsForSections(
                $sectionIds,
                self::PROPERTY_LANGUAGE_ID
            );

            // Apply translations to the sections recursively
            // Note: fallback is now handled internally by fetchTranslationsForSectionsWithFallback
            $this->sectionUtilityService->applySectionTranslations($sections, $translations, [], $propertyTranslations);

            // Process sections recursively with proper data inheritance and sequential operations
            // This replaces the bulk applySectionsData, interpolation, and condition filtering
            $user = $this->userContextAwareService->getCurrentUser();
            $userId = $user ? $user->getId() : null;
            $sections = $this->processSectionsRecursively($sections, [], $userId, $languageId);

            return $sections;
        });
    }

    /**
     * Determine which language ID to use for translations
     * 
     * @param int|null $language_id Explicitly provided language ID
     * @return int The language ID to use
     */
    private function determineLanguageId(?int $language_id = null): int
    {
        // If language_id is explicitly provided, use it
        if ($language_id !== null) {
            return $language_id;
        }

        // If user is logged in, use their preferred language
        $user = $this->userContextAwareService->getCurrentUser();
        if ($user && $user->getLanguage()) {
            return $user->getLanguage()->getId();
        }

        // Otherwise use default language from CMS preferences
        try {
            return $this->cache
                ->withCategory(CacheService::CATEGORY_CMS_PREFERENCES)
                ->getItem("cms_preferences_default_language_id", fn() => $this->entityManager->getRepository(CmsPreference::class)->findOneBy([])->getDefaultLanguage()->getId());
        } catch (\Exception $e) {
            // If there's an error getting the default language, use fallback
        }

        // Fallback to language ID 2 if no default language is configured
        return 2;
    }

    /**
     * Process sections recursively with proper data inheritance and sequential operations
     *
     * For each section:
     * 1. Apply first interpolation pass using parent data
     * 2. Retrieve data from data_config
     * 3. Apply second interpolation pass using newly retrieved data
     * 4. Evaluate condition to determine if section should be included
     * 5. Process children recursively if condition passes
     *
     * @param array $sections The sections to process
     * @param array $parentData Parent data to inherit (default empty array)
     * @param int|null $userId User ID for condition evaluation
     * @param int $languageId Language ID for data retrieval
     * @return array Processed sections that pass conditions
     */
    private function processSectionsRecursively(array $sections, array $parentData = [], ?int $userId = null, int $languageId = 1): array
    {
        $processedSections = [];

        foreach ($sections as $section) {
            // Step 1: First interpolation pass using parent data
            $this->applyInterpolationWithData($section, $parentData);

            // Step 2: Retrieve data from data_config
            $this->retrieveSectionData($section, $parentData, $languageId);

            // Combine parent data with newly retrieved data for this section
            $sectionData = array_merge($parentData, $section['retrieved_data'] ?? []);

            // Update the section's retrieved_data with the merged data (for visibility to children and interpolation)
            if (!empty($sectionData)) {
                $section['retrieved_data'] = $sectionData;
            }

            // Step 3: Second interpolation pass using combined data
            $this->applyInterpolationWithData($section, $sectionData);

            // Step 4: Evaluate condition
            $conditionResult = $this->evaluateSectionCondition($section, $userId);

            // Step 5: If condition passes, include section and process children
            if ($conditionResult['passes']) {
                // Add condition debug info if available
                if (isset($conditionResult['debug'])) {
                    $section['condition_debug'] = $conditionResult['debug'];
                }

                // Process children recursively with inherited data
                if (isset($section['children']) && is_array($section['children'])) {
                    $section['children'] = $this->processSectionsRecursively($section['children'], $sectionData, $userId, $languageId);
                }

                $processedSections[] = $section;
            }
        }

        return $processedSections;
    }

    /**
     * Apply interpolation to a section using provided data
     *
     * @param array &$section The section to interpolate
     * @param array $interpolationData The data to use for interpolation
     */
    private function applyInterpolationWithData(array &$section, array $interpolationData): void
    {
        if (empty($interpolationData)) {
            return;
        }

        // Check if debug is enabled for this section
        $isDebugEnabled = isset($section['debug']) && $section['debug'];

        // Interpolate content fields
        if (isset($section['content']) && is_array($section['content'])) {
            $section['content'] = $this->interpolationService->interpolateArray($section['content'], $interpolationData);
        }

        // Interpolate meta fields if they exist
        if (isset($section['meta']) && is_array($section['meta'])) {
            $section['meta'] = $this->interpolationService->interpolateArray($section['meta'], $interpolationData);
        }

        // Interpolate content fields that may contain variables
        $this->interpolateContentFields($section, [$interpolationData], $isDebugEnabled);
    }

    /**
     * Retrieve data for a single section from its data_config
     *
     * @param array &$section The section to retrieve data for
     * @param array $availableData Available data for interpolation (parent data)
     * @param int $languageId The language ID for data retrieval
     */
    private function retrieveSectionData(array &$section, array $availableData, int $languageId): void
    {

        // Handle data_config field - parse and retrieve data
        if (isset($section['data_config']) && $section['data_config'] !== null) {
            // Parse data_config as JSON string to array
            $dataConfigArray = is_string($section['data_config'])
                ? json_decode($section['data_config'], true)
                : $section['data_config'];

            if (is_array($dataConfigArray)) {
                // data_config is an array of configuration objects, process each one
                $retrievedData = [];
                foreach ($dataConfigArray as $configIndex => $config) {
                    try {
                        // Interpolate the config before retrieving data
                        $interpolatedConfig = $this->interpolateDataConfig($config, $availableData);
                        $configData = $this->sectionUtilityService->retrieveData($interpolatedConfig, [], $languageId);
                        // Use the scope as key if available, otherwise use index
                        $key = isset($config['scope']) ? $config['scope'] : $configIndex;
                        $retrievedData[$key] = $configData;
                    } catch (\Exception $e) {
                        // If there's an error retrieving data, continue without it
                        // This prevents failures due to invalid data configs
                    }
                }
                // Add retrieved data as a new field
                $section['retrieved_data'] = $retrievedData;
                $section['data_config'] = $dataConfigArray;
            }
        }
    }

    /**
     * Interpolate variables in data config before data retrieval
     *
     * @param array $config The data config to interpolate
     * @param array $availableData Available data for interpolation
     * @return array The interpolated config
     */
    private function interpolateDataConfig(array $config, array $availableData): array
    {
        if (empty($availableData)) {
            return $config;
        }

        $interpolatedConfig = $config;

        // Interpolate string fields that might contain variables
        $fieldsToInterpolate = ['filter', 'table', 'retrieve'];

        foreach ($fieldsToInterpolate as $field) {
            if (isset($interpolatedConfig[$field]) && is_string($interpolatedConfig[$field])) {
                $interpolatedConfig[$field] = $this->interpolationService->interpolate($interpolatedConfig[$field], $availableData);
            }
        }

        // Interpolate fields array if it exists
        if (isset($interpolatedConfig['fields']) && is_array($interpolatedConfig['fields'])) {
            foreach ($interpolatedConfig['fields'] as &$field) {
                if (is_array($field)) {
                    foreach ($field as $key => $value) {
                        if (is_string($value)) {
                            $field[$key] = $this->interpolationService->interpolate($value, $availableData);
                        }
                    }
                }
            }
        }

        // Interpolate map_fields array if it exists
        if (isset($interpolatedConfig['map_fields']) && is_array($interpolatedConfig['map_fields'])) {
            foreach ($interpolatedConfig['map_fields'] as &$mapField) {
                if (is_array($mapField)) {
                    foreach ($mapField as $key => $value) {
                        if (is_string($value)) {
                            $mapField[$key] = $this->interpolationService->interpolate($value, $availableData);
                        }
                    }
                }
            }
        }

        return $interpolatedConfig;
    }

    /**
     * Evaluate condition for a section
     *
     * @param array $section The section to evaluate
     * @param int|null $userId User ID for condition evaluation
     * @return array Result with 'passes' boolean and optional 'debug' info
     */
    private function evaluateSectionCondition(array $section, ?int $userId): array
    {
        // Check if section has a condition
        if (!isset($section['condition']) || empty($section['condition'])) {
            return ['passes' => true]; // No condition means section passes
        }

        $conditionResult = $this->conditionService->evaluateCondition(
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

        $debugInfo = [
            "result" => $conditionResult['result'],
            "error" => $conditionResult['fields'],
            "variables" => $conditionResult['debug']['variables'],
            "condition_object" => $conditionObject
        ];

        // Ensure condition is returned as proper JSON string (not escaped)
        if (is_string($section['condition'])) {
            // Handle escaped JSON strings - decode to get proper JSON string
            $decoded = json_decode($section['condition']);
            if ($decoded !== null) {
                $section['condition'] = json_encode($decoded);
            }
        }

        return [
            'passes' => $conditionResult['result'],
            'debug' => $debugInfo
        ];
    }

    /**
     * Apply variable interpolation to sections recursively (legacy method for backward compatibility)
     *
     * Replaces {{variable_name}} patterns in content fields with values from retrieved_data
     *
     * @param array &$sections The sections array to process (passed by reference)
     */
    private function applyInterpolationToSections(array &$sections): void
    {
        foreach ($sections as &$section) {
            // Apply interpolation to the current section's content fields
            $this->interpolateSectionContent($section);

            // Recursively apply to children if they exist
            if (isset($section['children']) && is_array($section['children'])) {
                $this->applyInterpolationToSections($section['children']);
            }
        }
    }

    /**
     * Apply interpolation to a single section's content fields
     *
     * @param array &$section The section to process (passed by reference)
     */
    private function interpolateSectionContent(array &$section): void
    {
        // Check if section has retrieved_data to interpolate with
        $interpolationData = [];
        if (isset($section['retrieved_data']) && is_array($section['retrieved_data'])) {
            $interpolationData[] = $section['retrieved_data'];
        }

        // If no data to interpolate with, skip
        if (empty($interpolationData)) {
            return;
        }

        // Check if debug is enabled for this section
        $isDebugEnabled = isset($section['debug']) && $section['debug'];

        // Interpolate content fields
        if (isset($section['content']) && is_array($section['content'])) {
            $section['content'] = $this->interpolationService->interpolateArray($section['content'], ...$interpolationData);
        }

        // Interpolate meta fields if they exist
        if (isset($section['meta']) && is_array($section['meta'])) {
            $section['meta'] = $this->interpolationService->interpolateArray($section['meta'], ...$interpolationData);
        }

        // Interpolate content fields that may contain variables
        // Include condition interpolation only if debug is enabled
        $this->interpolateContentFields($section, $interpolationData, $isDebugEnabled);
    }

    /**
     * Interpolate content fields that may contain {{variable}} patterns
     *
     * @param array &$section The section to process (passed by reference)
     * @param array $interpolationData The data arrays for interpolation
     * @param bool $includeCondition Whether to include condition field interpolation (only when debug enabled)
     */
    private function interpolateContentFields(array &$section, array $interpolationData, bool $includeCondition = false): void
    {
        // Direct string fields that may contain variables
        $directStringFields = [
            'css',
            'css_mobile',
            'condition'
        ];

        foreach ($directStringFields as $field) {
            if (isset($section[$field]) && is_string($section[$field])) {
                // Special handling for condition field with debug support
                if ($section['debug'] && $field === 'condition') {
                    $section[$field] = $this->interpolationService->interpolateConditionWithDebug($section, ...$interpolationData);
                } else {
                    $section[$field] = $this->interpolationService->interpolate($section[$field], ...$interpolationData);
                }
            }
        }

        // Object fields with "content" sub-property that may contain variables
        // Based on TypeScript IContentField<string> definitions - all user-editable content fields
        $contentObjectFields = [
            // Core content fields
            'content',                              // Main content field
            'text',                                 // Text content
            'html',                                 // HTML content
            'markdown',                             // Markdown content
            'title',                                // Titles
            'name',                                 // Names (sometimes user-editable)

            // Form field content
            'label',                                // Field labels
            'placeholder',                          // Input placeholders
            'description',                          // Field descriptions
            'value',                                // Field values (sometimes contain text)

            // Button and action labels
            'btn_save_label',                       // Save button labels
            'btn_update_label',                     // Update button labels
            'btn_cancel_label',                     // Cancel button labels
            'label_cancel',                         // Cancel button label (alternative)

            // Alert and message content
            'alert_success',                        // Success alert messages
            'alert_error',                          // Error alert messages
            'close_button_label',                   // Close button labels

            // URLs and navigation
            'redirect_at_end',                      // Redirect URLs
            'btn_cancel_url',                       // Cancel button URLs
            'url',                                  // General URLs
            'page_keyword',                         // Page keywords

            // Confirmation dialogs
            'confirmation_title',                   // Confirmation dialog titles
            'confirmation_continue',                // Continue button text
            'confirmation_message',                 // Confirmation messages

            // Mantine component translatable content
            'mantine_notification_title',           // Notification titles
            'mantine_alert_title',                  // Alert titles
            'mantine_spoiler_show_label',           // Spoiler show labels
            'mantine_spoiler_hide_label',           // Spoiler hide labels
            'mantine_switch_on_label',              // Switch on labels
            'mantine_switch_off_label',             // Switch off labels
            'mantine_tooltip_label',                // Tooltip text
            'mantine_list_item_content',            // List item content
            'mantine_highlight_highlight',          // Text to highlight
            'mantine_datepicker_placeholder',       // Date picker placeholders
            'mantine_color_picker_button_label',    // Color picker button labels
            'mantine_rich_text_editor_placeholder', // Rich text editor placeholders
            'mantine_text_gradient',                // Text gradient configurations
            'mantine_accordion_item_value',         // Accordion item values
            'mantine_accordion_default_value',      // Default accordion values
            'mantine_title_text_wrap',              // Title text wrap settings
            'mantine_blockquote_cite',              // Blockquote citations
            'mantine_background_image_src',         // Background image sources
            'mantine_fieldset_legend',              // Fieldset legends
            'mantine_list_item_icon',               // List item icons
            'mantine_carousel_next_control_icon',   // Carousel control icons
            'mantine_carousel_previous_control_icon', // Carousel control icons
            'mantine_left_icon',                    // Left icons
            'mantine_right_icon',                   // Right icons
            'mantine_avatar_initials',              // Avatar initials
            'mantine_chip_on_value',                // Chip on values
            'mantine_chip_off_value',               // Chip off values
            'mantine_switch_on_value',              // Switch on values
            'mantine_switch_off_value',             // Switch off values
            'mantine_rating_empty_icon',            // Rating empty icons
            'mantine_rating_full_icon',             // Rating full icons
            'mantine_progress_section_label',       // Progress section labels
            'mantine_tooltip_label',                // Tooltip labels (duplicate for completeness)
            'mantine_accordion_item_icon',          // Accordion item icons
            'mantine_theme_icon',                   // Theme icons

            // Additional translatable fields from various components
            'alt',                                  // Image alt text
            'caption',                              // Figure captions
            'caption_title',                        // Caption titles
            'img_src',                              // Image sources
            'cite',                                 // Blockquote citations
            'legend',                               // Fieldset legends
            'subject_user',                         // Email subjects
            'email_user',                           // Email addresses
            'anonymous_user_name_description',      // User descriptions
            'pw_placeholder',                       // Password placeholders
            'success',                              // Success messages
            'login_title',                          // Login titles
            'subtitle',                             // Subtitles
            'type',                                 // Types (sometimes contain text)
            'is_active',                            // Active states (sometimes contain text)
            'icon',                                 // Icon references
            'chip_value',                           // Chip values
            'checkbox_value',                       // Checkbox values
            'mantine_checkbox_icon',                // Checkbox icons
            'mantine_file_input_accept',            // File input accept types
            'mantine_color_picker_saturation_label', // Color picker labels
            'mantine_color_picker_hue_label',       // Color picker labels
            'mantine_color_picker_alpha_label',     // Color picker labels
            'mantine_segmented_control_data',       // Segmented control data (JSON)
            'mantine_combobox_options',             // Combobox options (JSON)
            'mantine_multi_select_data',            // Multi-select data (JSON)
            'mantine_radio_options',                // Radio options (JSON)
            'mantine_slider_marks_values',          // Slider marks (JSON)
            'mantine_color_picker_swatches',        // Color swatches (JSON)
            'mantine_color_input_swatches',         // Color input swatches
            'mantine_file_input_accept',            // File accept types (duplicate)
            'mantine_combobox_data',                // Combobox data (JSON)
            'mantine_carousel_embla_options',       // Carousel options (JSON)

            // Any other fields that might contain user-editable content
        ];

        foreach ($contentObjectFields as $field) {
            if (isset($section[$field]) && is_array($section[$field]) && isset($section[$field]['content'])) {
                if (is_string($section[$field]['content'])) {
                    $section[$field]['content'] = $this->interpolationService->interpolate($section[$field]['content'], ...$interpolationData);
                }
            }
        }

        // Special handling for nested structures like children
        if (isset($section['children']) && is_array($section['children'])) {
            foreach ($section['children'] as &$child) {
                $this->interpolateContentFields($child, $interpolationData);
            }
        }
    }
}
