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
        private readonly InterpolationService $interpolationService
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
            // Build nested hierarchical structure
            $sections = $this->sectionUtilityService->buildNestedSections($flatSections, true, $languageId);

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

            $this->sectionUtilityService->applySectionsData($sections, $languageId);

            // Apply variable interpolation for retrieved_data
            $this->applyInterpolationToSections($sections);

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
     * Apply variable interpolation to sections recursively
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

        // Interpolate content fields
        if (isset($section['content']) && is_array($section['content'])) {
            $section['content'] = $this->interpolationService->interpolateArray($section['content'], ...$interpolationData);
        }

        // Interpolate meta fields if they exist
        if (isset($section['meta']) && is_array($section['meta'])) {
            $section['meta'] = $this->interpolationService->interpolateArray($section['meta'], ...$interpolationData);
        }

        // Interpolate content fields that may contain variables
        $this->interpolateContentFields($section, $interpolationData);
    }

    /**
     * Interpolate content fields that may contain {{variable}} patterns
     *
     * @param array &$section The section to process (passed by reference)
     * @param array $interpolationData The data arrays for interpolation
     */
    private function interpolateContentFields(array &$section, array $interpolationData): void
    {
        // Direct string fields that may contain variables
        $directStringFields = [
            'css',
            'css_mobile',
            'condition'
        ];

        foreach ($directStringFields as $field) {
            if (isset($section[$field]) && is_string($section[$field])) {
                $section[$field] = $this->interpolationService->interpolate($section[$field], ...$interpolationData);
            }
        }

        // Object fields with "content" sub-property that may contain variables
        $contentObjectFields = [
            // Text content fields
            'text',
            'html',
            'markdown',
            'content',

            // Form field labels and descriptions
            'label',
            'placeholder',
            'description',

            // Button labels
            'btn_save_label',
            'btn_update_label',
            'btn_cancel_label',

            // Alert messages
            'alert_success',
            'alert_error',

            // Form names and titles
            'name',
            'title',

            // URLs and redirects
            'redirect_at_end',
            'btn_cancel_url',

            // Mantine translatable content fields (based on TypeScript definitions)
            'mantine_rich_text_editor_placeholder',
            'mantine_highlight_highlight',           // Translatable text to highlight
            'mantine_spoiler_show_label',            // Translatable show label
            'mantine_spoiler_hide_label',            // Translatable hide label
            'mantine_switch_on_label',               // Translatable on label
            'mantine_switch_off_label',              // Translatable off label
            'mantine_tooltip_label',                 // Translatable tooltip text
            'mantine_list_item_content',             // Translatable list item content
            'mantine_datepicker_placeholder',        // Translatable placeholder
            'mantine_color_picker_button_label',     // Translatable button label
            'mantine_text_gradient',                 // Gradient configuration (might contain variables)
            'mantine_accordion_item_value',          // Accordion item value
            'mantine_accordion_default_value',       // Default open accordion items
            'confirmation_title',                    // Confirmation dialog title
            'confirmation_continue',                 // Continue button text
            'confirmation_message',                  // Confirmation message
            'mantine_notification_title',            // Notification title
            'mantine_title_text_wrap',               // Text wrap setting
            'mantine_text_gradient',                 // Text gradient configuration
            'mantine_blockquote_icon_size',          // Icon size (numeric, but might be templated)

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
