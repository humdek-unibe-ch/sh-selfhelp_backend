<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Frontend;

use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Repository\SectionsFieldsTranslationRepository;
use App\Repository\PagesFieldsTranslationRepository;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Service\CMS\Common\SectionUtilityService;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\BaseService;
use App\Service\Core\ConditionService;
use App\Service\Core\InterpolationService;
use App\Service\Core\UserContextAwareService;
use App\Service\Auth\UserContextService;

class PageService extends BaseService
{
    // Default values for language
    private const PROPERTY_LANGUAGE_ID = 1; // Language ID 1 is for properties, not a real language

    /**
     * Page behaviour property field (display=0) projected onto the single-page
     * content response so the web renderer can open the page inside a modal.
     * Seeded by Version20260630151141; consumed by `@selfhelp/shared`
     * `IPageContent.open_in_modal` and the frontend `DynamicPageClient`.
     */
    private const OPEN_IN_MODAL_FIELD = 'open_in_modal';

    /**
     * Modal-size page property fields (display=0) projected next to
     * `open_in_modal` on the single-page content response. Free-text CSS
     * lengths or `auto`; empty means "use the frontend default (80%)". Seeded by
     * Version20260630172821; consumed by `@selfhelp/shared`
     * `IPageContent.modal_width|modal_height` and `DynamicPageClient`.
     *
     * @var list<string>
     */
    private const MODAL_SIZE_FIELDS = ['modal_width', 'modal_height'];

    // Pages where should_fallback is meaningful: keyword must match a section style name.
    // keyword => style name to look for. If style name equals keyword, just use keyword as value.
    private const FALLBACK_CHECK_KEYWORDS = [
        'login'                    => 'login',
        'two-factor-authentication' => 'two-factor-auth',
        'reset-password'           => 'reset-password',
        'profile'                  => 'profile',
        'no-access'                => 'no-access',
        'no-access-guest'          => 'no-access',
        'missing'                  => 'missing',
    ];

    public function __construct(
        private readonly SectionRepository $sectionRepository,
        private readonly LookupService $lookupService,
        private readonly ACLService $aclService,
        private readonly PageRepository $pageRepository,
        private readonly SectionsFieldsTranslationRepository $translationRepository,
        private readonly SectionUtilityService $sectionUtilityService,
        private readonly PagesFieldsTranslationRepository $pagesFieldsTranslationRepository,
        private readonly CacheService $cache,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly DataService $dataService,
        private readonly InterpolationService $interpolationService,
        private readonly ConditionService $conditionService,
        private readonly \App\Repository\PageVersionRepository $pageVersionRepository,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly \App\Routing\PageRouteResolverService $pageRouteResolver
    ) {
    }

    /**
     * Recursively sorts pages alphabetically by keyword.
     *
     * @param list<array<string, mixed>> $pages
     */
    private function sortPagesRecursively(array &$pages): void
    {
        usort($pages, function (array $a, array $b): int {
            return strcasecmp($this->asString($a['keyword'] ?? ''), $this->asString($b['keyword'] ?? ''));
        });

        foreach ($pages as &$page) {
            if (isset($page['children']) && is_array($page['children']) && !empty($page['children'])) {
                /** @var list<array<string, mixed>> $children */
                $children = $page['children'];
                $this->sortPagesRecursively($children);
                $page['children'] = $children;
            }
        }
        unset($page);
    }

