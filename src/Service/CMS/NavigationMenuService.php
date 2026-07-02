<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Navigation\NavigationMenuItemTranslationSupport;
use App\Navigation\NavigationMenuResolveSupport;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\NavigationMenuItemTranslationRepository;
use App\Repository\NavigationMenuRepository;
use App\Repository\NavigationSettingsRepository;
use App\Service\CMS\Frontend\PageService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Auth\UserContextService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves navigation menus, settings, and startup metadata for public clients.
 */
class NavigationMenuService extends BaseService
{
    /** @var list<string> */
    private const MENU_KEYS = [
        LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
        LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER,
        LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER,
        LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS,
    ];

    public function __construct(
        private readonly NavigationMenuRepository $navigationMenuRepository,
        private readonly NavigationMenuItemRepository $navigationMenuItemRepository,
        private readonly NavigationMenuItemTranslationRepository $navigationMenuItemTranslationRepository,
        private readonly NavigationMenuItemTranslationSupport $navigationMenuItemTranslationSupport,
        private readonly NavigationSettingsRepository $navigationSettingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PageService $pageService,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly LookupService $lookupService,
        private readonly CacheService $cache,
        private readonly UserContextService $userContextAwareService,
        private readonly UserNavigationStateService $userNavigationStateService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublicNavigationPayload(string $mode, ?int $languageId = null): array
    {
        $user = $this->userContextAwareService->getCurrentUser();
        $userId = $user ? (int) $user->getId() : UserContextService::GUEST_USER_ID;
        $languageId = $languageId ?? $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;

        $cacheKey = sprintf('navigation_%s_%d_%d', $mode, $languageId, $userId);

        return $this->cache
            ->withCategory(CacheService::CATEGORY_NAVIGATION)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_LANGUAGE, $languageId)
            ->getItem($cacheKey, function () use ($mode, $languageId, $userId): array {
                return $this->buildPublicNavigationPayload($mode, $languageId, $userId);
            });
    }

    /**
     * @return array{warnings: list<array<string, mixed>>, suggestions: list<array<string, mixed>>}
     */
    public function getAdminMenuDiagnostics(string $menuKey, int $languageId): array
    {
        unset($menuKey, $languageId);

        return ['warnings' => [], 'suggestions' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublicNavigationPayload(string $mode, int $languageId, int $userId): array
    {
        $pageTree = $this->pageService->getAllAccessiblePagesForUser($mode, false, $languageId);
        $pageMap = $this->flattenPageTreeToMap($pageTree);
        $sectionCounts = $this->fetchSectionCounts(array_keys($pageMap));

        $menus = [];
        foreach (self::MENU_KEYS as $menuKeyCode) {
            $menu = $this->navigationMenuRepository->findByMenuKeyLookupId(
                (int) $this->lookupService->getLookupIdByCode(LookupService::NAVIGATION_MENU_KEYS, $menuKeyCode)
            );
            if (!$menu instanceof NavigationMenu) {
                continue;
            }
            $menus[$menuKeyCode] = $this->formatMenu($menu, $pageMap, $sectionCounts, $languageId);
        }

        $settings = $this->navigationSettingsRepository->getSingleton();
        $user = $userId === UserContextService::GUEST_USER_ID
            ? null
            : $this->userContextAwareService->getCurrentUser();

        return [
            'menus' => $menus,
            'startup' => $this->formatStartup($settings, $pageMap, $sectionCounts, $languageId, $user),
            'search' => $this->formatSearch($settings),
        ];
    }

    /**
     * @param list<array<string, mixed>> $tree
     *
     * @return array<int, array<string, mixed>>
     */
    private function flattenPageTreeToMap(array $tree): array
    {
        /** @var array<int, array<string, mixed>> $map */
        $map = [];
        $walk = function (array $nodes) use (&$map, &$walk): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $id = $this->pageIdFromNode($node);
                if ($id > 0) {
                    /** @var array<string, mixed> $pageNode */
                    $pageNode = $node;
                    $map[$id] = $pageNode;
                }
                $children = $node['children'] ?? null;
                if (is_array($children) && $children !== []) {
                    /** @var list<array<string, mixed>> $childList */
                    $childList = array_values(array_filter($children, 'is_array'));
                    $walk($childList);
                }
            }
        };
        $walk($tree);

        return $map;
    }

    /**
     * @param array<mixed, mixed> $node
     */
    private function pageIdFromNode(array $node): int
    {
        if (isset($node['id_pages']) && is_numeric($node['id_pages'])) {
            return (int) $node['id_pages'];
        }
        if (isset($node['id']) && is_numeric($node['id'])) {
            return (int) $node['id'];
        }

        return 0;
    }

