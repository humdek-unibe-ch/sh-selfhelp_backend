<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Navigation\NavigationMenuResolveSupport;
use App\Repository\NavigationMenuItemExclusionRepository;
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
        private readonly NavigationMenuItemExclusionRepository $navigationMenuItemExclusionRepository,
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
     * Admin-only diagnostics: hidden auto-children and manual-plus suggestions.
     *
     * @return array{warnings: list<array<string, mixed>>, suggestions: list<array<string, mixed>>}
     */
    public function getAdminMenuDiagnostics(string $menuKey, int $languageId): array
    {
        $menu = $this->navigationMenuRepository->findByMenuKeyLookupId(
            (int) $this->lookupService->getLookupIdByCode(LookupService::NAVIGATION_MENU_KEYS, $menuKey)
        );
        if (!$menu instanceof NavigationMenu) {
            return ['warnings' => [], 'suggestions' => []];
        }

        $mode = $menuKey === LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER
            || $menuKey === LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS
            ? LookupService::PAGE_ACCESS_TYPES_MOBILE
            : LookupService::PAGE_ACCESS_TYPES_WEB;

        $authoringTree = $this->pageService->getAllAccessiblePagesForUser($mode, true, $languageId);
        $publicTree = $this->pageService->getAllAccessiblePagesForUser($mode, false, $languageId);
        $authoringMap = $this->flattenPageTreeToMap($authoringTree);
        $publicMap = $this->flattenPageTreeToMap($publicTree);

        $warnings = [];
        $suggestions = [];
        $items = $this->navigationMenuItemRepository->findActiveByMenu($menu);

        foreach ($items as $item) {
            $childSource = $item->getChildSource()?->getLookupCode() ?? LookupService::NAVIGATION_CHILD_SOURCE_MANUAL;
            $pageId = $item->getPage()?->getId();
            if ($pageId === null || !isset($authoringMap[$pageId])) {
                continue;
            }

            $authoringNode = $authoringMap[$pageId];
            $treeChildren = $authoringNode['children'] ?? [];
            if (!is_array($treeChildren)) {
                $treeChildren = [];
            }
            /** @var list<array<string, mixed>> $childList */
            $childList = array_values(array_filter($treeChildren, 'is_array'));

            if ($childSource === LookupService::NAVIGATION_CHILD_SOURCE_PAGE_CHILDREN) {
                $excluded = $this->navigationMenuItemExclusionRepository->findExcludedPageIdsForItem($item);
                foreach (NavigationMenuResolveSupport::hiddenAutoIncludeChildren($childList, $publicMap, $excluded) as $hidden) {
                    $warnings[] = [
                        'code' => 'hidden_auto_child',
                        'menu_item_id' => $item->getId(),
                        'parent_page_id' => $pageId,
                        'page_id' => $hidden['page_id'],
                        'keyword' => $hidden['keyword'],
                        'message' => sprintf(
                            'Page "%s" is a child in the page tree but is hidden from the public menu (ACL, platform, or headless).',
                            $hidden['keyword'] !== '' ? $hidden['keyword'] : (string) $hidden['page_id'],
                        ),
                    ];
                }
            }

            if ($childSource === LookupService::NAVIGATION_CHILD_SOURCE_MANUAL_PLUS_SUGGESTIONS) {
                $explicitChildPageIds = [];
                foreach ($items as $candidate) {
                    if ($candidate->getParentItem()?->getId() !== $item->getId()) {
                        continue;
                    }
                    $childPageId = $candidate->getPage()?->getId();
                    if ($childPageId !== null) {
                        $explicitChildPageIds[] = (int) $childPageId;
                    }
                }
                foreach (NavigationMenuResolveSupport::suggestedManualChildren($childList, $explicitChildPageIds) as $suggested) {
                    $suggestions[] = [
                        'code' => 'child_not_in_menu',
                        'menu_item_id' => $item->getId(),
                        'parent_page_id' => $pageId,
                        'page_id' => $suggested['page_id'],
                        'keyword' => $suggested['keyword'],
                        'message' => sprintf(
                            'Page "%s" is not in this menu branch. Add it explicitly or switch child source to page_children.',
                            $suggested['keyword'] !== '' ? $suggested['keyword'] : (string) $suggested['page_id'],
                        ),
                    ];
                }
            }
        }

        return ['warnings' => $warnings, 'suggestions' => $suggestions];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublicNavigationPayload(string $mode, int $languageId, int $userId): array
    {
        $defaultLanguageId = $this->cmsPreferenceService->getDefaultLanguageId();
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
            $menus[$menuKeyCode] = $this->formatMenu($menu, $pageMap, $sectionCounts, $languageId, $defaultLanguageId);
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
        ?int $defaultLanguageId,
    ): array {
        $menuKey = $menu->getMenuKey()?->getLookupCode() ?? '';
        $items = $this->navigationMenuItemRepository->findActiveByMenu($menu);
        $itemIds = array_values(array_filter(array_map(
            static fn (NavigationMenuItem $i): ?int => $i->getId(),
            $items,
        )));
        $translationsByItem = $this->navigationMenuItemTranslationRepository->fetchTranslationsForItems(
            $itemIds,
            $languageId,
            $defaultLanguageId,
        );
        $roots = array_values(array_filter($items, static fn (NavigationMenuItem $i): bool => $i->getParentItem() === null));
        $resolvedRoots = $this->buildItemTree(
            $roots,
            $items,
            $pageMap,
            $sectionCounts,
            $languageId,
            $defaultLanguageId,
            $translationsByItem,
            0,
            $menu->getMaxDepth(),
        );

        if ($menuKey === LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS) {
            /** @var list<array<string, mixed>> $limitedRoots */
            $limitedRoots = NavigationMenuResolveSupport::applyRootItemLimit($resolvedRoots, $menu->getItemLimit());
            $resolvedRoots = $limitedRoots;
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
     * @param array<int, array<int, array{label: ?string, description: ?string, aria_label: ?string}>> $translationsByItem
     *
     * @return list<array<string, mixed>>
     */
    private function buildItemTree(
        array $roots,
        array $allItems,
        array $pageMap,
        array $sectionCounts,
        int $languageId,
        ?int $defaultLanguageId,
        array $translationsByItem,
        int $depth,
        ?int $maxDepth,
    ): array {
        $out = [];
        foreach ($roots as $item) {
            $formatted = $this->formatMenuItem(
                $item,
                $allItems,
                $pageMap,
                $sectionCounts,
                $languageId,
                $defaultLanguageId,
                $translationsByItem,
                $depth,
                $maxDepth,
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
     * @param array<int, array<int, array{label: ?string, description: ?string, aria_label: ?string}>> $translationsByItem
     *
     * @return array<string, mixed>|null
     */
    private function formatMenuItem(
        NavigationMenuItem $item,
        array $allItems,
        array $pageMap,
        array $sectionCounts,
        int $languageId,
        ?int $defaultLanguageId,
        array $translationsByItem,
        int $depth,
        ?int $maxDepth,
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
                $languageId,
                $defaultLanguageId,
                $translationsByItem,
                $depth + 1,
                $maxDepth,
            );
        }

        if ($this->shouldAutoIncludeChildren($item) && $pageId !== null) {
            $autoChildren = $this->buildAutoIncludedChildren(
                $item,
                $pageNode,
                $pageMap,
                $sectionCounts,
                $languageId,
                $defaultLanguageId,
                $translationsByItem,
                1,
                $item->getAutoIncludeDepth() ?? 1,
            );
            $childItems = $this->mergeItemLists($childItems, $autoChildren);
        }

        if ($typeCode === LookupService::NAVIGATION_ITEM_TYPE_GROUP && $childItems === []) {
            return null;
        }

        $pageTitle = $pageNode !== null ? $this->stringOrNullFromNode($pageNode, 'title') : null;
        $presentation = $this->navigationMenuItemTranslationRepository->resolvePresentationFromMap(
            $item,
            $translationsByItem,
            $languageId,
            $defaultLanguageId,
            $pageTitle,
        );

        $formatted = [
            'id' => $item->getId(),
            'item_type' => $typeCode,
            'label' => $presentation['label'],
            'position' => $item->getPosition(),
            'is_active' => true,
            'child_source' => $item->getChildSource()?->getLookupCode(),
            'children' => $childItems,
        ];

        if ($presentation['description'] !== null) {
            $formatted['description'] = $presentation['description'];
        }
        if ($presentation['aria_label'] !== null) {
            $formatted['aria_label'] = $presentation['aria_label'];
        }

        if ($item->getIconOverride()) {
            $formatted['icon'] = $item->getIconOverride();
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
                'title' => $this->stringOrNullFromNode($pageNode, 'title'),
                'icon' => $this->stringOrNullFromNode($pageNode, 'icon'),
                'mobile_icon' => $this->stringOrNullFromNode($pageNode, 'mobile_icon'),
                'has_content' => $sectionCount > 0,
                'section_count' => $sectionCount,
            ];
        }

        return $formatted;
    }

    private function shouldAutoIncludeChildren(NavigationMenuItem $item): bool
    {
        $code = $item->getChildSource()?->getLookupCode();

        return $code === LookupService::NAVIGATION_CHILD_SOURCE_PAGE_CHILDREN;
    }

    /**
     * @param array<string, mixed> $parentNode
     * @param array<int, array<string, mixed>> $pageMap
     * @param array<int, int> $sectionCounts
     * @param array<int, array<int, array{label: ?string, description: ?string, aria_label: ?string}>> $translationsByItem
     *
     * @return list<array<string, mixed>>
     */
    private function buildAutoIncludedChildren(
        NavigationMenuItem $parentItem,
        array $parentNode,
        array $pageMap,
        array $sectionCounts,
        int $languageId,
        ?int $defaultLanguageId,
        array $translationsByItem,
        int $currentDepth,
        int $maxAutoDepth,
    ): array {
        if ($currentDepth > $maxAutoDepth) {
            return [];
        }

        $excluded = $this->navigationMenuItemExclusionRepository->findExcludedPageIdsForItem($parentItem);
        $children = $parentNode['children'] ?? [];
        if (!is_array($children)) {
            return [];
        }

        $out = [];
        $position = 1000;
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $childId = $this->pageIdFromNode($child);
            if ($childId <= 0 || in_array($childId, $excluded, true)) {
                continue;
            }
            if (!array_key_exists($childId, $pageMap) || $this->isHeadlessNode($child)) {
                continue;
            }
            /** @var array<string, mixed> $childNode */
            $childNode = $pageMap[$childId];
            $sectionCount = $sectionCounts[$childId] ?? 0;
            $nestedAuto = $currentDepth < $maxAutoDepth
                ? $this->buildAutoIncludedChildren(
                    $parentItem,
                    $childNode,
                    $pageMap,
                    $sectionCounts,
                    $languageId,
                    $defaultLanguageId,
                    $translationsByItem,
                    $currentDepth + 1,
                    $maxAutoDepth,
                )
                : [];

            $childLabel = $this->stringOrNullFromNode($child, 'title')
                ?? $this->stringOrNullFromNode($child, 'keyword')
                ?? '';
            $childDescription = $this->stringOrNullFromNode($child, 'description');

            $virtualItem = [
                'id' => 'virtual-' . $parentItem->getId() . '-' . $childId,
                'item_type' => LookupService::NAVIGATION_ITEM_TYPE_PAGE,
                'label' => $childLabel,
                'position' => $position,
                'is_active' => true,
                'is_virtual' => true,
                'children' => $nestedAuto,
                'page' => [
                    'id' => $childId,
                    'keyword' => $this->stringOrNullFromNode($child, 'keyword') ?? '',
                    'url' => $this->stringOrNullFromNode($child, 'url'),
                    'title' => $this->stringOrNullFromNode($child, 'title'),
                    'icon' => $this->stringOrNullFromNode($child, 'icon'),
                    'mobile_icon' => $this->stringOrNullFromNode($child, 'mobile_icon'),
                    'has_content' => $sectionCount > 0,
                    'section_count' => $sectionCount,
                ],
            ];
            if ($childDescription !== null) {
                $virtualItem['description'] = $childDescription;
            }
            $out[] = $virtualItem;
            $position += 10;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $explicit
     * @param list<array<string, mixed>> $auto
     *
     * @return list<array<string, mixed>>
     */
    private function mergeItemLists(array $explicit, array $auto): array
    {
        $explicitPageIds = [];
        foreach ($explicit as $item) {
            $page = $item['page'] ?? null;
            if (is_array($page) && isset($page['id']) && is_numeric($page['id'])) {
                $explicitPageIds[(int) $page['id']] = true;
            }
        }
        foreach ($auto as $item) {
            $page = $item['page'] ?? null;
            if (!is_array($page) || !isset($page['id']) || !is_numeric($page['id'])) {
                continue;
            }
            $pid = (int) $page['id'];
            if (!isset($explicitPageIds[$pid])) {
                $explicit[] = $item;
            }
        }

        usort($explicit, static fn (array $a, array $b): int => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return $explicit;
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
