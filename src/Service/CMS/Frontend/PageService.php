<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Frontend;

use App\Entity\Language;
use App\Repository\PageRepository;
use App\Repository\SectionRepository;
use App\Repository\SectionsFieldsTranslationRepository;
use App\Repository\PagesFieldsTranslationRepository;
use App\Exception\ServiceException;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Service\CMS\Common\DataTableFilterService;
use App\Service\CMS\Common\SectionUtilityService;
use App\Service\CMS\Common\StyleNames;
use App\Service\CMS\CmsPreferenceService;
use App\Service\CMS\Effective\EffectivePage;
use App\Service\CMS\Effective\EffectivePageResolver;
use App\Service\Core\BaseService;
use App\Service\Core\ConditionService;
use App\Service\Core\InterpolationService;
use App\Service\Core\ServerBuiltString;
use App\Service\Core\UserContextAwareService;
use App\Service\Auth\UserContextService;
use App\Service\Sync\ClockBoundaries;
use App\Service\Sync\PageTableScanner;
use App\Service\Sync\SyncTag;
use App\Service\System\PlatformAboutService;

class PageService extends BaseService
{
    /**
     * The scope names of the context of a section that a record or a data view's data_config can never
     * take over: the visitor and the site, the route and the position of the record in the page.
     */
    private const ROW_TEMPLATE_RESERVED_SCOPES = ['system', 'globals', 'route', 'route_requirements', '_index'];

    /**
     * Page behaviour property field (display=0) projected onto the single-page
     * content response so the web renderer can open the page inside a modal.
     * Seeded by Version20260710093044; consumed by `@selfhelp/shared`
     * `IPageContent.open_in_modal` and the frontend `DynamicPageClient`.
     */
    private const OPEN_IN_MODAL_FIELD = 'open_in_modal';

    /**
     * Modal-size page property fields (display=0) projected next to
     * `open_in_modal` on the single-page content response. Free-text CSS
     * lengths or `auto`; empty means "use the frontend default (80%)". Seeded by
     * Version20260710093044; consumed by `@selfhelp/shared`
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
        'register'                 => 'register',
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
        private readonly \App\Routing\PageRouteResolverService $pageRouteResolver,
        private readonly OptionLabelHydrator $optionLabelHydrator,
        private readonly DataTableFilterService $dataTableFilterService,
        private readonly \App\Repository\PageTypeRepository $pageTypeRepository,
        private readonly \App\Repository\AssetRepository $assetRepository,
        private readonly \App\Service\CMS\Admin\AssetStorageService $assetStorage,
        private readonly PlatformAboutService $platformAbout,
        private readonly EffectivePageResolver $effectivePages,
        private readonly \App\Service\CMS\FormFileUploadService $formFiles,
        private readonly \App\Service\CMS\DataQuery\DataQueryEngine $dataQueryEngine,
        private readonly \App\Service\CMS\FormFieldKeyResolver $formFieldKeys,
        private readonly ClockBoundaries $clockBoundaries,
        private readonly PageTableScanner $tableScanner,
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

                // Public navigation renders content pages only; system page types
                // (intern, maintenance, emails, global_css, …) never appear.
                // Resolved by name — see PageType::PUBLIC_NAVIGABLE_PAGE_TYPES.
                $publicNavigableTypeIds = $this->pageTypeRepository->findPublicNavigableIds();

                // If mode is both, do not remove any type
                $filteredPages = array_values(array_filter($allPages, function (array $item) use ($removeTypeId, $mode, $admin, $publicNavigableTypeIds) {

                    // Base ACL check
                    if ($item['acl_select'] != 1) {
                        return false;
                    }

                    // If admin is true, then all pages (normal filtering)
                    // If not admin, then only public-navigable (content) page types
                    if (!$admin && isset($item['id_page_types']) && is_numeric($item['id_page_types'])
                        && !in_array((int) $item['id_page_types'], $publicNavigableTypeIds, true)
                    ) {
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

                // Published pages show their published title and description
                // in menus, not unpublished edits.
                $publishedFields = $admin ? [] : $this->pageVersionRepository->fetchPublishedPageFields($pageIds);
                foreach ($publishedFields as $publishedPageId => $fields) {
                    $settings = $this->publishedPageSettings($fields, $languageId);
                    $pageTitleTranslations[$publishedPageId] = array_filter(
                        ['title' => $settings['title'], 'description' => $settings['description']],
                        static fn (?string $value): bool => $value !== null
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
        $routeRequirements = $resolved['route_requirements'];

        // Existence-leak guard (issue #30): a page-ACL denial is remapped to
        // 404 whether or not the caller asked for a preview, so an unseen
        // page cannot be told from a missing one by adding `preview=true`.
        // The preview gate runs afterwards inside resolvePageResponse and
        // keeps its own 401 (guest) / 403 (no edit rights) on a page the
        // caller can already see.
        try {
            if ($mode !== null) {
                $this->assertPageAccessForMode($page, $mode);
            }
            $this->assertPageReadable($page);
        } catch (\App\Exception\ServiceException $e) {
            if ($e->getCode() === \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN) {
                $this->throwNotFound('Page not found');
            }
            throw $e;
        }

        $response = $this->resolvePageResponse(
            $page,
            (int) $page->getId(),
            $languageId,
            $preview,
            $mode,
            $routeParams,
            $routeRequirements
        );

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
     * Whether the caller may read the page at all: the platform mode, the CMS
     * surface rule and the page ACL. The page endpoints and every endpoint that
     * serves a part of a page (the list endpoints) answer with the same errors,
     * so a section cannot be reached through a side door that the page itself
     * refuses.
     *
     * @param string|null $mode `web` or `mobile`; null skips the platform check
     * @param string $permission the ACL permission the caller needs on the page
     * @throws ServiceException
     */
    public function assertPageReadable(\App\Entity\Page $page, ?string $mode = null, string $permission = 'select'): void
    {
        if ($mode !== null) {
            $this->assertPageAccessForMode($page, $mode);
        }

        // CMS-surface pages are Host Admin only (CMS Apps content host / admin
        // preview). Public consumers — anonymous or authenticated without admin
        // data-access — never receive them via keyword or path resolve (404 so
        // existence is not leaked). Admin select on the page is the explicit
        // authorization gate; frontend ACL alone is not enough.
        if ($page->getPageSurfaceCode() === LookupService::PAGE_SURFACE_CMS) {
            // Anonymous callers cannot hold admin data-access; deny before the
            // permission service audits with the guest sentinel user id.
            if ($this->userContextAwareService->getCurrentUser() === null) {
                $this->throwNotFound('Page not found');
            }
            try {
                $this->userContextAwareService->checkAdminAccessById((int) $page->getId(), 'select');
            } catch (ServiceException $e) {
                if ($e->getCode() === \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED) {
                    throw $e;
                }
                $this->throwNotFound('Page not found');
            }
        }

        // Check if user has access to the page
        $this->userContextAwareService->checkAclAccess((string) $page->getKeyword(), $permission);
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
     * - Readability is decided first (same 404/403 as without `preview`).
     * - `preview=true` then serves the unpublished draft only to a caller who
     *   may edit the page (`admin.page.update` plus the update grant; admin
     *   passes). Guests get 401; everyone else without those rights gets 403.
     *   The same rule lives in {@see EffectivePageResolver::assertPreviewAllowed()}.
     *
     * @param array<string, string> $routeParams Public route params ({{route.*}}); empty for keyword resolution.
     * @param array<string, string> $routeRequirements Matched Symfony route requirements for the public path.
     * @return array<string, mixed>
     */
    private function resolvePageResponse(
        \App\Entity\Page $page,
        int $page_id,
        int $languageId,
        bool $preview,
        ?string $mode = null,
        array $routeParams = [],
        array $routeRequirements = []
    ): array {
        if ($mode !== null) {
            $this->assertPageAccessForMode($page, $mode);
        }

        // Page ACL / CMS surface first (rule 4.3): an unseen page answers the
        // same way with or without preview. Draft edit rights come after.
        $this->assertPageReadable($page);

        // Draft preview needs edit rights. One decision shared with every list /
        // form / history route behind the page.
        $this->effectivePages->assertPreviewAllowed($page, $preview);

        // If preview mode is disabled and a published version exists, serve it;
        // otherwise serve the draft version (fresh from database). The same decision
        // ({@see EffectivePageResolver::servesPublished()}) picks the configuration every endpoint
        // behind the page (lists, form writes, history) acts on, so they cannot disagree with the page.
        $servesPublished = $this->effectivePages->servesPublished($page, $preview);

        // Everything the page decides by the clock (a condition on the date or time, a clock token in a
        // text) reports its next change into this scope: it becomes `sync.valid_until`.
        $served = $this->clockBoundaries->collect(
            fn (): array => $servesPublished
                ? $this->servePublishedVersion($page_id, $languageId, $routeParams, $routeRequirements)
                : $this->serveDraftVersion($page_id, $languageId, $page, $routeParams, $routeRequirements)
        );

        // Both versions leave here, after every cache read: what the `version`
        // style shows depends on the caller, so it is added last.
        $pageData = $this->withPlatformAboutOnPage($served['value']);
        $pageData['sync'] = $this->syncBlock($page, $servesPublished, $served['valid_until']);

        return $pageData;
    }

    /**
     * The `sync` block of a page payload (live-sync contract C6): the change tags the page depends on
     * and the moment time alone changes the result.
     *
     * @return array{depends_on: list<string>, valid_until: string|null}
     */
    private function syncBlock(\App\Entity\Page $page, bool $servesPublished, ?int $validUntil): array
    {
        $pageId = (int) $page->getId();
        $effective = $servesPublished ? $this->effectivePages->forPage($page, false) : $this->effectivePages->draftOf($page);

        $dependsOn = [$servesPublished ? SyncTag::page($pageId) : SyncTag::pageDraft($pageId)];
        $tables = $this->tableScanner->scan($effective)['any'];
        sort($tables);
        foreach ($tables as $tableId) {
            $dependsOn[] = SyncTag::table($tableId);
        }
        $dependsOn[] = SyncTag::GLOBAL_VALUES;

        return [
            'depends_on' => $dependsOn,
            'valid_until' => $validUntil === null ? null : gmdate('Y-m-d\TH:i:s\Z', $validUntil),
        ];
    }

    /**
     * Cached value that never outlives the clock: the entry stores the moment time next changes its
     * result, and an entry past that moment is dropped and built again. The moment also reaches the
     * scope around the call, so a cache hit reports it just as a build does.
     *
     * @param callable(): mixed $compute
     */
    private function rememberUntilClockChanges(CacheService $cache, string $key, callable $compute): mixed
    {
        $value = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $envelope = $cache->getItem($key, function () use ($compute): array {
                $built = $this->clockBoundaries->collect($compute);

                return ['value' => $built['value'], 'valid_until' => $built['valid_until']];
            });

            $until = $envelope['valid_until'];
            if ($attempt === 0 && $until !== null && $until <= time()) {
                $cache->invalidateItem($key);
                continue;
            }

            $this->clockBoundaries->note($until);
            $value = $envelope['value'];
            break;
        }

        return $value;
    }