    /**
     * Get all published pages for the current user, filtered by mode and ACL
     *
     * @param string $mode Either 'web' or 'mobile'
     * @param int|null $language_id Optional language ID for translations
     * @return list<array<string, mixed>>
     */
    public function getAllAccessiblePagesForUser(string $mode, bool $admin, ?int $language_id = null): array
    {
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? (int) $user->getId() : UserContextService::GUEST_USER_ID;

        // Determine which language ID to use for translations
        $languageId = $this->determineLanguageId($language_id);

        // Try to get from cache first
        $cacheKey = "pages_{$mode}_{$admin}_{$languageId}";

        $pages = $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $languageId)
            ->getList($cacheKey, function () use ($mode, $admin, $languageId, $userId) {
                // Get all pages with ACL for the user using the ACLService (cached).
                // The `get_user_acl` stored procedure returns canonical snake_case
                // columns (id_parent_page, id_page_types, id_page_access_types)
                // — no key normalization is needed.
                /** @var list<array<string, mixed>> $allPages */
                $allPages = $this->aclService->getAllUserAcls($userId);

                // Determine which type to remove based on mode
                $removeType = $mode === LookupService::PAGE_ACCESS_TYPES_MOBILE ? LookupService::PAGE_ACCESS_TYPES_WEB : LookupService::PAGE_ACCESS_TYPES_MOBILE;
                $removeTypeId = $this->lookupService->getLookupIdByCode(LookupService::PAGE_ACCESS_TYPES, $removeType);

                // If mode is both, do not remove any type
                $filteredPages = array_values(array_filter($allPages, function (array $item) use ($removeTypeId, $mode, $admin) {

                    // Base ACL check
                    if ($item['acl_select'] != 1) {
                        return false;
                    }

                    // If admin is true, then all pages (normal filtering)
                    // If not admin, then only pages with id_page_types = 2 or 3
                    // (core and experiment pages)
                    if (!$admin && isset($item['id_page_types']) && !in_array($item['id_page_types'], [2, 3])) {
                        return false;
                    }

                    // Apply mode-based filtering
                    if ($mode === LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB) {
                        return true;
                    }

                    return ($item['id_page_access_types'] ?? null) != $removeTypeId;
                }));

                // Get default language ID for fallback translations
                $defaultLanguageId = null;
                try {
                    $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId();
                } catch (\Exception $e) {
                    // If there's an error getting the default language, continue without fallback
                }

                // Extract page IDs for fetching translations
                $pageIds = array_map(fn (mixed $id): int => $this->asInt($id), array_column($filteredPages, 'id_pages'));

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

                    // Attach translated SEO fields (title + description) from the
                    // pages_fields_translation store. Both fields are display=1
                    // (user-visible, translatable) and are needed by the frontend
                    // for <title> / <meta name="description"> generation without a
                    // second round-trip per page.
                    $pageId = $this->asInt($page['id_pages']);
                    $translations = $pageTitleTranslations[$pageId] ?? [];

                    $page['title'] = null;
                    if (isset($translations['title'])) {
                        $page['title'] = $translations['title'];
                    } elseif (!empty($translations)) {
                        // Fallback: first translated display=1 field (legacy behaviour)
                        $page['title'] = reset($translations) ?: null;
                    }

                    $page['description'] = isset($translations['description'])
                        ? $translations['description']
                        : null;

                    $page['children'] = []; // Initialize children array
                    $pagesMap[$pageId] = &$page;
                }
                unset($page); // Break the reference
    
                // Build the hierarchy
                $nestedPages = [];
                foreach ($pagesMap as $id => &$page) {
                    $parentId = isset($page['id_parent_page']) ? $this->asInt($page['id_parent_page']) : null;
                    if ($parentId !== null && isset($pagesMap[$parentId])) {
                        // This is a child page, add it to its parent's children array
                        $pagesMap[$parentId]['children'][] = &$page;
                    } else {
                        // This is a root level page
                        $nestedPages[] = &$page;
                    }
                }
                unset($page); // Break the reference
    
                // Children are ordered by page tree; menu order is owned by navigation_menu_items.
                $this->sortPagesRecursively($nestedPages);

                // Cache the result for this user
                return $nestedPages;
            });

        return $pages;
    }

    /**
     * Resolve a page by its unique keyword. This is the single public
     * page-content entry point (the legacy numeric-id route was removed —
     * every client resolves pages by keyword). Throws 404 if no page with
     * this keyword exists.
     *
     * This method supports hybrid versioning:
     * - If a published version exists and preview=false: serves published version with refreshed dynamic elements
     * - If no published version or preview=true: serves fresh draft from database
     *
     * `$mode` is the page-access type the caller is requesting (web, mobile,
     * mobile_and_web). If supplied, the page's own access type must be
     * compatible (`mobile_and_web` is universal). Mismatches throw 404 to
     * avoid leaking page metadata across platforms.
     *
     * @return array<string, mixed>
     */
    public function getPageByKeyword(string $keyword, ?int $language_id = null, bool $preview = false, ?string $mode = null): array
    {
        $languageId = $this->determineLanguageId($language_id);

        $page = $this->pageRepository->findOneBy(['keyword' => $keyword]);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        return $this->resolvePageResponse($page, (int) $page->getId(), $languageId, $preview, $mode);
    }

    /**
     * Resolve a page by its public URL path using the DB-driven `page_routes`
     * contract (issue #30). The resolver maps a path like `/reset/42/abc123` or
     * `/team/7` to a page keyword plus snake_case route params; those params are
     * threaded into the render so `{{route.user_id}}` / `{{route.record_id}}`
     * resolve in section content and `data_config` filters, and are included in
     * the page/section cache keys so different params never collide.
     *
     * Resolving a path to a page does NOT bypass any access rule: the resolved
     * page still applies full page ACL, published/draft + preview rules, the
     * platform/language checks, and data-access security (a 404 is returned for
     * unauthorized access to avoid leaking page existence). The open-access
     * `/pages/resolve` API route only governs reaching this resolver, not the
     * page it returns.
     *
     * @return array<string, mixed>
     */
    public function getPageByPublicPath(string $path, ?int $language_id = null, bool $preview = false, ?string $mode = null): array
    {
        $resolved = $this->pageRouteResolver->resolve($path);
        if ($resolved === null) {
            $this->throwNotFound('Page not found');
        }

        $languageId = $this->determineLanguageId($language_id);

        $page = $this->pageRepository->findOneBy(['keyword' => $resolved['keyword']]);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }

        $routeParams = $resolved['route_params'];

        try {
            $response = $this->resolvePageResponse(
                $page,
                (int) $page->getId(),
                $languageId,
                $preview,
                $mode,
                $routeParams
            );
        } catch (\App\Exception\ServiceException $e) {
            // Existence-leak guard (issue #30 locked decision): the public path
            // resolver must NEVER reveal that a path maps to a page the caller
            // cannot access. A page-ACL denial (403 Forbidden) is remapped to a
            // 404 so an unauthorized caller cannot distinguish "exists but
            // forbidden" from "does not exist" — critical for cms/surfaced
            // pages. The 401 preview-auth case is left intact (it is a generic
            // "authenticate to preview", not a page-specific existence signal).
            if ($e->getCode() === \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN) {
                $this->throwNotFound('Page not found');
            }
            throw $e;
        }

        // Attach route metadata so the frontend/mobile can read params and the
        // canonical URL without re-parsing the slug. route_params is omitted
        // when empty (static route) so the wire payload stays a clean object
        // map per the response schema.
        if (isset($response['page']) && is_array($response['page'])) {
            if ($routeParams !== []) {
                $response['page']['route_params'] = $routeParams;
            }
            $response['page']['matched_url_pattern'] = $resolved['matched_pattern'];
            $response['page']['canonical_url'] = $resolved['canonical_url'];
        }

        return $response;
    }

    /**
     * Shared resolution path used by getPageByKeyword().
     *
     * Page-access enforcement:
     * - Pages flagged `mobile_and_web` are universal and always pass.
     * - Pages flagged `web` only resolve for `web` callers.
     * - Pages flagged `mobile` only resolve for `mobile` callers.
     * - When `$mode` is null (legacy callers / internal callers), the
     *   check is skipped for back-compat.
     * - Pages with no `id_page_access_types` are treated as `web` (legacy
     *   default).
     *
     * Preview enforcement:
     * - `preview=true` serves the unpublished draft, so it requires an
     *   authenticated caller (anonymous => 401). The page ACL is still
     *   enforced on top, so a logged-in user can only preview pages they
     *   already have `select` access to.
     *
     * @param array<string, string> $routeParams Public route params ({{route.*}}); empty for keyword resolution.
     * @return array<string, mixed>
     */
    private function resolvePageResponse(\App\Entity\Page $page, int $page_id, int $languageId, bool $preview, ?string $mode = null, array $routeParams = []): array
    {
        if ($mode !== null) {
            $this->assertPageAccessForMode($page, $mode);
        }

        // Drafts are never served anonymously: preview requires authentication.
        if ($preview && $this->userContextAwareService->getCurrentUser() === null) {
            $this->throwUnauthorized('Authentication required to preview unpublished drafts');
        }

        // Check if user has access to the page
        $this->userContextAwareService->checkAclAccess((string) $page->getKeyword(), 'select');

        // If preview mode is disabled and a published version exists, serve it
        if (!$preview && $page->getPublishedVersionId()) {
            return $this->servePublishedVersion($page_id, $languageId, $routeParams);
        }

        // Otherwise serve the draft version (fresh from database)
        return $this->serveDraftVersion($page_id, $languageId, $page, $routeParams);
    }

    /**
     * Wrap public route params as a top-level `route` interpolation scope so
     * `{{route.<name>}}` resolves in section content and `data_config` filters.
     * Returns an empty array (no scope) when there are no params.
     *
     * @param array<string, string> $routeParams
     * @return array<array-key, mixed>
     */
    private function buildRouteScope(array $routeParams): array
    {
        return $routeParams === [] ? [] : ['route' => $routeParams];
    }

    /**
     * Deterministic cache-key suffix derived from the route params, so a
     * parameterized page (e.g. `/team/{record_id}`) caches one entry per param
     * set instead of colliding. Empty string when there are no params.
     *
     * @param array<string, string> $routeParams
     */
    private function routeCacheSuffix(array $routeParams): string
    {
        if ($routeParams === []) {
            return '';
        }
        ksort($routeParams);
        return '_route_' . substr(md5((string) json_encode($routeParams)), 0, 12);
    }

    /**
     * Throw a 404 (not 403, to avoid leaking page existence) if the page's
     * declared platform doesn't match the caller's requested mode.
     */
    private function assertPageAccessForMode(\App\Entity\Page $page, string $mode): void
    {
        $pageType = $page->getPageAccessType();
        $pageMode = $pageType ? strtolower((string) $pageType->getLookupCode()) : \App\Service\Core\LookupService::PAGE_ACCESS_TYPES_WEB;

        if ($pageMode === \App\Service\Core\LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB) {
            return;
        }

        $callerMode = strtolower($mode);
        if ($callerMode === \App\Service\Core\LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB) {
            // The caller explicitly asks for the universal lane — match.
            return;
        }

        if ($pageMode === $callerMode) {
            return;
        }

        $this->throwNotFound('Page not found');
    }

    /**
     * Serve published version with refreshed dynamic elements
     * 
     * Hybrid approach:
     * - Load stored JSON structure from page_versions table
     * - Re-run dynamic elements (data retrieval, condition evaluation)
     * - Apply fresh interpolation
     * 
     * @param int $page_id The page ID
     * @param int $languageId The language ID for translations
     * @param array<string, string> $routeParams Public route params ({{route.*}}).
     * @return array<string, mixed> The hydrated page data
     */
    private function servePublishedVersion(int $page_id, int $languageId, array $routeParams = []): array
    {
        // Get the published version from database
        $page = $this->pageRepository->find($page_id);
        if (!$page) {
            $this->throwNotFound('Page not found');
        }
        $versionId = $page->getPublishedVersionId();
        
        $publishedVersion = $this->pageVersionRepository->find($versionId);
        if (!$publishedVersion) {
            // Fallback to draft if published version not found
            return $this->serveDraftVersion($page_id, $languageId, $page);
        }

        // Get the stored page JSON
        $storedPageData = $publishedVersion->getPageJson();

        // Hydrate the published page with fresh dynamic elements
        $hydrated = $this->hydratePublishedPage($storedPageData, $languageId, $routeParams);

        // Inject translated SEO fields (title/description) — these live in
        // pages_fields_translation, not in the versioned JSON, so they must
        // be resolved fresh per request/language.
        if (isset($hydrated['page']) && is_array($hydrated['page'])) {
            $seo = $this->resolvePageSeoFields($page_id, $languageId);
            $hydrated['page']['title'] = $seo['title'];
            $hydrated['page']['description'] = $seo['description'];
            // open_in_modal lives in pages_fields_translation (a behaviour
            // property), not in the versioned JSON, so resolve it fresh. The
            // modal-size properties travel with it.
            $hydrated['page']['open_in_modal'] = $this->resolveOpenInModal($page_id);
            $modalSize = $this->resolveModalSize($page_id);
            $hydrated['page']['modal_width'] = $modalSize['modal_width'];
            $hydrated['page']['modal_height'] = $modalSize['modal_height'];
            $flatSections = $this->sectionRepository->fetchSectionsHierarchicalByPageId($page_id);
            $keyword = $page->getKeyword();
            if ($keyword !== null) {
                $fallback = $this->shouldFallback($flatSections, $keyword);
                if ($fallback !== null) {
                    $hydrated['page']['should_fallback'] = $fallback;
                }
            }
        }

        return $hydrated;
    }

    /**
     * Hydrate published page with fresh dynamic elements
     * 
     * This method:
     * 1. Extracts language-specific translations from stored multi-language data
     * 2. Re-runs data retrieval from data tables
     * 3. Re-evaluates conditions
     * 4. Applies variable interpolation
     * 
     * @param array<string, mixed> $storedPageData The stored page JSON structure with ALL languages
     * @param int $languageId The language ID to extract and serve
     * @param array<string, string> $routeParams Public route params ({{route.*}}).
     * @return array<string, mixed> The hydrated page data with single language
     */
    private function hydratePublishedPage(array $storedPageData, int $languageId, array $routeParams = []): array
    {
        // Extract sections from stored data
        if (!isset($storedPageData['page']) || !is_array($storedPageData['page']) || !isset($storedPageData['page']['sections']) || !is_array($storedPageData['page']['sections'])) {
            return $storedPageData;
        }

        /** @var array<int, array<string, mixed>> $sections */
        $sections = $storedPageData['page']['sections'];

        // Step 1: Extract language-specific translations from multi-language data
        $this->extractLanguageTranslations($sections, $languageId);

        // Step 2: Re-process sections with dynamic element refresh (data retrieval, conditions, interpolation)
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? (int) $user->getId() : null;

        $hydratedSections = $this->processSectionsRecursively($sections, $this->buildRouteScope($routeParams), $userId, $languageId);

        // Update the page data with hydrated sections
        $storedPageData['page']['sections'] = $hydratedSections;

        return $storedPageData;
    }

    /**
     * Extract language-specific translations from multi-language translation data
     * 
     * Converts from:
     * section['translations'][language_id][field_name] = {content, meta}
     * 
     * To:
     * section[field_name] = {content, meta}
     * 
     * IMPORTANT: This method ONLY adds/overrides translatable fields.
     * All other section fields (structure, config, etc.) are preserved.
     * 
     * @param array<int, array<string, mixed>> &$sections Sections array (passed by reference)
     * @param int $languageId Language ID to extract
     */
    private function extractLanguageTranslations(array &$sections, int $languageId): void
    {
        foreach ($sections as &$section) {
            // If section has translations data, extract the specific language
            if (isset($section['translations']) && is_array($section['translations'])) {
                if (isset($section['translations'][$languageId]) && is_array($section['translations'][$languageId])) {
                    // Apply the language-specific translations as direct fields
                    // This OVERRIDES existing field values but doesn't remove other fields
                    foreach ($section['translations'][$languageId] as $fieldName => $fieldData) {
                        $section[$fieldName] = $fieldData;
                    }
                }
                
                // Remove the translations array after extraction
                unset($section['translations']);
            }

            // Recursively process children
            if (isset($section['children']) && is_array($section['children'])) {
                /** @var array<int, array<string, mixed>> $children */
                $children = $section['children'];
                $this->extractLanguageTranslations($children, $languageId);
                $section['children'] = $children;
            }
        }
        unset($section);
    }

    /**
     * Serve draft version (fresh from database)
     * 
     * This is the existing getPage logic for serving current page state
     * 
     * @param int $page_id The page ID
     * @param int $languageId The language ID
     * @param \App\Entity\Page $page The page entity
     * @param array<string, string> $routeParams Public route params ({{route.*}}).
     * @return array<string, mixed> The page data
     */
    private function serveDraftVersion(int $page_id, int $languageId, \App\Entity\Page $page, array $routeParams = []): array
    {
        // Get current user for caching
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? (int) $user->getId() : UserContextService::GUEST_USER_ID; // anonymous guest sentinel

        // Try to get from cache first. The route-param suffix keeps one cache
        // entry per param set so e.g. /team/7 and /team/8 never collide.
        $cacheKey = "page_draft_{$page_id}_{$languageId}" . $this->routeCacheSuffix($routeParams);

        // Get flat sections to extract data table dependencies for page-level cache
        /** @var list<array<string, mixed>> $flatSections */
        $flatSections = $this->sectionRepository->fetchSectionsHierarchicalByPageId($page_id);
        $dataTableConfigs = $this->extractDataTableDependencies($flatSections, $page_id);

        // Build cache service with entity scopes including data table dependencies
        $cacheService = $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $languageId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, (int) $page->getId());

        // Add data table entity scopes for each data table this page depends on
        foreach ($dataTableConfigs as $dataTableId => $config) {
            // Always add data table scope for global configs (current_user: false)
            if (!empty($config['has_global_config'])) {
                $cacheService = $cacheService->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId);
            }

            // For user-specific configs (current_user: true), add user-data-table combined scope
            if (!empty($config['has_current_user_config'])) {
                $cacheService = $cacheService
                    ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId)
                    ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
            }
        }

        return $cacheService->getItem($cacheKey, function () use ($languageId, $page, $flatSections, $routeParams) {
            // Resolve the translated SEO fields (title + description) for this
            // single page. Keeps the payload self-contained so the frontend
            // doesn't have to cross-reference the nav list to render <title>
            // / <meta name="description">.
            $seo = $this->resolvePageSeoFields((int) $page->getId(), $languageId);

            $modalSize = $this->resolveModalSize((int) $page->getId());
            $pageData = [
                'page' => [
                    'id' => $page->getId(),
                    'keyword' => $page->getKeyword(),
                    'url' => $page->getUrl(),
                    'parent_page_id' => $page->getParentPage()?->getId(),
                    'is_headless' => $page->isHeadless(),
                    'open_in_modal' => $this->resolveOpenInModal((int) $page->getId()),
                    'modal_width' => $modalSize['modal_width'],
                    'modal_height' => $modalSize['modal_height'],
                    'title' => $seo['title'],
                    'description' => $seo['description'],
                    'sections' => $this->getPageSections((int) $page->getId(), $languageId, $routeParams)
                ]
            ];

            $keyword = $page->getKeyword();
            if ($keyword !== null) {
                $fallback = $this->shouldFallback($flatSections, $keyword);
                if ($fallback !== null) {
                    $pageData['page']['should_fallback'] = $fallback;
                }
            }

            return $pageData;
        });
    }

    /**
     * Resolve the `open_in_modal` page behaviour property (display=0, stored
     * under the property language in `pages_fields_translation`). Returns true
     * only when the authored value is the boolean-like string `1`/`true`.
     */
    private function resolveOpenInModal(int $pageId): bool
    {
        $properties = $this->pagesFieldsTranslationRepository->fetchPropertyFieldsForPages(
            [$pageId],
            [self::OPEN_IN_MODAL_FIELD]
        );

        $value = $properties[$pageId][self::OPEN_IN_MODAL_FIELD] ?? null;

        return $value === '1' || $value === 'true';
    }

    /**
     * Resolve the optional `modal_width` / `modal_height` page properties
     * (display=0, stored under the property language in
     * `pages_fields_translation`). Returns a `{modal_width, modal_height}` map
     * with the authored CSS length / `auto`, or `null` per field when unset so
     * the frontend applies its default (80%). Only meaningful together with
     * `open_in_modal`.
     *
     * @return array{modal_width: ?string, modal_height: ?string}
     */
    private function resolveModalSize(int $pageId): array
    {
        $properties = $this->pagesFieldsTranslationRepository->fetchPropertyFieldsForPages(
            [$pageId],
            self::MODAL_SIZE_FIELDS
        );

        $resolved = ['modal_width' => null, 'modal_height' => null];
        foreach (self::MODAL_SIZE_FIELDS as $field) {
            $value = $properties[$pageId][$field] ?? null;
            $resolved[$field] = ($value === null || $value === '') ? null : $this->asStringOrNull($value);
        }

        return $resolved;
    }

    /**
     * Resolve translated `title` + `description` (both display=1 page fields)
     * for a single page, falling back to the CMS default language if the
     * requested language has no translation. Used by the single-page endpoints
     * so the frontend can build <title> / <meta description> without a second
     * lookup against the nav list.
     *
     * @return array{title: ?string, description: ?string}
     */
    private function resolvePageSeoFields(int $pageId, int $languageId): array
    {
        $defaultLanguageId = null;
        try {
            $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId();
        } catch (\Exception $e) {
            // Fallback handled below.
        }

        $translations = $this->pagesFieldsTranslationRepository->fetchTitleTranslationsWithFallback(
            [$pageId],
            $languageId,
            $defaultLanguageId
        );

        $entry = $translations[$pageId] ?? [];
        return [
            'title'       => isset($entry['title']) ? $this->asStringOrNull($entry['title']) : null,
            'description' => isset($entry['description']) ? $this->asStringOrNull($entry['description']) : null,
        ];
    }

    /**
     * Extract data table dependencies from sections (with caching)
     *
     * @param list<array<string, mixed>> $flatSections Flat sections array from repository
     * @param int $pageId The page ID for caching key
     * @return array<int, array{has_current_user_config: bool, has_global_config: bool}> Associative array with data table IDs as keys and config info as values
     */
    /**
     * Returns true when the keyword is in the fallback whitelist AND no section
     * on the page has a style name matching the keyword.
     * Returns null when the keyword is not in the whitelist (field omitted from response).
     */
    /**
     * @param list<array<string, mixed>> $flatSections
     */
    private function shouldFallback(array $flatSections, string $keyword): ?bool
    {
        if (!isset(self::FALLBACK_CHECK_KEYWORDS[$keyword])) {
            return null;
        }
        $expectedStyle = self::FALLBACK_CHECK_KEYWORDS[$keyword];
        foreach ($flatSections as $row) {
            $styleName = $row['style_name'] ?? null;
            if (is_string($styleName) && $styleName === $expectedStyle) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<array<string, mixed>> $flatSections
     * @return array<int, array{has_current_user_config: bool, has_global_config: bool}>
     */
    private function extractDataTableDependencies(array $flatSections, int $pageId): array
    {
        $cacheKey = "page_data_table_deps_{$pageId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_SECTIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId)
            ->getList($cacheKey, function () use ($flatSections) {
                /** @var array<int, array{has_current_user_config: bool, has_global_config: bool}> $dataTableConfigs */
                $dataTableConfigs = [];

                foreach ($flatSections as $section) {
                    if (isset($section['data_config'])) {
                        // Parse data_config as JSON string to array
                        $dataConfigArray = is_string($section['data_config'])
                            ? json_decode($section['data_config'], true)
                            : $section['data_config'];

                        if (is_array($dataConfigArray)) {
                            // data_config is an array of configuration objects, process each one
                            foreach ($dataConfigArray as $config) {
                                if (is_array($config) && isset($config['table'])) {
                                    $tableName = $this->asString($config['table']);

                                    // Get data table by name to get its ID
                                    try {
                                        $dataTable = $this->dataService->getDataTableByName($tableName);
                                        if ($dataTable) {
                                            $dataTableId = (int) $dataTable->getId();
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

                // showUserInput sections reference their data table through the
                // `data_table` property field, NOT data_config. Without registering
                // it here the page render cache never carries the data-table entity
                // scope, so DataService's write-path invalidation
                // (invalidateEntityScope(DATA_TABLE, id) on submit/update/delete)
                // cannot bust the cached page and the rendered rows stay stale until
                // a manual cache clear.
                $showUserInputSectionIds = [];
                foreach ($flatSections as $section) {
                    if (($section['style_name'] ?? null) === StyleNames::STYLE_SHOW_USER_INPUT) {
                        $rawSectionId = $section['id'] ?? null;
                        $sectionId = is_numeric($rawSectionId) ? (int) $rawSectionId : 0;
                        if ($sectionId > 0) {
                            $showUserInputSectionIds[] = $sectionId;
                        }
                    }
                }

                if ($showUserInputSectionIds !== []) {
                    $showUserInputProperties = $this->translationRepository->fetchTranslationsForSections(
                        $showUserInputSectionIds,
                        self::PROPERTY_LANGUAGE_ID
                    );

                    foreach ($showUserInputProperties as $fields) {
                        $dataTableId = isset($fields['data_table']['content'])
                            ? (int) $this->asString($fields['data_table']['content'])
                            : 0;
                        if ($dataTableId <= 0) {
                            continue;
                        }

                        // own_entries_only defaults to true (mirrors applySectionData):
                        // own-records render is user-scoped, otherwise it is global.
                        $ownEntriesOnly = !isset($fields['own_entries_only']['content'])
                            || $this->asString($fields['own_entries_only']['content']) !== '0';

                        if (!isset($dataTableConfigs[$dataTableId])) {
                            $dataTableConfigs[$dataTableId] = [
                                'has_current_user_config' => false,
                                'has_global_config' => false,
                            ];
                        }

                        if ($ownEntriesOnly) {
                            $dataTableConfigs[$dataTableId]['has_current_user_config'] = true;
                        } else {
                            $dataTableConfigs[$dataTableId]['has_global_config'] = true;
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
     * @param array<string, string> $routeParams Public route params ({{route.*}}).
     * @return list<array<string, mixed>> The page sections in a hierarchical structure with translations
     */
    public function getPageSections(int $page_id, int $languageId, array $routeParams = []): array
    {
        // Get current user for caching
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? (int) $user->getId() : UserContextService::GUEST_USER_ID; // anonymous guest sentinel

        $cacheKey = "page_sections_{$page_id}_{$languageId}" . $this->routeCacheSuffix($routeParams);

        // Get flat sections first to extract data table dependencies
        /** @var list<array<string, mixed>> $flatSections */
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

        return $cacheService->getList($cacheKey, function () use ($flatSections, $languageId, $routeParams) {
            // Build nested hierarchical structure (without applying data initially)
            $sections = $this->sectionUtilityService->buildNestedSections($flatSections, false, $languageId);

            // Extract all section IDs from the hierarchical structure
            $sectionIds = $this->sectionUtilityService->extractSectionIds($sections);

            // Get default language ID for fallback translations
            $defaultLanguageId = null;
            try {
                $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId();
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
            // This replaces the bulk applySectionsData, interpolation, and condition filtering.
            // Seed the top-level parentData with the public route scope so
            // {{route.<name>}} resolves in data_config filters + content and
            // propagates to children.
            $user = $this->userContextAwareService->getCurrentUser();
            $userId = $user ? (int) $user->getId() : null;
            $sections = $this->processSectionsRecursively($sections, $this->buildRouteScope($routeParams), $userId, $languageId);

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
        $userLanguage = $user?->getLanguage();
        if ($userLanguage !== null) {
            return (int) $userLanguage->getId();
        }

        // Otherwise use default language from CMS preferences
        try {
            return (int) $this->cache
                ->withCategory(CacheService::CATEGORY_CMS_PREFERENCES)
                ->getItem("cms_preferences_default_language_id", fn() => $this->cmsPreferenceService->getDefaultLanguageId());
        } catch (\Exception $e) {
            // If there's an error getting the default language, use fallback
        }

        // Fallback to language ID 2 if no default language is configured
        return 2;
    }

    /**
     * Process sections recursively with proper data inheritance and sequential operations
     *
     * CRITICAL ORDER: This order must be maintained for correct functionality
     * 1. Interpolate data_config fields using parent data (for filters that reference parent data)
     * 2. Retrieve data from data_config (using interpolated filters)
     * 3. Interpolate ALL content fields using combined parent + own data
     * 4. Evaluate condition to determine if section should be included
     * 5. Process children recursively with inherited data
     *
     * @param array<int, array<string, mixed>> $sections The sections to process
     * @param array<array-key, mixed> $parentData Parent data to inherit (default empty array)
     * @param int|null $userId User ID for condition evaluation
     * @param int $languageId Language ID for data retrieval
     * @return list<array<string, mixed>> Processed sections that pass conditions
     */
    private function processSectionsRecursively(array $sections, array $parentData = [], ?int $userId = null, int $languageId = 1): array
    {
        $processedSections = [];

        foreach ($sections as $section) {
            // Step 1: CRITICAL - Interpolate data_config fields using parent data
            // This allows filters like "record_id = {{parent.record_id}}" to work
            $this->interpolateDataConfigInSection($section, $parentData);

            // Step 2: Apply section data (for form-record sections)
            $this->sectionUtilityService->applySectionData($section, $languageId);

            // Step 3: Retrieve data from data_config (now with properly interpolated filters)
            $this->retrieveSectionData($section, $parentData, $languageId);

            // Step 4: Merge parent data with newly retrieved data efficiently
            $sectionData = $this->mergeDataEfficiently($parentData, $this->asArray($section['retrieved_data'] ?? []));

            // Step 4b: Guarantee the public route scope ({{route.*}}) survives
            // data binding (issue #30). entry-list / entry-record / loop store
            // their rows under their own `data_config` scope, but a retrieved
            // scope accidentally named `route` must never shadow the URL-derived
            // route params that descendant sections (e.g. entry-record filtering
            // on {{route.record_id}}) depend on. Re-applying the inherited route
            // scope after the merge keeps loop/record context namespaced.
            if (isset($parentData['route'])) {
                $sectionData['route'] = $parentData['route'];
            }

            // Step 5: CRITICAL - Interpolate ALL content fields using combined data
            // Now we have both parent data and newly retrieved data available
            $this->applyOptimizedInterpolationPass($section, $sectionData);

            // Step 6: Evaluate condition using fully interpolated data.
            // $languageId is propagated so conditions that reference the `language`
            // system variable match the current render language instead of a
            // hard-coded default of 1 (seen as "English never unlocks for de_CH").
            $conditionResult = $this->evaluateSectionCondition($section, $userId, $languageId);

            // Step 7: Handle condition results with debug support
            if ($conditionResult['passes']) {
                // Condition passes: include section with children
                // Add condition debug info if available
                if (isset($conditionResult['debug'])) {
                    $section['condition_debug'] = $conditionResult['debug'];
                }

                // Step 8: Process children recursively with inherited data
                if (isset($section['children']) && is_array($section['children'])) {
                    /** @var array<int, array<string, mixed>> $children */
                    $children = $section['children'];
                    $section['children'] = $this->processSectionsRecursively($children, $sectionData, $userId, $languageId);
                }

                // Step 9: `retrieved_data` is internal scaffolding for the
                // interpolation pass that just ran on this section. Once we're
                // here every `{{var}}` in `content`/`meta`/`css`/etc has been
                // substituted, so the field has no FE consumer (verified by
                // ripgrep against `sh-selfhelp_frontend/src` — zero hits) and
                // payload bloat is real for list widgets that load hundreds of
                // rows. Keep it only for sections that explicitly opt into
                // debug mode so the admin inspector can still show the data
                // that drove the render.
                $this->cleanupInternalSectionScaffolding($section);

                $processedSections[] = $section;
            } elseif (isset($section['debug']) && $section['debug']) {
                // Condition failed but debug is enabled: include section without children
                // Add condition debug info for debugging purposes
                if (isset($conditionResult['debug'])) {
                    $section['condition_debug'] = $conditionResult['debug'];
                }

                // Remove children for failed conditions with debug enabled
                $section['children'] = [];

                // No cleanup here: this branch only fires when `debug=true`,
                // so we keep `retrieved_data` for the admin inspector.
                $processedSections[] = $section;
            }
            // Condition failed and debug not enabled: skip section entirely (do nothing)
        }

        return $processedSections;
    }

    /**
     * Drop backend-only fields from a section before it ships in the API
     * response. Currently this only removes `retrieved_data` from sections
     * whose `debug` flag is not `true` — see Step 9 above for context.
     *
     * @param array<string, mixed> $section Section reference (mutated in place).
     */
    private function cleanupInternalSectionScaffolding(array &$section): void
    {
        $debugEnabled = !empty($section['debug']);
        if (!$debugEnabled && array_key_exists('retrieved_data', $section)) {
            unset($section['retrieved_data']);
        }
    }

    /**
     * Efficiently merge parent and section data
     * Avoids unnecessary array_merge when one array is empty
     *
     * @param array<array-key, mixed> $parentData
     * @param array<array-key, mixed> $sectionData
     * @return array<array-key, mixed>
     */
    private function mergeDataEfficiently(array $parentData, array $sectionData): array
    {
        if (empty($parentData)) {
            return $sectionData;
        }
        if (empty($sectionData)) {
            return $parentData;
        }
        return array_merge($parentData, $sectionData);
    }

    /**
     * Coerce a value that is expected to be a JSON config object into a
     * string-keyed array. `data_config` entries are decoded from JSON objects,
     * so their keys are always strings at runtime; this normalises the static
     * type without changing behaviour for valid configs.
     *
     * @return array<string, mixed>
     */
    private function toConfigArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $config = [];
        foreach ($value as $key => $entry) {
            $config[(string) $key] = $entry;
        }

        return $config;
    }

    /**
     * Interpolate data_config fields in a section using parent data
     * This is critical for filters that reference parent data
     *
     * @param array<string, mixed> &$section The section to process
     * @param array<array-key, mixed> $parentData Parent data for interpolation
     */
    private function interpolateDataConfigInSection(array &$section, array $parentData): void
    {
        if (empty($parentData) || !isset($section['data_config'])) {
            return;
        }

        // Parse data_config if it's a string
        $dataConfig = is_string($section['data_config'])
            ? json_decode($section['data_config'], true)
            : $section['data_config'];

        if (!is_array($dataConfig)) {
            return;
        }

        // Interpolate each config entry
        foreach ($dataConfig as $configIndex => $configEntry) {
            if (is_array($configEntry)) {
                $dataConfig[$configIndex] = $this->interpolateDataConfig($this->toConfigArray($configEntry), $parentData);
            }
        }

        // Update the section's data_config
        $section['data_config'] = $dataConfig;
    }

    /**
     * Apply optimized interpolation pass for ALL fields using combined data
     * This happens after data retrieval so all parent + own data is available
     *
     * @param array<string, mixed> &$section The section to interpolate
     * @param array<array-key, mixed> $interpolationData The combined data to use for interpolation
     */
    private function applyOptimizedInterpolationPass(array &$section, array $interpolationData): void
    {
        if (empty($interpolationData)) {
            return;
        }

        // Check if debug is enabled for this section
        $isDebugEnabled = isset($section['debug']) && $section['debug'];

        // Update section's retrieved_data for interpolation access
        $section['retrieved_data'] = $interpolationData;

        // Interpolate direct string fields that exist
        $directStringFields = ['css', 'css_mobile'];
        if ($isDebugEnabled) {
            $directStringFields[] = 'condition';
        }

        foreach ($directStringFields as $field) {
            if (isset($section[$field]) && is_string($section[$field])) {
                $section[$field] = $this->interpolationService->interpolate($section[$field], $interpolationData);
            }
        }

        // Interpolate content and meta fields if they exist
        if (isset($section['content']) && is_array($section['content'])) {
            $section['content'] = $this->interpolationService->interpolateArray($section['content'], $interpolationData);
        }

        if (isset($section['meta']) && is_array($section['meta'])) {
            $section['meta'] = $this->interpolationService->interpolateArray($section['meta'], $interpolationData);
        }

        // Interpolate only fields that actually exist in this section
        $this->interpolateExistingContentFields($section, $interpolationData, $isDebugEnabled);
    }

    /**
     * Interpolate only content fields that actually exist in the section
     * Much more efficient than checking 100+ field names
     *
     * @param array<string, mixed> &$section The section to process
     * @param-out array<string, mixed> $section
     * @param array<array-key, mixed> $interpolationData Data for interpolation
     * @param bool $includeCondition Whether to include condition field
     */
    private function interpolateExistingContentFields(array &$section, array $interpolationData, bool $includeCondition = false): void
    {
        foreach ($section as $fieldName => $fieldValue) {
            // Skip non-content fields
            if (!is_array($fieldValue) || !isset($fieldValue['content'])) {
                continue;
            }

            // Special handling for condition field
            if ($fieldName === 'condition' && $includeCondition) {
                $fieldValue['content'] = $this->interpolationService->interpolateConditionWithDebug($section, $interpolationData);
                $section[$fieldName] = $fieldValue;
                continue;
            }

            // Interpolate content if it's a string
            if (is_string($fieldValue['content'])) {
                $fieldValue['content'] = $this->interpolationService->interpolate($fieldValue['content'], $interpolationData);
                $section[$fieldName] = $fieldValue;
            }
        }
    }

    /**
     * Retrieve data for a single section from its data_config
     *
     * @param array<string, mixed> &$section The section to retrieve data for
     * @param array<array-key, mixed> $availableData Available data for interpolation (parent data)
     * @param int $languageId The language ID for data retrieval
     */
    private function retrieveSectionData(array &$section, array $availableData, int $languageId): void
    {
        // Handle data_config field - parse and retrieve data
        if (isset($section['data_config'])) {
            // Parse data_config as JSON string to array
            $dataConfigArray = is_string($section['data_config'])
                ? json_decode($section['data_config'], true)
                : $section['data_config'];

            if (is_array($dataConfigArray)) {
                // data_config is an array of configuration objects, process each one
                $retrievedData = [];
                foreach ($dataConfigArray as $configIndex => $config) {
                    if (!is_array($config)) {
                        continue;
                    }
                    try {
                        // Interpolate the config before retrieving data
                        // availableData contains the structured parent data (system, globals, parent scopes)
                        $interpolatedConfig = $this->interpolateDataConfig($this->toConfigArray($config), $availableData);
                        $configData = $this->sectionUtilityService->retrieveData($interpolatedConfig, [], $languageId);
                        // Use the scope as key if available, otherwise use index
                        $key = isset($config['scope']) ? $this->asString($config['scope']) : $configIndex;
                        $retrievedData[$key] = $configData;
                    } catch (\Exception $e) {
                        // If there's an error retrieving data, continue without it
                        // This prevents failures due to invalid data configs
                    }
                }
                
                // Merge retrieved data scopes with existing retrieved_data (system, globals)
                // This adds data scopes (parent, test, etc.) to the structure
                if (isset($section['retrieved_data']) && is_array($section['retrieved_data'])) {
                    $section['retrieved_data'] = array_merge($section['retrieved_data'], $retrievedData);
                } else {
                    $section['retrieved_data'] = $retrievedData;
                }
                
                $section['data_config'] = $dataConfigArray;
            }
        }
    }

    /**
     * Interpolate variables in data config before data retrieval
     *
     * @param array<string, mixed> $config The data config to interpolate
     * @param array<array-key, mixed> $availableData Available data for interpolation
     * @return array<string, mixed> The interpolated config
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
     * @param array<string, mixed> $section The section to evaluate
     * @param int|null $userId User ID for condition evaluation
     * @param int $languageId Language of the current render; forwarded to ConditionService
     *                        so the `language` system variable matches the request language.
     * @return array{passes: bool, debug?: array<string, mixed>} Result with 'passes' boolean and optional 'debug' info
     */
    private function evaluateSectionCondition(array $section, ?int $userId, int $languageId = 1): array
    {
        if (!isset($section['condition']) || empty($section['condition'])) {
            return ['passes' => true];
        }

        $condition = $section['condition'];
        // Conditions are stored as JSON strings or already-decoded arrays. Any
        // other (non-empty) type is not a valid condition, so treat it as "no
        // condition" instead of passing it to ConditionService (which only
        // accepts array|string|null and would otherwise raise a TypeError).
        if (!is_string($condition) && !is_array($condition)) {
            return ['passes' => true];
        }

        /** @var array{result: bool, fields?: mixed, debug?: array<string, mixed>} $conditionResult */
        $conditionResult = $this->conditionService->evaluateCondition(
            $condition,
            $userId,
            $this->asString($section['keyword'] ?? 'unknown'),
            $languageId
        );

        return [
            'passes' => $conditionResult['result'],
            'debug' => $this->conditionService->buildConditionDebug($conditionResult, $condition),
        ];
    }

}