    /**
     * @param array<mixed, mixed> $node
     */
    private function isHeadlessNode(array $node): bool
    {
        $headless = $node['is_headless'] ?? false;

        return $headless === true || $headless === 1 || $headless === '1';
    }

    /**
     * @param array<mixed, mixed> $node
     */
    private function stringOrNullFromNode(array $node, string $key): ?string
    {
        if (!isset($node[$key]) || !is_scalar($node[$key])) {
            return null;
        }

        return (string) $node[$key];
    }

    /**
     * @param list<int> $pageIds
     *
     * @return array<int, int>
     */
    private function fetchSectionCounts(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        /** @var list<array{page_id:int|string, cnt:int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(ps.page) AS page_id', 'COUNT(ps.section) AS cnt')
            ->from(\App\Entity\PagesSection::class, 'ps')
            ->andWhere('ps.page IN (:ids)')
            ->setParameter('ids', $pageIds)
            ->groupBy('ps.page')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['page_id']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $pageMap
     * @param array<int, int> $sectionCounts
     *
     * @return array<string, mixed>
     */
    private function formatMenu(
        NavigationMenu $menu,
        array $pageMap,
        array $sectionCounts,
        int $languageId,
    ): array {
        $menuKey = $menu->getMenuKey()?->getLookupCode() ?? '';
        $items = $this->navigationMenuItemRepository->findActiveByMenu($menu);
        $itemIds = array_values(array_filter(array_map(
            static fn (NavigationMenuItem $item): ?int => $item->getId(),
            $items,
        )));
        $translationMap = $this->navigationMenuItemTranslationRepository->findLabelsByMenuItemIds($itemIds);
        $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;
        $roots = NavigationMenuResolveSupport::resolveRootMenuItems($items);
        $resolvedRoots = $this->buildItemTree(
            $roots,
            $items,
            $pageMap,
            $sectionCounts,
            0,
            $menu->getMaxDepth(),
            $languageId,
            $defaultLanguageId,
            $translationMap,
        );

        if ($menuKey === LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS) {
            $resolvedRoots = NavigationMenuResolveSupport::applyRootItemLimit($resolvedRoots, $menu->getItemLimit());
        }

        return [
            'key' => $menuKey,
            'platform' => $menu->getPlatform()?->getLookupCode(),
            'surface' => $menu->getSurface()?->getLookupCode(),
            'preset' => $menu->getPreset()?->getLookupCode(),
            'max_depth' => $menu->getMaxDepth(),
            'item_limit' => $menu->getItemLimit(),
            'config' => $menu->getConfig(),
            'items' => $resolvedRoots,
        ];
    }

    /**
     * @param list<NavigationMenuItem> $roots
     * @param list<NavigationMenuItem> $allItems
     * @param array<int, array<string, mixed>> $pageMap
     * @param array<int, int> $sectionCounts
     * @param array<int, array<int, string>> $translationMap
     *
     * @return list<array<string, mixed>>
     */
    private function buildItemTree(
        array $roots,
        array $allItems,
        array $pageMap,
        array $sectionCounts,
        int $depth,
        ?int $maxDepth,
        int $languageId,
        int $defaultLanguageId,
        array $translationMap,
    ): array {
        $out = [];
        foreach ($roots as $item) {
            $formatted = $this->formatMenuItem(
                $item,
                $allItems,
                $pageMap,
                $sectionCounts,
                $depth,
                $maxDepth,
                $languageId,
                $defaultLanguageId,
                $translationMap,
            );
            if ($formatted !== null) {
                $out[] = $formatted;
            }
        }

        return $out;
    }

    /**
     * @param list<NavigationMenuItem> $allItems
     * @param array<int, array<string, mixed>> $pageMap
     * @param array<int, int> $sectionCounts
     * @param array<int, array<int, string>> $translationMap
     *
     * @return array<string, mixed>|null
     */
    private function formatMenuItem(
        NavigationMenuItem $item,
        array $allItems,
        array $pageMap,
        array $sectionCounts,
        int $depth,
        ?int $maxDepth,
        int $languageId,
        int $defaultLanguageId,
        array $translationMap,
    ): ?array {
        if (!$item->isActive()) {
            return null;
        }

        $typeCode = $item->getItemType()?->getLookupCode() ?? LookupService::NAVIGATION_ITEM_TYPE_PAGE;
        $pageNode = null;
        $pageId = $item->getPage()?->getId();
        if ($pageId !== null) {
            if (!array_key_exists($pageId, $pageMap)) {
                return null;
            }
            /** @var array<string, mixed> $pageNode */
            $pageNode = $pageMap[$pageId];
            if ($this->isHeadlessNode($pageNode)) {
                return null;
            }
        }

        $children = [];
        foreach ($allItems as $childItem) {
            if ($childItem->getParentItem()?->getId() === $item->getId()) {
                $children[] = $childItem;
            }
        }

        $childItems = [];
        if ($maxDepth === null || $maxDepth === 0 || $depth < $maxDepth) {
            $childItems = $this->buildItemTree(
                $children,
                $allItems,
                $pageMap,
                $sectionCounts,
                $depth + 1,
                $maxDepth,
                $languageId,
                $defaultLanguageId,
                $translationMap,
            );
        }

        if ($typeCode === LookupService::NAVIGATION_ITEM_TYPE_GROUP && $childItems === []) {
            return null;
        }

        $pageTitle = $pageNode !== null ? $this->stringOrNullFromNode($pageNode, 'title') : null;
        $itemId = $item->getId() ?? 0;
        /** @var array<int, string> $labelsByLanguage */
        $labelsByLanguage = $translationMap[$itemId] ?? [];
        $label = $this->resolveMenuItemLabel(
            $item,
            $typeCode,
            $pageTitle,
            $pageNode,
            $labelsByLanguage,
            $languageId,
            $defaultLanguageId,
        );
        if ($label === null || $label === '') {
            return null;
        }

        $formatted = [
            'id' => $item->getId(),
            'item_type' => $typeCode,
            'label' => $label,
            'position' => $item->getPosition(),
            'is_active' => true,
            'children' => $childItems,
        ];

        if ($item->getIcon() !== null && $item->getIcon() !== '') {
            $formatted['icon'] = $item->getIcon();
        }
        if ($item->getMobileIcon() !== null && $item->getMobileIcon() !== '') {
            $formatted['mobile_icon'] = $item->getMobileIcon();
        }

        if ($typeCode === LookupService::NAVIGATION_ITEM_TYPE_EXTERNAL_URL) {
            $formatted['external_url'] = $item->getExternalUrl();
        }

        if ($pageNode !== null) {
            $sectionCount = $sectionCounts[$pageId] ?? 0;
            $formatted['page'] = [
                'id' => $pageId,
                'keyword' => $this->stringOrNullFromNode($pageNode, 'keyword') ?? '',
                'url' => $this->stringOrNullFromNode($pageNode, 'url'),
                'title' => $label,
                'has_content' => $sectionCount > 0,
                'section_count' => $sectionCount,
            ];
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed>|null $pageNode
     * @param array<int, string>         $labelsByLanguage
     */
    private function resolveMenuItemLabel(
        NavigationMenuItem $item,
        string $typeCode,
        ?string $pageTitle,
        ?array $pageNode,
        array $labelsByLanguage,
        int $languageId,
        int $defaultLanguageId,
    ): ?string {
        if ($typeCode === LookupService::NAVIGATION_ITEM_TYPE_PAGE) {
            if ($pageTitle !== null && $pageTitle !== '') {
                return $pageTitle;
            }
            if ($pageNode !== null) {
                $keyword = $this->stringOrNullFromNode($pageNode, 'keyword');

                return ($keyword === null || $keyword === '') ? null : $keyword;
            }

            return null;
        }

        return $this->navigationMenuItemTranslationSupport->resolveLabel(
            $labelsByLanguage,
            $item->getLabel(),
            $languageId,
            $defaultLanguageId,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $pageMap
     * @param array<int, int> $sectionCounts
     *
     * @return array<string, mixed>
     */
    private function formatStartup(
        ?\App\Entity\NavigationSettings $settings,
        array $pageMap,
        array $sectionCounts,
        int $languageId,
        ?\App\Entity\User $user,
    ): array {
        if ($settings === null) {
            return [
                'web_guest_start_page' => null,
                'web_user_start_page' => null,
                'web_user_start_mode' => LookupService::NAVIGATION_START_MODE_FIXED_PAGE,
                'web_user_last_visited_page' => null,
                'mobile_guest_start_page' => null,
                'mobile_user_start_page' => null,
                'mobile_user_start_mode' => LookupService::NAVIGATION_START_MODE_FIXED_PAGE,
                'mobile_user_last_visited_page' => null,
                'mobile_start_page_source' => LookupService::NAVIGATION_MOBILE_START_SAME_AS_WEB,
            ];
        }

        $webLastVisited = null;
        $mobileLastVisited = null;
        if ($user !== null) {
            $webLastVisited = $this->userNavigationStateService->resolveLastVisitedForUser(
                $user,
                'web',
                $languageId,
            );
            $mobileLastVisited = $this->userNavigationStateService->resolveLastVisitedForUser(
                $user,
                'mobile',
                $languageId,
            );
        }

        return [
            'web_guest_start_page' => $this->formatPageRef($settings->getWebGuestStartPage()?->getId(), $pageMap, $sectionCounts),
            'web_user_start_page' => $this->formatPageRef($settings->getWebUserStartPage()?->getId(), $pageMap, $sectionCounts),
            'web_user_start_mode' => $settings->getWebUserStartMode()?->getLookupCode() ?? LookupService::NAVIGATION_START_MODE_FIXED_PAGE,
            'web_user_last_visited_page' => $this->formatLastVisitedRef($webLastVisited, $pageMap, $sectionCounts),
            'mobile_guest_start_page' => $this->formatPageRef($settings->getMobileGuestStartPage()?->getId(), $pageMap, $sectionCounts),
            'mobile_user_start_page' => $this->formatPageRef($settings->getMobileUserStartPage()?->getId(), $pageMap, $sectionCounts),
            'mobile_user_start_mode' => $settings->getMobileUserStartMode()?->getLookupCode() ?? LookupService::NAVIGATION_START_MODE_FIXED_PAGE,
            'mobile_user_last_visited_page' => $this->formatLastVisitedRef($mobileLastVisited, $pageMap, $sectionCounts),
            'mobile_start_page_source' => $settings->getMobileStartPageSource()?->getLookupCode() ?? LookupService::NAVIGATION_MOBILE_START_SAME_AS_WEB,
        ];
    }

    /**
     * @param array<string, mixed>|null $lastVisited
     * @param array<int, array<string, mixed>> $pageMap
     * @param array<int, int> $sectionCounts
     *
     * @return array<string, mixed>|null
     */
    private function formatLastVisitedRef(?array $lastVisited, array $pageMap, array $sectionCounts): ?array
    {
        if ($lastVisited === null) {
            return null;
        }
        $pageId = $lastVisited['page_id'] ?? null;
        if (!is_int($pageId) && !is_numeric($pageId)) {
            return null;
        }

        $ref = $this->formatPageRef((int) $pageId, $pageMap, $sectionCounts);
        if ($ref === null) {
            return null;
        }
        if (isset($lastVisited['url']) && is_string($lastVisited['url'])) {
            $ref['url'] = $lastVisited['url'];
        }
        if (isset($lastVisited['keyword']) && is_string($lastVisited['keyword'])) {
            $ref['keyword'] = $lastVisited['keyword'];
        }

        return $ref;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSearch(?\App\Entity\NavigationSettings $settings): array
    {
        if ($settings === null) {
            return [
                'mode' => LookupService::NAVIGATION_SEARCH_MODE_CONTENT_INDEX,
                'min_chars' => 2,
                'result_limit' => 8,
                'default_visibility' => 'all_accessible_pages',
                'field_policy' => 'all_display_text',
            ];
        }

        return [
            'mode' => $settings->getWebHeaderSearchMode()?->getLookupCode() ?? LookupService::NAVIGATION_SEARCH_MODE_CONTENT_INDEX,
            'min_chars' => $settings->getWebHeaderSearchMinChars(),
            'result_limit' => $settings->getWebHeaderSearchResultLimit(),
            'default_visibility' => $settings->getSearchDefaultVisibility()?->getLookupCode() ?? 'all_accessible_pages',
            'field_policy' => $settings->getSearchFieldPolicy()?->getLookupCode() ?? 'all_display_text',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pageMap
     * @param array<int, int> $sectionCounts
     *
     * @return array<string, mixed>|null
     */
    private function formatPageRef(?int $pageId, array $pageMap, array $sectionCounts): ?array
    {
        if ($pageId === null || !isset($pageMap[$pageId])) {
            return null;
        }
        $node = $pageMap[$pageId];
        $sectionCount = $sectionCounts[$pageId] ?? 0;

        return [
            'id' => $pageId,
            'keyword' => $this->stringOrNullFromNode($node, 'keyword') ?? '',
            'url' => $this->stringOrNullFromNode($node, 'url'),
            'title' => $this->stringOrNullFromNode($node, 'title'),
            'has_content' => $sectionCount > 0,
            'section_count' => $sectionCount,
        ];
    }

    public function invalidateNavigationCaches(): void
    {
        $this->cache
            ->withCategory(CacheService::CATEGORY_NAVIGATION)
            ->invalidateCategory();
    }
}