    /**
     * Add the `about` block to every `version` section of the page payload.
     *
     * It belongs to the caller, not to the page: guests and users without
     * `admin.system.read` get no versions, so it is built here, per request,
     * after the page cache, and never stored in the cached payload (anonymous
     * visitors share one cache entry). Pages without a `version` section cost
     * one walk of the section tree and nothing else.
     *
     * @param array<string, mixed> $pageData
     * @return array<string, mixed>
     */
    private function withPlatformAboutOnPage(array $pageData): array
    {
        $page = $pageData['page'] ?? null;
        if (!is_array($page) || !is_array($page['sections'] ?? null)) {
            return $pageData;
        }

        if (!$this->containsStyle($page['sections'], StyleNames::STYLE_VERSION)) {
            return $pageData;
        }

        $about = $this->platformAbout->aboutForCaller($this->userContextAwareService->getCurrentUser());
        $page['sections'] = $this->fillPlatformAbout($page['sections'], $about);
        $pageData['page'] = $page;

        return $pageData;
    }

    /**
     * @param array<array-key, mixed> $sections
     */
    private function containsStyle(array $sections, string $styleName): bool
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['style_name'] ?? null) === $styleName) {
                return true;
            }
            if (is_array($section['children'] ?? null) && $this->containsStyle($section['children'], $styleName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $sections
     * @param array<string, mixed> $about
     * @return list<array<string, mixed>>
     */
    private function fillPlatformAbout(array $sections, array $about): array
    {
        $filled = [];
        foreach ($sections as $section) {
            $section = $this->asAssocArray($section);
            if (($section['style_name'] ?? null) === StyleNames::STYLE_VERSION) {
                $section['about'] = $about;
            }
            if (is_array($section['children'] ?? null)) {
                $section['children'] = $this->fillPlatformAbout($section['children'], $about);
            }
            $filled[] = $section;
        }

        return $filled;
    }

    /**
     * Wrap public route params as a top-level `route` interpolation scope so
     * `{{route.<name>}}` resolves in section content and `data_config` filters.
     * Returns an empty array (no scope) when there are no params.
     *
     * @param array<string, string> $routeParams Public route params ({{route.*}}).
     * @param array<string, string> $routeRequirements Matched route placeholder requirements.
     * @return array<array-key, mixed>
     */
    private function buildRouteScope(array $routeParams, array $routeRequirements = []): array
    {
        $scope = [];
        if ($routeParams !== []) {
            $scope['route'] = $routeParams;
        }
        if ($routeRequirements !== []) {
            $scope['route_requirements'] = $routeRequirements;
        }

        return $scope;
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
     * @param array<string, string> $routeRequirements Matched route placeholder requirements.
     * @return array<string, mixed> The hydrated page data
     */
    private function servePublishedVersion(
        int $page_id,
        int $languageId,
        array $routeParams = [],
        array $routeRequirements = []
    ): array {
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
        $hydrated = $this->hydratePublishedPage($page_id, $storedPageData, $languageId, $routeParams, $routeRequirements);

        // Page settings (title, description, modal) come from the published
        // version, so editing them waits for Publish. A version that holds
        // none has no settings: nothing is read from the live page.
        if (isset($hydrated['page']) && is_array($hydrated['page'])) {
            $storedFields = is_array($storedPageData['page'] ?? null) ? ($storedPageData['page']['fields'] ?? null) : null;
            $settings = $this->publishedPageSettings(is_array($storedFields) ? $storedFields : [], $languageId);
            unset($hydrated['page']['fields']);
            $hydrated['page'] = array_merge($hydrated['page'], $settings);
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
     * @param array<string, string> $routeRequirements Matched route placeholder requirements.
     * @return array<string, mixed> The hydrated page data with single language
     */
    private function hydratePublishedPage(
        int $pageId,
        array $storedPageData,
        int $languageId,
        array $routeParams = [],
        array $routeRequirements = []
    ): array {
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

        $hydratedSections = $this->processSectionsRecursively(
            $sections,
            $this->buildRouteScope($routeParams, $routeRequirements),
            $userId,
            $languageId,
            false,
            $pageId,
        );

        // Resolved at serve time (not frozen into the version JSON) so an
        // asset's alt edit reaches published pages without a republish.
        $hydratedSections = $this->withAssetDefaultAlts($hydratedSections);

        // Update the page data with hydrated sections
        $storedPageData['page']['sections'] = $hydratedSections;

        return $storedPageData;
    }

    /**
     * The stored section tree of a published page translated for one language, exactly as the page
     * endpoint prepares it before it processes the sections: no condition is evaluated and no value is
     * interpolated yet. The layout of one record of a data view is read from here, so a published page
     * never takes its template from the live sections.
     *
     * @return list<array<string, mixed>>
     */
    public function publishedSections(EffectivePage $effective, int $languageId): array
    {
        /** @var array<int, array<string, mixed>> $sections */
        $sections = $effective->publishedNodes();
        $this->extractLanguageTranslations($sections, $languageId);

        return array_values($sections);
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
     * @param array<string, string> $routeRequirements Matched route placeholder requirements.
     * @return array<string, mixed> The page data
     */
    private function serveDraftVersion(
        int $page_id,
        int $languageId,
        \App\Entity\Page $page,
        array $routeParams = [],
        array $routeRequirements = []
    ): array {
        // Get current user for caching
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? (int) $user->getId() : UserContextService::GUEST_USER_ID; // anonymous guest sentinel

        // Try to get from cache first. The route-param suffix keeps one cache
        // entry per param set so e.g. /team/7 and /team/8 never collide.
        $cacheKey = "page_draft_v2_{$page_id}_{$languageId}" . $this->routeCacheSuffix($routeParams);

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

        $pageData = $this->rememberUntilClockChanges($cacheService, $cacheKey, function () use ($languageId, $page, $flatSections, $routeParams, $routeRequirements) {
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
                    'page_surface' => $page->getPageSurfaceCode(),
                    'open_in_modal' => $this->resolveOpenInModal((int) $page->getId()),
                    'modal_width' => $modalSize['modal_width'],
                    'modal_height' => $modalSize['modal_height'],
                    'title' => $seo['title'],
                    'description' => $seo['description'],
                    'sections' => $this->getPageSections((int) $page->getId(), $languageId, $routeParams, $routeRequirements)
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

        // The asset's default alt belongs to the asset, not to the page: it is
        // read when the page is served and never stored in the cached payload
        // (the published path does the same in hydratePublishedPage). An alt
        // edit then shows at once without flushing any cached page.
        return $this->withAssetDefaultAltsOnPage($this->asAssocArray($pageData));
    }

    /**
     * {@see withAssetDefaultAlts()} for the sections of a whole page payload.
     *
     * @param array<string, mixed> $pageData
     * @return array<string, mixed>
     */
    private function withAssetDefaultAltsOnPage(array $pageData): array
    {
        $page = $pageData['page'] ?? null;
        if (!is_array($page) || !is_array($page['sections'] ?? null)) {
            return $pageData;
        }

        $page['sections'] = $this->withAssetDefaultAlts(array_values(array_map(
            fn (mixed $section): array => $this->asAssocArray($section),
            $page['sections']
        )));
        $pageData['page'] = $page;

        return $pageData;
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
     * Page settings as published: read from the version's `page.fields`
     * (name => [language id => content]) with the same rules as the live
     * readers. Title and description take the requested language, else the
     * CMS default language, ignoring empty rich-text wrappers; properties take
     * the first non-empty value by language id.
     *
     * @param array<array-key, mixed> $fields
     * @return array{title: ?string, description: ?string, open_in_modal: bool, modal_width: ?string, modal_height: ?string}
     */
    private function publishedPageSettings(array $fields, int $languageId): array
    {
        $defaultLanguageId = null;
        try {
            $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId();
        } catch (\Exception $e) {
            // Without a default language only the requested one is used.
        }

        $translated = function (string $name) use ($fields, $languageId, $defaultLanguageId): ?string {
            $byLanguage = is_array($fields[$name] ?? null) ? $fields[$name] : [];
            foreach ([$languageId, $defaultLanguageId] as $candidate) {
                $content = $candidate !== null ? ($byLanguage[$candidate] ?? null) : null;
                if (is_string($content) && !\App\Util\TranslationContentHelper::isEffectivelyEmpty($content)) {
                    return $content;
                }
            }
            return null;
        };
        $property = function (string $name) use ($fields): ?string {
            $byLanguage = is_array($fields[$name] ?? null) ? $fields[$name] : [];
            ksort($byLanguage);
            foreach ($byLanguage as $content) {
                if (is_string($content) && $content !== '') {
                    return $content;
                }
            }
            return null;
        };

        $openInModal = $property(self::OPEN_IN_MODAL_FIELD);

        return [
            'title' => $translated('title'),
            'description' => $translated('description'),
            'open_in_modal' => $openInModal === '1' || $openInModal === 'true',
            'modal_width' => $property('modal_width'),
            'modal_height' => $property('modal_height'),
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

                // entry-table sections reference their data table through the
                // `data_table` property field, NOT data_config. Without registering
                // it here the page render cache never carries the data-table entity
                // scope, so DataService's write-path invalidation
                // (invalidateEntityScope(DATA_TABLE, id) on submit/update/delete)
                // cannot bust the cached page and the rendered rows stay stale until
                // a manual cache clear.
                //
                // A record form (form-record, entry-record-form) prefills from its own data
                // table, and the prefill carries the record's version: a save names that version
                // and is refused when it is old. So a cached prefill has to go with the record it
                // shows: the table that holds the record is registered for these too. The table of
                // a form-record, and of an entry-record-form that names none, is the one named
                // after the section.
                $entryHolderSectionIds = [];
                $ownTableSectionIds = [];
                foreach ($flatSections as $section) {
                    $style = $section['style_name'] ?? null;
                    $rawSectionId = $section['id'] ?? null;
                    $sectionId = is_numeric($rawSectionId) ? (int) $rawSectionId : 0;
                    if ($sectionId <= 0) {
                        continue;
                    }
                    if ($style === StyleNames::STYLE_ENTRY_TABLE
                        || $style === StyleNames::STYLE_ENTRY_LIST
                        || $style === StyleNames::STYLE_ENTRY_RECORD
                        || $style === StyleNames::STYLE_ENTRY_RECORD_FORM) {
                        $entryHolderSectionIds[] = $sectionId;
                    }
                    if ($style === StyleNames::STYLE_FORM_RECORD || $style === StyleNames::STYLE_ENTRY_RECORD_FORM) {
                        $ownTableSectionIds[$sectionId] = $style;
                    }
                }

                $entryTableProperties = $this->translationRepository->fetchTranslationsForSections(
                    $entryHolderSectionIds,
                    Language::INDEPENDENT_ID
                );

                foreach ($entryTableProperties as $holderId => $fields) {
                    $dataTableId = isset($fields['data_table']['content'])
                        ? (int) $this->asString($fields['data_table']['content'])
                        : 0;
                    if ($dataTableId <= 0) {
                        continue;
                    }
                    // A form that names its table is registered by it, not by the section's own name.
                    unset($ownTableSectionIds[(int) $holderId]);

                    // own_entries_only defaults to true (mirrors applySectionData):
                    // own-records render is user-scoped, otherwise it is global.
                    $ownEntriesOnly = !isset($fields['own_entries_only']['content'])
                        || $this->asString($fields['own_entries_only']['content']) !== '0';

                    $this->registerDataTableDependency($dataTableConfigs, $dataTableId, $ownEntriesOnly);
                }

                foreach ($ownTableSectionIds as $formSectionId => $style) {
                    // A plain form-record always prefills the person's own record; an
                    // entry-record-form that names no table does the same on its own table.
                    $ownTableFields = $entryTableProperties[$formSectionId] ?? [];
                    $ownEntriesOnly = $style === StyleNames::STYLE_FORM_RECORD
                        || !isset($ownTableFields['own_entries_only']['content'])
                        || $this->asString($ownTableFields['own_entries_only']['content']) !== '0';
                    try {
                        $ownTable = $this->dataService->getDataTableByName((string) $formSectionId);
                    } catch (\Exception) {
                        // A table that cannot be read cannot be a dependency; the page still renders.
                        $ownTable = null;
                    }
                    if ($ownTable !== null) {
                        $this->registerDataTableDependency($dataTableConfigs, (int) $ownTable->getId(), $ownEntriesOnly);
                    }
                }

                return $dataTableConfigs;
            });
    }

    /**
     * Note that a page depends on a data table: a user's own records scope the cached page to the
     * person, records everyone sees scope it to the table alone.
     *
     * @param array<int, array{has_current_user_config: bool, has_global_config: bool}> $dataTableConfigs
     */
    private function registerDataTableDependency(array &$dataTableConfigs, int $dataTableId, bool $ownEntriesOnly): void
    {
        $dataTableConfigs[$dataTableId] ??= ['has_current_user_config' => false, 'has_global_config' => false];
        if ($ownEntriesOnly) {
            $dataTableConfigs[$dataTableId]['has_current_user_config'] = true;
        } else {
            $dataTableConfigs[$dataTableId]['has_global_config'] = true;
        }
    }

    /**
     * Get page sections with translations
     *
     * The sections are cached, so they hold nothing that belongs to another
     * entity: the default alt of an asset is NOT filled in here but when the
     * page is served ({@see withAssetDefaultAltsOnPage()}).
     * 
     * @param int $page_id The page ID
     * @param int $languageId The language ID for translations
     * @param array<string, string> $routeParams Public route params ({{route.*}}).
     * @param array<string, string> $routeRequirements Matched route placeholder requirements.
     * @return list<array<string, mixed>> The page sections in a hierarchical structure with translations
     */
    public function getPageSections(
        int $page_id,
        int $languageId,
        array $routeParams = [],
        array $routeRequirements = []
    ): array {
        // Get current user for caching
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? (int) $user->getId() : UserContextService::GUEST_USER_ID; // anonymous guest sentinel

        $cacheKey = "page_sections_v2_{$page_id}_{$languageId}" . $this->routeCacheSuffix($routeParams);

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

        $sections = $this->rememberUntilClockChanges($cacheService, $cacheKey, function () use ($flatSections, $languageId, $routeParams, $routeRequirements, $page_id) {
            $sections = $this->translatedDraftSections($flatSections, $languageId);

            // Process sections recursively with proper data inheritance and sequential operations
            // This replaces the bulk applySectionsData, interpolation, and condition filtering.
            // Seed the top-level parentData with the public route scope so
            // {{route.<name>}} resolves in data_config filters + content and
            // propagates to children.
            $user = $this->userContextAwareService->getCurrentUser();
            $userId = $user ? (int) $user->getId() : null;
            $sections = $this->processSectionsRecursively(
                $sections,
                $this->buildRouteScope($routeParams, $routeRequirements),
                $userId,
                $languageId,
                false,
                $page_id,
            );

            return $sections;
        });

        /** @var list<array<string, mixed>> $sections */
        $sections = is_array($sections) ? array_values($sections) : [];

        return $sections;
    }

    /**
     * The live (draft) section tree of a page, nested and translated for one language, before any data is
     * retrieved, value interpolated or condition evaluated.
     *
     * @param list<array<string, mixed>> $flatSections rows from {@see SectionRepository::fetchSectionsHierarchicalByPageId()}
     * @return array<int, array<string, mixed>>
     */
    private function translatedDraftSections(array $flatSections, int $languageId): array
    {
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
            Language::INDEPENDENT_ID
        );

        // Apply translations to the sections recursively
        // Note: fallback is now handled internally by fetchTranslationsForSectionsWithFallback
        $this->sectionUtilityService->applySectionTranslations($sections, $translations, [], $propertyTranslations);

        return $sections;
    }

    /**
     * The variables one section of a page sees when the page is drawn for the current user, in one
     * language: user and system values, global values, route parameters, and the data that the
     * section's and its ancestors' `data_config` load. This is what `{{name}}` in that section's
     * content resolves to. A plugin uses it to replace variables in content it serves outside the
     * page response (H1, e.g. the questions of a survey).
     *
     * Built exactly like the page: only the section's ancestors and the section itself are
     * processed, in page order, each with its own data configuration. Returns an empty array when
     * the section is not on the page or an ancestor is hidden by its condition; whether the caller
     * may see the section at all is checked before, by the caller.
     *
     * Known limit: the call names the section, not a row. Under a repeating ancestor (an entry
     * list or a loop) every copy gets the first row's variables, and under a data view none.
     * Telling the copies apart needs the row in the plugin's call.
     *
     * @param array<string, string> $routeParams public route params ({{route.*}})
     * @return array<array-key, mixed>
     */
    public function variablesForSection(EffectivePage $effective, int $sectionId, int $languageId, array $routeParams = []): array
    {
        if (!$effective->contains($sectionId)) {
            return [];
        }
        $sections = $effective->isPublished()
            ? $this->publishedSections($effective, $languageId)
            : array_values($this->translatedDraftSections(
                $this->listOfStringKeyedRows($this->sectionRepository->fetchSectionsHierarchicalByPageId($effective->pageId)),
                $languageId
            ));

        $chain = $this->sectionChain($sections, $sectionId);
        if ($chain === null) {
            return [];
        }
        // Debug keeps `retrieved_data` (the section's variables) on the processed section.
        $target = array_pop($chain);
        $node = $target;
        $node['children'] = [];
        $node['debug'] = true;
        while (($parent = array_pop($chain)) !== null) {
            $parent['children'] = [$node];
            $node = $parent;
        }

        $user = $this->userContextAwareService->getCurrentUser();
        $processed = $this->processSectionsRecursively(
            [$node],
            $this->buildRouteScope($routeParams, []),
            $user ? (int) $user->getId() : null,
            $languageId,
            false,
            $effective->pageId,
        );

        $current = $processed[0] ?? null;
        while (is_array($current)) {
            if ($this->asInt($current['id'] ?? null) === $sectionId) {
                return $this->asArray($current['retrieved_data'] ?? []);
            }
            $children = $this->asArray($current['children'] ?? []);
            $current = $children[0] ?? null;
        }

        return [];
    }

    /**
     * The path from a top-level section to the section with the given id, both included; null when it is
     * not in the tree.
     *
     * @param array<array-key, mixed> $sections
     * @return list<array<string, mixed>>|null
     */
    private function sectionChain(array $sections, int $sectionId): ?array
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $row = $this->stringKeyed($section);
            if ($this->asInt($row['id'] ?? null) === $sectionId) {
                return [$row];
            }
            $below = $this->sectionChain($this->asArray($row['children'] ?? []), $sectionId);
            if ($below !== null) {
                return [$row, ...$below];
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function listOfStringKeyedRows(array $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $list[] = $this->stringKeyed($row);
            }
        }

        return $list;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function stringKeyed(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Fill an empty section `alt` from the default alt of the asset in its
     * `img_src`. A section's own alt always wins; the asset alt is only the
     * fallback, so an image is described once at upload and reused everywhere.
     * One batched lookup for the whole page.
     *
     * @param list<array<string, mixed>> $sections
     * @return list<array<string, mixed>>
     */
    private function withAssetDefaultAlts(array $sections): array
    {
        $keys = [];
        $this->collectAltlessImageKeys($sections, $keys);
        if ($keys === []) {
            return $sections;
        }

        $alts = [];
        foreach ($this->assetRepository->findByUuids(array_values($keys)) as $key => $asset) {
            $alt = $asset->getAlt();
            if ($alt !== null && $alt !== '') {
                $alts[$key] = $alt;
            }
        }

        return $alts === [] ? $sections : $this->fillAltlessImages($sections, $alts);
    }

    /**
     * @param array<array-key, mixed> $sections
     * @param array<string, string> $keys collected asset lookup keys
     */
    private function collectAltlessImageKeys(array $sections, array &$keys): void
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $key = $this->altlessImageKey($section);
            if ($key !== null) {
                $keys[$key] = $key;
            }
            if (is_array($section['children'] ?? null)) {
                $this->collectAltlessImageKeys($section['children'], $keys);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @param array<string, string> $alts asset lookup key => default alt
     * @return list<array<string, mixed>>
     */
    private function fillAltlessImages(array $sections, array $alts): array
    {
        $filled = [];
        foreach ($sections as $section) {
            $key = $this->altlessImageKey($section);
            if ($key !== null && isset($alts[$key])) {
                $meta = is_array($section['alt'] ?? null) ? ($section['alt']['meta'] ?? null) : null;
                $section['alt'] = ['content' => $alts[$key], 'meta' => $meta];
            }
            if (is_array($section['children'] ?? null)) {
                $section['children'] = $this->fillAltlessImages(
                    array_values(array_map(fn (mixed $child): array => $this->asAssocArray($child), $section['children'])),
                    $alts
                );
            }
            $filled[] = $section;
        }

        return $filled;
    }

    /**
     * The asset lookup key of a section's `img_src` when its alt is empty, or
     * null when there is nothing to fill.
     *
     * @param array<array-key, mixed> $section
     */
    private function altlessImageKey(array $section): ?string
    {
        $src = is_array($section['img_src'] ?? null) ? ($section['img_src']['content'] ?? null) : null;
        if (!is_string($src) || $src === '') {
            return null;
        }

        $alt = is_array($section['alt'] ?? null) ? ($section['alt']['content'] ?? null) : null;
        if (is_string($alt) && trim($alt) !== '') {
            return null;
        }

        return $this->assetStorage->uuidFromReference($src);
    }

    /**
     * Real language for translations: explicit, else the user's, else the CMS
     * default, else the lowest real language id. Never the language-independent row.
     */
    public function determineLanguageId(?int $language_id = null): int
    {
        if ($language_id !== null && $language_id !== Language::INDEPENDENT_ID) {
            return $language_id;
        }

        $user = $this->userContextAwareService->getCurrentUser();
        $userLanguage = $user?->getLanguage();
        if ($userLanguage !== null) {
            $id = (int) $userLanguage->getId();
            if ($id !== Language::INDEPENDENT_ID) {
                return $id;
            }
        }

        return $this->cmsPreferenceService->getEffectiveDefaultLanguageId();
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
    private function processSectionsRecursively(array $sections, array $parentData = [], ?int $userId = null, int $languageId = Language::INDEPENDENT_ID, bool $rowTemplate = false, int $pageId = 0): array
    {
        $processedSections = [];

        foreach ($sections as $section) {
            // Step 1: CRITICAL - Interpolate data_config fields using parent data
            // This allows filters like "record_id = {{parent.record_id}}" to work
            $this->interpolateDataConfigInSection($section, $parentData, $rowTemplate);

            // Step 2: Apply section data (for form-record sections). Route
            // params flow in so the form-record edit mode (`load_record_from`)
            // can prefill the URL-addressed record.
            $routeParams = [];
            if (is_array($parentData['route'] ?? null)) {
                foreach ($parentData['route'] as $routeKey => $routeValue) {
                    $routeParams[(string) $routeKey] = $routeValue;
                }
            }
            $this->sectionUtilityService->applySectionData($section, $languageId, $routeParams);

            // Step 3: Retrieve data from data_config (now with properly interpolated filters)
            $this->retrieveSectionData($section, $parentData, $languageId, $rowTemplate);

            // Step 4: Merge parent data with newly retrieved data efficiently
            $sectionData = $this->mergeDataEfficiently($parentData, $this->asArray($section['retrieved_data'] ?? []));

            // Step 4b: Guarantee the public route scope ({{route.*}}) survives
            // data binding (issue #30). `loop` may still bind rows through
            // `data_config`, but entry-list / entry-record load rows from style
            // property fields. A retrieved scope accidentally named `route` must
            // never shadow the URL-derived route params that descendant sections
            // (e.g. entry-list filters with `{{route.*}}`, or entry-record
            // `load_record_from`) depend on.
            if (isset($parentData['route'])) {
                $sectionData['route'] = $parentData['route'];
            }

            // Step 4c: Entry row hydration (issue #30). entry-list / entry-record
            // load their rows ONLY from style property fields (`data_table`,
            // `own_entries_only`, `filter` on lists, `load_record_from` on
            // entry-record, …) — never from `data_config.table`.
            // `data_config` on the same section may still run above (Step 3) to
            // populate helper scopes in `$sectionData` (e.g. `filters`) that an
            // entry-list author `filter` may reference via `{{filters.*}}`.
            $styleName = isset($section['style_name']) ? $this->asString($section['style_name']) : '';
            $isRepeater = $styleName === StyleNames::STYLE_ENTRY_LIST || $styleName === StyleNames::STYLE_LOOP;
            $entryRows = [];
            if ($isRepeater || $styleName === StyleNames::STYLE_ENTRY_RECORD) {
                $entryRows = $this->resolveEntryRows($section, $sectionData, $languageId, $pageId);
                if ($entryRows === [] && $styleName === StyleNames::STYLE_LOOP) {
                    $entryRows = $this->resolveLoopFieldRows($section, $sectionData);
                }
                if (!$isRepeater && isset($entryRows[0])) {
                    $sectionData = array_merge($sectionData, $entryRows[0]);
                    if (isset($parentData['route'])) {
                        $sectionData['route'] = $parentData['route'];
                    }
                }
            }

            // entry-record-delete needs the bound row's record id as a real
            // FIELD (both renderers read `record_id` off the section to call
            // the delete API), not just as an interpolation token.
            if ($styleName === StyleNames::STYLE_ENTRY_RECORD_DELETE && !isset($section['record_id'])) {
                $recordId = $sectionData['record_id'] ?? null;
                if (is_int($recordId) || is_float($recordId) || (is_string($recordId) && $recordId !== '')) {
                    $section['record_id'] = ['content' => (string) $recordId, 'meta' => null];
                }
            }
            // A delete is refused without the version of the record it is based on (409 when it is old),
            // so the page also offers the version the bound record has right now, read live next to its
            // id. A record that no longer exists offers none and the renderers keep the button off.
            if ($styleName === StyleNames::STYLE_ENTRY_RECORD_DELETE) {
                $boundId = is_array($section['record_id'] ?? null) ? ($section['record_id']['content'] ?? null) : null;
                if (is_string($boundId) && ctype_digit($boundId) && (int) $boundId > 0) {
                    try {
                        $section['record_version'] = ['content' => (string) $this->dataService->getRecordVersion((int) $boundId), 'meta' => null];
                    } catch (ServiceException) {
                        unset($section['record_version']);
                    }
                }
            }

            // Step 5: CRITICAL - Interpolate ALL content fields using combined data
            // Now we have both parent data and newly retrieved data available
            // (The condition of a section in a row template is not interpolated as text, not even in debug mode:
            // its tokens are resolved in the decoded condition at Step 6.)
            $this->applyOptimizedInterpolationPass($section, $sectionData, !$rowTemplate);

            // Step 6: Evaluate condition using fully interpolated data.
            // $languageId is propagated so conditions that reference the `language`
            // system variable match the current render language instead of a
            // hard-coded default of 1 (seen as "English never unlocks for de_CH").
            // Inside the layout of one record of a data view the condition may also read the record and the
            // route: its `{{tokens}}` are resolved from the data of the section (see evaluateSectionCondition).
            $conditionResult = $this->evaluateSectionCondition($section, $userId, $languageId, $rowTemplate ? $sectionData : null);

            // Step 7: Handle condition results with debug support
            if ($conditionResult['passes']) {
                // Condition passes: include section with children
                // Add condition debug info if available
                if (isset($conditionResult['debug'])) {
                    $section['condition_debug'] = $conditionResult['debug'];
                }

                // Step 8: Process children recursively with inherited data.
                // entry-list / loop are data-bound repeaters: their children
                // are a TEMPLATE cloned once per bound row (the web/mobile
                // renderers just render the already-cloned children). No rows
                // -> no children.
                // A data view is a list whose records are served page by page
                // by its own endpoint: its children are the TEMPLATE of one
                // record and stay as stored here; they are rendered once per
                // record of the list state (renderRowTemplate) and never ship
                // with the page itself.
                if (isset($section['children']) && is_array($section['children'])) {
                    /** @var array<int, array<string, mixed>> $children */
                    $children = $section['children'];
                    if ($styleName === StyleNames::STYLE_DATA_VIEW) {
                        $section['children'] = $children;
                    } elseif ($isRepeater) {
                        $section['children'] = $this->hydrateEntryListChildren($children, $entryRows, $sectionData, $userId, $languageId, $rowTemplate, $pageId);
                    } else {
                        $section['children'] = $this->processSectionsRecursively($children, $sectionData, $userId, $languageId, $rowTemplate, $pageId);
                    }
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
     * Resolve bound rows for entry-list / entry-record from style property fields
     * (`data_table`, `own_entries_only`, `filter`, `scope`).
     *
     * Row table and retrieve mode come **only** from those fields — never from
     * `data_config`. `$interpolationContext` carries parent/route data plus any
     * helper scopes already retrieved from `data_config` on this section.
     *
     * @param array<string, mixed> $section
     * @param array<array-key, mixed> $interpolationContext Parent + retrieved helper scopes
     * @return list<array<string, mixed>>
     */
    private function resolveEntryRows(array $section, array $interpolationContext, int $languageId, int $pageId = 0): array
    {
        $styleName = $this->asString($section['style_name'] ?? '');
        $dataTableId = (int) $this->getSectionPropertyContent($section, 'data_table');
        if ($dataTableId <= 0) {
            return [];
        }

        $dataTable = $this->dataService->getDataTableById($dataTableId);
        if ($dataTable === null) {
            return [];
        }

        $ownEntriesOnly = $this->getSectionPropertyBool($section, 'own_entries_only', true);
        $routeRecordId = 0;

        // entry-record: same visible contract as entry-record-form —
        // `load_record_from` names the route param; the server builds the
        // record_id predicate (no author SQL filter). A typed `fixed_filter`
        // (F166) may still refuse the row after load. A section with no value,
        // or a blank one, already carries the style default (`record_id`) here
        // (SectionUtilityService fills it), the same rule a bundle import
        // applies to a missing field (F64, F87). A name the route does not have
        // finds no record below.
        if ($styleName === StyleNames::STYLE_ENTRY_RECORD) {
            $loadRecordFrom = trim($this->getSectionPropertyContent($section, 'load_record_from'));
            $routeParams = is_array($interpolationContext['route'] ?? null)
                ? $interpolationContext['route']
                : [];
            $routeRecordId = is_numeric($routeParams[$loadRecordFrom] ?? null)
                ? (int) $routeParams[$loadRecordFrom]
                : 0;
            if ($routeRecordId <= 0) {
                return [];
            }
            $filter = 'AND record_id = ' . $routeRecordId;
        } else {
            $rawFilter = $this->getSectionPropertyContent($section, 'filter');
            $filter = $this->dataTableFilterService->prepareFilter(
                $rawFilter,
                $interpolationContext,
                $this->asStringKeyedMap($interpolationContext['route_requirements'] ?? null),
            );
            if ($rawFilter !== '' && $filter === '') {
                return [];
            }
        }

        try {
            $dbFirst = $styleName === StyleNames::STYLE_ENTRY_RECORD;
            $selectedColumns = '';
            if ($styleName === StyleNames::STYLE_ENTRY_LIST) {
                $selectedColumns = $this->getSectionPropertyContent($section, 'selected_columns');
            }
            $rows = $this->dataService->getData(
                $dataTableId,
                $filter,
                $ownEntriesOnly,
                null,
                $dbFirst,
                true,
                $languageId,
                $selectedColumns,
            );
        } catch (\Exception) {
            return [];
        }

        if ($styleName === StyleNames::STYLE_ENTRY_RECORD) {
            $rows = $rows !== [] ? [$rows] : [];
        } elseif (!array_is_list($rows)) {
            $rows = [$rows];
        }

        // F166: typed fixed_filter on entry-record — same bound as the file route.
        // Outside it is treated like a missing record (empty detail, page still 200).
        if ($styleName === StyleNames::STYLE_ENTRY_RECORD && $rows !== [] && $routeRecordId > 0) {
            if (!$this->entryRecordPassesFixedFilter(
                $section,
                $dataTableId,
                $ownEntriesOnly,
                $routeRecordId,
                $languageId,
            )) {
                return [];
            }
        }

        $tableName = (string) $dataTable->getName();
        if ($rows !== []) {
            $rows = $this->dataService->remapEntriesToInputNames($dataTableId, $rows);
        }

        $keyedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowArray = [];
            foreach ($row as $key => $value) {
                $rowArray[(string) $key] = $value;
            }
            $keyedRows[] = $rowArray;
        }

        // The translated labels of option codes (`_<field>_label`) are added
        // before the rows are put under the `scope`, so `{{scope._<field>_label}}`
        // resolves exactly like `{{_<field>_label}}`.
        if ($tableName !== '' && $keyedRows !== []) {
            $keyedRows = $this->optionLabelHydrator->hydrate($keyedRows, $tableName, $languageId);
        }

        $scope = $this->getSectionPropertyContent($section, 'scope');
        $sectionId = is_numeric($section['id'] ?? null) ? (int) $section['id'] : 0;
        // File URLs must name the page being rendered (contract 1), not the first
        // page that happens to contain the section. __url / __files are the same
        // for every viewer of that page; access is decided on the file request
        // (rule 3.6b: nothing per-user enters the shared page payload shape).
        $normalized = [];
        $index = 0;
        foreach ($keyedRows as $rowArray) {
            if ($styleName === StyleNames::STYLE_ENTRY_LIST) {
                $rowArray['_index'] = $index;
                $index++;
            }
            if ($sectionId > 0 && $pageId > 0) {
                $rowArray = $this->withFileTemplateVars($rowArray, $sectionId, $pageId, $tableName);
            }
            if ($scope !== '') {
                $scoped = [];
                foreach ($rowArray as $key => $value) {
                    if ($key === '_index' || str_contains((string) $key, '.')) {
                        continue;
                    }
                    $scoped[$key] = $value;
                    $rowArray[$scope . '.' . $key] = $value;
                }
                if ($scoped !== []) {
                    $rowArray[$scope] = $scoped;
                }
            }
            $normalized[] = $rowArray;
        }

        return $normalized;
    }

    /**
     * Whether the named record sits inside the entry-record's typed fixed filter.
     *
     * @param array<string, mixed> $section
     */
    private function entryRecordPassesFixedFilter(
        array $section,
        int $dataTableId,
        bool $ownEntriesOnly,
        int $recordId,
        int $languageId,
    ): bool {
        $raw = trim($this->getSectionPropertyContent($section, 'fixed_filter'));
        if ($raw === '') {
            return true;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return false;
            }
            $group = \App\Service\CMS\DataQuery\DataFilterJson::parse($decoded);
        } catch (\JsonException|\App\Service\CMS\DataQuery\DataQueryException) {
            return false;
        }
        if ($group->isEmpty()) {
            return true;
        }

        $group = $this->resolveFixedFilterColumns($group, $dataTableId);

        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user !== null ? (int) $user->getId() : null;
        if ($ownEntriesOnly && $userId === null) {
            return false;
        }

        try {
            $optionalColumns = $this->optionalColumnsForTable($dataTableId);
            $query = new \App\Service\CMS\DataQuery\DataQuery(
                tableId: $dataTableId,
                ownerScope: $ownEntriesOnly
                    ? \App\Service\CMS\DataQuery\DataQuery::OWNER_USER
                    : \App\Service\CMS\DataQuery\DataQuery::OWNER_ANY,
                userId: $userId,
                filter: $group,
                columns: [],
                languageId: $languageId,
                timezone: $user?->getTimezone()?->getLookupCode() ?? 'UTC',
                optionalColumns: $optionalColumns,
            );

            return $this->dataQueryEngine->count($query->forRecord($recordId)) > 0;
        } catch (\App\Service\CMS\DataQuery\DataQueryException) {
            return false;
        }
    }

    /**
     * Declared form fields of the table that may not have a `data_cols` row yet (F169).
     *
     * @return array<string, string>
     */
    private function optionalColumnsForTable(int $dataTableId): array
    {
        $dataTable = $this->dataService->getDataTableById($dataTableId);
        $tableName = $dataTable !== null ? (string) $dataTable->getName() : '';
        if ($tableName === '' || !ctype_digit($tableName)) {
            return [];
        }
        $types = $this->formFieldKeys->getFieldKeyToValueType($tableName);
        $optional = [];
        foreach (array_keys($this->formFieldKeys->getFieldKeyToName($tableName)) as $key) {
            $optional[$key] = $types[$key] ?? \App\Service\Core\LookupService::DATA_COL_VALUE_TYPES_TEXT;
        }

        return $optional;
    }

    private function resolveFixedFilterColumns(
        \App\Service\CMS\DataQuery\DataFilterGroup $group,
        int $dataTableId,
    ): \App\Service\CMS\DataQuery\DataFilterGroup {
        if ($group->isEmpty()) {
            return $group;
        }
        $dataTable = $this->dataService->getDataTableById($dataTableId);
        $tableName = $dataTable !== null ? (string) $dataTable->getName() : '';
        if ($tableName === '') {
            return $group;
        }
        $nameToKey = $this->formFieldKeys->getNameToFieldKey($tableName);
        if ($nameToKey === []) {
            return $group;
        }
        $map = static fn (string $name): string => $nameToKey[$name] ?? $name;
        $rewritten = \App\Service\CMS\FormList\FormListColumnReferences::rewrite(
            \App\Service\CMS\FormList\FormListColumnReferences::FIELD_FIXED_FILTER,
            json_encode(\App\Service\CMS\DataQuery\DataFilterJson::toArray($group), JSON_THROW_ON_ERROR),
            $map,
        );
        if ($rewritten === null) {
            return $group;
        }
        try {
            $decoded = json_decode($rewritten, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded)
                ? \App\Service\CMS\DataQuery\DataFilterJson::parse($decoded)
                : $group;
        } catch (\JsonException|\App\Service\CMS\DataQuery\DataQueryException) {
            return $group;
        }
    }

    /**
     * For every file-input cell of a record shown by entry-list / entry-record /
     * a data-view row template: `{{field__url}}` (first file), `{{field__files}}`
     * (JSON list) and `{{field__links}}` (HTML `<a>` list for the `text` style).
     * URLs are section-scoped; names are HTML-escaped. Never a storage path.
     * The same string for every viewer of that page (rule 3.6b): access is
     * decided on the file request, nothing per-caller enters the page cache.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withFileTemplateVars(array $row, int $sectionId, int $pageId, string $tableName): array
    {
        $recordId = is_numeric($row['record_id'] ?? null) ? (int) $row['record_id'] : 0;
        if ($recordId <= 0 || $tableName === '') {
            return $row;
        }

        foreach ($row as $key => $value) {
            if ($key === '' || str_contains($key, '.') || str_starts_with($key, '_')) {
                continue;
            }
            // Structured file cells (already shaped for list display) expose
            // their section-scoped URL directly for {{field__url}}.
            if (is_array($value) && isset($value['files']) && is_array($value['files'])) {
                $files = [];
                foreach ($value['files'] as $i => $file) {
                    if (!is_array($file)) {
                        continue;
                    }
                    $files[] = [
                        'index' => is_numeric($file['index'] ?? null) ? (int) $file['index'] : (int) $i,
                        'name' => is_string($file['name'] ?? null) ? $file['name'] : '',
                        'mime' => is_string($file['mime'] ?? null) ? $file['mime'] : null,
                        'size' => is_numeric($file['size'] ?? null) ? (int) $file['size'] : 0,
                        'url' => sprintf(
                            '/lists/%d/records/%d/files/%s?page_id=%d&index=%d',
                            $sectionId,
                            $recordId,
                            rawurlencode((string) $key),
                            $pageId,
                            is_numeric($file['index'] ?? null) ? (int) $file['index'] : (int) $i,
                        ),
                    ];
                }
                $row[$key . '__url'] = new ServerBuiltString($files[0]['url'] ?? '');
                $row[$key . '__files'] = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
                $row[$key . '__links'] = new ServerBuiltString(self::fileLinksHtml($files));
                continue;
            }
            $text = is_scalar($value) ? (string) $value : '';
            if ($text === '' || !\App\Service\CMS\FormFileUploadService::cellHoldsFiles($text)) {
                continue;
            }
            if (!$this->formFiles->isFileInputField($key, $tableName)) {
                continue;
            }
            $keys = \App\Service\CMS\FormFileUploadService::keysIn($text);
            $files = [];
            foreach ($keys as $i => $logical) {
                $absolute = $this->formFiles->resolveKey($logical);
                if ($absolute === null) {
                    continue;
                }
                $files[] = [
                    'index' => $i,
                    'name' => $this->formFiles->originalName($absolute),
                    'mime' => mime_content_type($absolute) ?: null,
                    'size' => (int) filesize($absolute),
                    'url' => sprintf(
                        '/lists/%d/records/%d/files/%s?page_id=%d&index=%d',
                        $sectionId,
                        $recordId,
                        rawurlencode($key),
                        $pageId,
                        $i,
                    ),
                ];
            }
            $row[$key . '__url'] = new ServerBuiltString($files[0]['url'] ?? '');
            $row[$key . '__files'] = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
            $row[$key . '__links'] = new ServerBuiltString(self::fileLinksHtml($files));
        }

        return $row;
    }

    /**
     * Ready HTML for the `text` style: one `<a>` per file (escaped name, same
     * section-scoped URL as `__files`). Empty string when there are no files.
     *
     * @param array<mixed> $files
     */
    public static function fileLinksHtml(array $files): string
    {
        $parts = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $url = is_string($file['url'] ?? null) ? $file['url'] : '';
            if ($url === '') {
                continue;
            }
            $name = is_string($file['name'] ?? null) && $file['name'] !== '' ? $file['name'] : $url;
            $parts[] = sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }

        return $parts === [] ? '' : implode('<br>', $parts);
    }

    /**
     * @param array<string, mixed> $section
     */
    private function getSectionPropertyContent(array $section, string $fieldName, string $default = ''): string
    {
        $field = $section[$fieldName] ?? null;
        if (is_array($field) && array_key_exists('content', $field)) {
            return $this->asString($field['content']);
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function getSectionPropertyBool(array $section, string $fieldName, bool $default = true): bool
    {
        $raw = $this->getSectionPropertyContent($section, $fieldName, $default ? '1' : '0');

        return $raw !== '0';
    }

    /**
     * Rows for a `loop` section without data binding: the style's `loop`
     * field carries a static JSON array of row objects. The raw JSON is
     * interpolated with the section's data first (authors may reference
     * parent scopes inside the array), then decoded. Invalid JSON, a
     * non-list value, or non-object items yield no rows.
     *
     * @param array<string, mixed> $section
     * @param array<array-key, mixed> $sectionData
     * @return list<array<string, mixed>>
     */
    private function resolveLoopFieldRows(array $section, array $sectionData): array
    {
        $loopField = $section['loop'] ?? null;
        $raw = is_array($loopField) ? ($loopField['content'] ?? null) : null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        if (str_contains($raw, '{{')) {
            $this->clockBoundaries->noteText($raw);
            $raw = $this->interpolationService->interpolate($raw, $sectionData);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach ($item as $key => $value) {
                $row[(string) $key] = $value;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Render the row template of a data view once per record of a list page.
     *
     * The sections placed inside a data view are the layout of ONE record. Like
     * the children of an `entry-list`, they are processed once per row with the
     * row's values as interpolation data, so `{{title}}`, `{{record_id}}` and
     * `{{_<field>_label}}` resolve per record and every normal section feature
     * (translations, conditions, classes) keeps working. The values arrive
     * already limited to what the list shows, keyed by the stored field key; they
     * are mapped to the input names of a form-owned table and get the translated
     * option labels exactly as the rows of an `entry-list` do.
     *
     * The context of every section is built in one order, and what comes first cannot be changed by what
     * comes later:
     *
     * 1. the identity of the visitor and the site (`{{system.*}}`, `{{globals.*}}`), set fresh for every section;
     * 2. the declared context of the page: the route (`{{route.*}}`, validated by the page's routes) and the
     *    values the author gave the data view itself (its `data_config`, read once for the whole list), each
     *    under the scope name the author chose;
     * 3. the record: its projected columns, `record_id`, `entry_date` and `_index`, at the top level.
     *
     * A column whose name is also a scope name (a form input called `route` or `system`) is therefore not
     * available as a bare `{{token}}` in the layout; it never replaces a scope. A condition of a section of
     * the layout reads the same context (see {@see self::evaluateSectionCondition()}); a section's own
     * `data_config` filters may name the record (`{{record_id}}`) and the route (`{{route.id}}`).
     *
     * @param list<array<string, mixed>> $template the sections inside the data view, as the page tree holds them
     * @param list<array<string, mixed>> $rows one token map per record
     * @param array<string, string> $routeParams the route parameters of the page ({{route.*}}), already validated
     * @param array<string, string> $routeRequirements the requirements of the route that matched
     * @param mixed $supportingConfig the stored `data_config` of the data view itself (JSON text, array or null)
     * @return list<list<array<string, mixed>>> the processed sections of each record, in the order of $rows
     */
    public function renderRowTemplate(
        array $template,
        array $rows,
        int $dataTableId,
        ?int $userId,
        int $languageId,
        array $routeParams = [],
        array $routeRequirements = [],
        mixed $supportingConfig = null,
    ): array {
        if ($rows === []) {
            return [];
        }
        if ($template === []) {
            return array_fill(0, count($rows), []);
        }

        $keyed = [];
        foreach ($this->dataService->remapEntriesToInputNames($dataTableId, $rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowArray = [];
            foreach ($row as $key => $value) {
                $rowArray[(string) $key] = $value;
            }
            $keyed[] = $rowArray;
        }

        $dataTable = $this->dataService->getDataTableById($dataTableId);
        $tableName = $dataTable !== null ? (string) $dataTable->getName() : '';
        if ($tableName !== '' && $keyed !== []) {
            $keyed = $this->optionLabelHydrator->hydrate($keyed, $tableName, $languageId);
        }

        $declared = $this->buildRouteScope($routeParams, $routeRequirements);
        $declared = array_merge($this->supportingScopes($supportingConfig, $declared, $languageId), $declared);
        $reserved = array_fill_keys(array_merge(array_keys($declared), self::ROW_TEMPLATE_RESERVED_SCOPES), true);

        $rendered = [];
        foreach ($keyed as $index => $rowArray) {
            $context = array_merge(array_diff_key($rowArray, $reserved), $declared);
            $context['_index'] = $index;
            $rendered[] = $this->processSectionsRecursively($template, $context, $userId, $languageId, true);
        }

        return $rendered;
    }

    /**
     * The supporting values the author gave a data view itself: its `data_config`, read once for the whole
     * list with the route as the only context (the same way any section reads its data_config), one scope
     * per entry. A scope can never be called like the identity scopes or the route.
     *
     * @param array<array-key, mixed> $declared the route scope
     * @return array<string, mixed> scope name => values
     */
    private function supportingScopes(mixed $config, array $declared, int $languageId): array
    {
        if ($config === null || $config === '' || $config === []) {
            return [];
        }

        $holder = ['data_config' => $config];
        $this->interpolateDataConfigInSection($holder, $declared, true);
        $this->retrieveSectionData($holder, $declared, $languageId, true);

        $scopes = [];
        foreach ($this->asArray($holder['retrieved_data'] ?? []) as $name => $values) {
            if (is_string($name) && !in_array($name, self::ROW_TEMPLATE_RESERVED_SCOPES, true)) {
                $scopes[$name] = $values;
            }
        }

        return $scopes;
    }

    /**
     * Clone the entry-list / loop child template once per bound row and
     * process each clone with the row's columns flattened into the
     * interpolation data (so `{{name}}` / `{{record_id}}` resolve per row).
     * The inherited `route` scope is re-pinned after the flatten (same rule
     * as Step 4b).
     *
     * @param array<int, array<string, mixed>> $templateChildren
     * @param list<array<string, mixed>> $rows
     * @param array<array-key, mixed> $sectionData
     * @return list<array<string, mixed>>
     */
    private function hydrateEntryListChildren(array $templateChildren, array $rows, array $sectionData, ?int $userId, int $languageId, bool $rowTemplate = false, int $pageId = 0): array
    {
        $hydrated = [];
        foreach ($rows as $row) {
            $rowData = array_merge($sectionData, $row);
            if (isset($sectionData['route'])) {
                $rowData['route'] = $sectionData['route'];
            }
            foreach ($this->processSectionsRecursively($templateChildren, $rowData, $userId, $languageId, $rowTemplate, $pageId) as $child) {
                $hydrated[] = $child;
            }
        }

        return $hydrated;
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
    private function interpolateDataConfigInSection(array &$section, array $parentData, bool $strict = false): void
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
                $dataConfig[$configIndex] = $this->interpolateDataConfig($this->toConfigArray($configEntry), $parentData, $strict);
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
    private function applyOptimizedInterpolationPass(array &$section, array $interpolationData, bool $conditionAsText = true): void
    {
        if (empty($interpolationData)) {
            return;
        }

        // Check if debug is enabled for this section
        $isDebugEnabled = isset($section['debug']) && $section['debug'];
        $interpolateCondition = $isDebugEnabled && $conditionAsText;

        $this->noteClockTexts($section);

        // Update section's retrieved_data for interpolation access
        $section['retrieved_data'] = $interpolationData;

        // Interpolate direct string fields that exist
        $directStringFields = ['css', 'css_mobile'];
        if ($interpolateCondition) {
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
        $this->interpolateExistingContentFields($section, $interpolationData, $interpolateCondition);
    }

    /**
     * Report the clock tokens (`{{system.current_date}}` and the like) in the texts of one section
     * that are about to be interpolated: such a text changes by itself as time passes, which the
     * page reports as `sync.valid_until`. Only the section's own fields are read, never its children.
     *
     * @param array<string, mixed> $section
     */
    private function noteClockTexts(array $section): void
    {
        foreach (['css', 'css_mobile'] as $field) {
            if (isset($section[$field]) && is_string($section[$field])) {
                $this->clockBoundaries->noteText($section[$field]);
            }
        }
        foreach (['content', 'meta'] as $field) {
            if (isset($section[$field])) {
                $this->clockBoundaries->noteTexts($section[$field]);
            }
        }
        foreach ($section as $fieldValue) {
            if (is_array($fieldValue) && isset($fieldValue['content']) && is_string($fieldValue['content'])) {
                $this->clockBoundaries->noteText($fieldValue['content']);
            }
        }
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
    private function retrieveSectionData(array &$section, array $availableData, int $languageId, bool $strict = false): void
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
                        $interpolatedConfig = $this->interpolateDataConfig($this->toConfigArray($config), $availableData, $strict);
                        $configData = $this->sectionUtilityService->retrieveData(
                            $interpolatedConfig,
                            [],
                            $languageId,
                            $this->toConfigArray($availableData),
                        );
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
     * @param bool $strict a filter that cannot be resolved (a route token on a request without that route parameter, an
     *                     unsafe fragment) matches nothing instead of turning into "no filter"; used for the layout of a
     *                     data view, whose supporting values are bound to its record and its route
     * @return array<string, mixed> The interpolated config
     */
    private function interpolateDataConfig(array $config, array $availableData, bool $strict = false): array
    {
        if (empty($availableData)) {
            return $config;
        }

        $this->clockBoundaries->noteTexts($config);

        $interpolatedConfig = $config;

        // Interpolate string fields that might contain variables
        $fieldsToInterpolate = ['filter', 'table', 'retrieve'];

        foreach ($fieldsToInterpolate as $field) {
            if (isset($interpolatedConfig[$field]) && is_string($interpolatedConfig[$field])) {
                if ($field === 'filter') {
                    $prepared = $this->dataTableFilterService->prepareFilter(
                        $interpolatedConfig[$field],
                        $availableData,
                        $this->asStringKeyedMap($availableData['route_requirements'] ?? null),
                    );
                    if ($strict && trim($interpolatedConfig[$field]) !== '' && $prepared === '') {
                        // Record ids start at 1: this fragment is valid and finds no record.
                        $prepared = 'AND record_id = 0';
                    }
                    $interpolatedConfig[$field] = $prepared;
                } else {
                    $interpolatedConfig[$field] = $this->interpolationService->interpolate($interpolatedConfig[$field], $availableData);
                }
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
     * @param array<array-key, mixed>|null $tokenData The data a `{{token}}` of the condition is read from; given for the
     *                        sections of a record template only (the condition of any other section is left as it is)
     * @return array{passes: bool, debug?: array<string, mixed>} Result with 'passes' boolean and optional 'debug' info
     */
    private function evaluateSectionCondition(array $section, ?int $userId, int $languageId = Language::INDEPENDENT_ID, ?array $tokenData = null): array
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

        // A section inside the layout of one record of a data view: the condition may name the record and the
        // route (`{"==": [{"var": "{{status}}"}, "late"]}`, `{{route.record_id}}`). Its tokens are resolved in
        // the decoded condition from the data of the section, so a value can never change the shape of the
        // condition or pick a system variable. Everything else evaluates the stored condition as it always did.
        $authored = $condition;
        if ($tokenData !== null) {
            $condition = $this->interpolationService->interpolateCondition($condition, $tokenData);
        }

        /** @var array{result: bool, fields?: mixed, debug?: array<string, mixed>} $conditionResult */
        $conditionResult = $this->conditionService->evaluateCondition(
            $condition,
            $userId,
            $this->asString($section['keyword'] ?? 'unknown'),
            $languageId
        );

        // `condition_debug` travels with the section to every visitor. The condition of a record template is shown
        // as the author wrote it (with its tokens): the values it was resolved with belong to a record or to a
        // supporting table and may be cells the layout never draws. Only a section with `debug` switched on shows them.
        $shown = $tokenData !== null && empty($section['debug']) ? $authored : $condition;

        return [
            'passes' => $conditionResult['result'],
            'debug' => $this->conditionService->buildConditionDebug($conditionResult, $shown),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function asStringKeyedMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $out[$key] = is_scalar($item) ? (string) $item : '';
        }

        return $out;
    }

}
