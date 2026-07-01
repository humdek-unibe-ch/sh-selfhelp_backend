<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Admin;

use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Entity\NavigationMenuItemExclusion;
use App\Entity\NavigationMenuItemTranslation;
use App\Entity\NavigationSettings;
use App\Entity\Page;
use App\Repository\NavigationMenuItemExclusionRepository;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\NavigationMenuItemTranslationRepository;
use App\Repository\NavigationMenuRepository;
use App\Repository\NavigationSettingsRepository;
use App\Repository\PageRepository;
use App\Service\CMS\Frontend\PageService;
use App\Service\CMS\NavigationMenuService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Admin CRUD for navigation menus, items, and global navigation settings.
 */
class AdminNavigationService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NavigationMenuRepository $navigationMenuRepository,
        private readonly NavigationMenuItemRepository $navigationMenuItemRepository,
        private readonly NavigationMenuItemTranslationRepository $navigationMenuItemTranslationRepository,
        private readonly NavigationMenuItemExclusionRepository $navigationMenuItemExclusionRepository,
        private readonly NavigationSettingsRepository $navigationSettingsRepository,
        private readonly PageRepository $pageRepository,
        private readonly LookupService $lookupService,
        private readonly NavigationMenuService $navigationMenuService,
        private readonly PageService $pageService,
        private readonly TransactionService $transactionService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdminOverview(int $languageId = 1): array
    {
        $menus = [];
        foreach ($this->listSystemMenuKeys() as $key) {
            $menu = $this->findMenuByKey($key);
            if (!$menu instanceof NavigationMenu) {
                continue;
            }
            $preview = $this->getMenuPreview($key, $languageId);
            $menus[$key] = array_merge($this->formatMenuDefinition($menu), [
                'resolved' => $preview['resolved'],
                'warnings' => $preview['warnings'],
                'suggestions' => $preview['suggestions'],
            ]);
        }

        return [
            'menus' => $menus,
            'settings' => $this->formatSettings($this->navigationSettingsRepository->getSingleton()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMenuPreview(string $menuKey, int $languageId): array
    {
        $mode = $menuKey === LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER
            || $menuKey === LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS
            ? LookupService::PAGE_ACCESS_TYPES_MOBILE
            : LookupService::PAGE_ACCESS_TYPES_WEB;

        $payload = $this->navigationMenuService->getPublicNavigationPayload($mode, $languageId);
        $menus = $payload['menus'] ?? [];
        $resolved = is_array($menus) && isset($menus[$menuKey]) && is_array($menus[$menuKey])
            ? $menus[$menuKey]
            : null;

        $diagnostics = $this->navigationMenuService->getAdminMenuDiagnostics($menuKey, $languageId);

        return [
            'menu_key' => $menuKey,
            'resolved' => $resolved,
            'warnings' => $diagnostics['warnings'],
            'suggestions' => $diagnostics['suggestions'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createMenuItem(string $menuKey, array $data): array
    {
        $menu = $this->requireMenuByKey($menuKey);
        $item = $this->buildMenuItemFromPayload($menu, $data, null);
        $this->entityManager->persist($item);
        $this->entityManager->flush();
        $this->navigationMenuService->invalidateNavigationCaches();
        $this->logMenuItemChange('insert', $item);

        return $this->formatMenuItemEntity($item);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateMenuItem(int $itemId, array $data): array
    {
        $item = $this->requireMenuItem($itemId);
        $this->applyMenuItemPayload($item, $data);
        $this->entityManager->flush();
        $this->navigationMenuService->invalidateNavigationCaches();
        $this->logMenuItemChange('update', $item);

        return $this->formatMenuItemEntity($item);
    }

    public function deleteMenuItem(int $itemId): void
    {
        $item = $this->requireMenuItem($itemId);
        $this->entityManager->remove($item);
        $this->entityManager->flush();
        $this->navigationMenuService->invalidateNavigationCaches();
        $this->logMenuItemChange('delete', $item);
    }

    /**
     * @param list<array{item_id: int, position: int, parent_item_id?: int|null}> $order
     */
    public function reorderMenuItems(string $menuKey, array $order): void
    {
        $menu = $this->requireMenuByKey($menuKey);
        $this->entityManager->beginTransaction();
        try {
            foreach ($order as $row) {
                $itemId = $row['item_id'];
                $item = $this->navigationMenuItemRepository->find($itemId);
                if (!$item instanceof NavigationMenuItem || $item->getNavigationMenu()?->getId() !== $menu->getId()) {
                    continue;
                }
                $item->setPosition($row['position']);
                $parentId = $row['parent_item_id'] ?? null;
                if ($parentId === null) {
                    $item->setParentItem(null);
                } else {
                    $parent = $this->navigationMenuItemRepository->find($parentId);
                    if ($parent instanceof NavigationMenuItem && $parent->getNavigationMenu()?->getId() === $menu->getId()) {
                        $item->setParentItem($parent);
                    }
                }
            }
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }
        $this->navigationMenuService->invalidateNavigationCaches();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateMenuDefinition(string $menuKey, array $data): array
    {
        $menu = $this->requireMenuByKey($menuKey);
        if (array_key_exists('preset', $data) && is_string($data['preset'])) {
            $preset = $this->lookupService->findByTypeAndCode(LookupService::NAVIGATION_MENU_PRESETS, $data['preset']);
            $menu->setPreset($preset);
        }
        if (array_key_exists('max_depth', $data)) {
            $menu->setMaxDepth(is_int($data['max_depth']) ? $data['max_depth'] : null);
        }
        if (array_key_exists('item_limit', $data)) {
            $menu->setItemLimit(is_int($data['item_limit']) ? $data['item_limit'] : null);
        }
        if (array_key_exists('config', $data)) {
            $config = $data['config'];
            if ($config === null) {
                $menu->setConfig(null);
            } elseif (is_array($config)) {
                /** @var array<string, mixed> $typedConfig */
                $typedConfig = $config;
                $existing = $menu->getConfig() ?? [];
                $menu->setConfig(array_merge($existing, $typedConfig));
            }
        }
        $this->entityManager->flush();
        $this->navigationMenuService->invalidateNavigationCaches();

        return $this->formatMenuDefinition($menu);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateSettings(array $data): array
    {
        $settings = $this->navigationSettingsRepository->getSingleton();
        if (!$settings instanceof NavigationSettings) {
            $this->throwNotFound('Navigation settings are not initialized');
        }

        $this->applySettingsPayload($settings, $data);
        $this->entityManager->flush();
        $this->navigationMenuService->invalidateNavigationCaches();

        return $this->formatSettings($settings);
    }

    public function addExclusion(int $menuItemId, int $pageId): void
    {
        $item = $this->requireMenuItem($menuItemId);
        $page = $this->pageRepository->find($pageId);
        if (!$page instanceof Page) {
            $this->throwNotFound('Page not found');
        }
        $exclusion = new NavigationMenuItemExclusion();
        $exclusion->setNavigationMenuItem($item);
        $exclusion->setPage($page);
        $this->entityManager->persist($exclusion);
        $this->entityManager->flush();
        $this->navigationMenuService->invalidateNavigationCaches();
    }

    public function removeExclusion(int $menuItemId, int $pageId): void
    {
        $exclusion = $this->navigationMenuItemExclusionRepository->findOneBy([
            'navigationMenuItem' => $menuItemId,
            'page' => $pageId,
        ]);
        if ($exclusion instanceof NavigationMenuItemExclusion) {
            $this->entityManager->remove($exclusion);
            $this->entityManager->flush();
            $this->navigationMenuService->invalidateNavigationCaches();
        }
    }

    /**
     * Materialize auto-included page children as explicit menu items and switch the parent to manual.
     *
     * @return list<array<string, mixed>>
     */
    public function convertAutoChildrenToExplicit(int $menuItemId, int $languageId = 1): array
    {
        $item = $this->requireMenuItem($menuItemId);
        $childSourceCode = $item->getChildSource()?->getLookupCode();
        if ($childSourceCode !== LookupService::NAVIGATION_CHILD_SOURCE_PAGE_CHILDREN) {
            $this->throwBadRequest('Menu item does not auto-include page children');
        }

        $pageId = $item->getPage()?->getId();
        if ($pageId === null) {
            $this->throwBadRequest('Menu item has no linked page');
        }

        $menuKey = $item->getNavigationMenu()?->getMenuKey()?->getLookupCode() ?? '';
        $mode = $menuKey === LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER
            || $menuKey === LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS
            ? LookupService::PAGE_ACCESS_TYPES_MOBILE
            : LookupService::PAGE_ACCESS_TYPES_WEB;

        $authoringTree = $this->pageService->getAllAccessiblePagesForUser($mode, true, $languageId);
        $parentNode = $this->findPageNodeInTree($authoringTree, (int) $pageId);
        if ($parentNode === null) {
            $this->throwNotFound('Linked page not found in accessible page tree');
        }

        $treeChildren = $parentNode['children'] ?? [];
        if (!is_array($treeChildren)) {
            $treeChildren = [];
        }

        $excluded = $this->navigationMenuItemExclusionRepository->findExcludedPageIdsForItem($item);
        $menu = $item->getNavigationMenu();
        if (!$menu instanceof NavigationMenu) {
            $this->throwNotFound('Menu item has no owning menu');
        }
        $explicitChildPageIds = [];
        foreach ($this->navigationMenuItemRepository->findActiveByMenu($menu) as $candidate) {
            if ($candidate->getParentItem()?->getId() !== $item->getId()) {
                continue;
            }
            $childPageId = $candidate->getPage()?->getId();
            if ($childPageId !== null) {
                $explicitChildPageIds[] = (int) $childPageId;
            }
        }

        $itemType = $this->lookupService->findByTypeAndCode(
            LookupService::NAVIGATION_MENU_ITEM_TYPES,
            LookupService::NAVIGATION_ITEM_TYPE_PAGE
        );
        $manualSource = $this->lookupService->findByTypeAndCode(
            LookupService::NAVIGATION_CHILD_SOURCES,
            LookupService::NAVIGATION_CHILD_SOURCE_MANUAL
        );
        if (!$itemType || !$manualSource) {
            $this->throwNotFound('Navigation lookup configuration is incomplete');
        }

        $created = [];
        $position = 10;
        $this->entityManager->beginTransaction();
        try {
            foreach ($treeChildren as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $childPageId = isset($child['id_pages']) && is_numeric($child['id_pages'])
                    ? (int) $child['id_pages']
                    : (isset($child['id']) && is_numeric($child['id']) ? (int) $child['id'] : 0);
                if ($childPageId <= 0 || in_array($childPageId, $excluded, true) || in_array($childPageId, $explicitChildPageIds, true)) {
                    continue;
                }

                $childPage = $this->pageRepository->find($childPageId);
                if (!$childPage instanceof Page) {
                    continue;
                }

                $childItem = new NavigationMenuItem();
                $childItem->setNavigationMenu($menu);
                $childItem->setParentItem($item);
                $childItem->setItemType($itemType);
                $childItem->setPage($childPage);
                $childItem->setPosition($position);
                $childItem->setChildSource($manualSource);
                $childItem->setIsActive(true);
                $this->entityManager->persist($childItem);
                $created[] = $childItem;
                $position += 10;
            }

            $item->setChildSource($manualSource);
            $item->setAutoIncludeDepth(null);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        $this->navigationMenuService->invalidateNavigationCaches();
        $this->logMenuItemChange('update', $item);

        return array_map(fn (NavigationMenuItem $i): array => $this->formatMenuItemEntity($i), $created);
    }

    /**
     * @param array<int|string, mixed> $tree
     *
     * @return array<string, mixed>|null
     */
    private function findPageNodeInTree(array $tree, int $pageId): ?array
    {
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            /** @var array<string, mixed> $pageNode */
            $pageNode = $node;
            $nodeId = isset($pageNode['id_pages']) && is_numeric($pageNode['id_pages'])
                ? (int) $pageNode['id_pages']
                : (isset($pageNode['id']) && is_numeric($pageNode['id']) ? (int) $pageNode['id'] : 0);
            if ($nodeId === $pageId) {
                return $pageNode;
            }
            $children = $pageNode['children'] ?? [];
            if (is_array($children) && $children !== []) {
                /** @var list<array<string, mixed>> $childList */
                $childList = array_values(array_filter($children, 'is_array'));
                $found = $this->findPageNodeInTree($childList, $pageId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function listSystemMenuKeys(): array
    {
        return [
            LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER,
            LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER,
            LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS,
        ];
    }

    private function findMenuByKey(string $menuKey): ?NavigationMenu
    {
        $lookupId = $this->lookupService->getLookupIdByCode(LookupService::NAVIGATION_MENU_KEYS, $menuKey);

        return $this->navigationMenuRepository->findByMenuKeyLookupId((int) $lookupId);
    }

    private function requireMenuByKey(string $menuKey): NavigationMenu
    {
        $menu = $this->findMenuByKey($menuKey);
        if (!$menu instanceof NavigationMenu) {
            $this->throwNotFound("Navigation menu '{$menuKey}' not found");
        }

        return $menu;
    }

    private function requireMenuItem(int $itemId): NavigationMenuItem
    {
        $item = $this->navigationMenuItemRepository->find($itemId);
        if (!$item instanceof NavigationMenuItem) {
            $this->throwNotFound('Navigation menu item not found');
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildMenuItemFromPayload(NavigationMenu $menu, array $data, ?NavigationMenuItem $existing): NavigationMenuItem
    {
        $item = $existing ?? new NavigationMenuItem();
        $item->setNavigationMenu($menu);
        $this->applyMenuItemPayload($item, $data);

        return $item;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyMenuItemPayload(NavigationMenuItem $item, array $data): void
    {
        if (array_key_exists('item_type', $data) && is_string($data['item_type'])) {
            $type = $this->lookupService->findByTypeAndCode(LookupService::NAVIGATION_MENU_ITEM_TYPES, $data['item_type']);
            if ($type) {
                $item->setItemType($type);
            }
        } elseif ($item->getItemType() === null) {
            $item->setItemType($this->lookupService->findByTypeAndCode(
                LookupService::NAVIGATION_MENU_ITEM_TYPES,
                LookupService::NAVIGATION_ITEM_TYPE_PAGE
            ));
        }

        if (array_key_exists('page_id', $data) || array_key_exists('pageId', $data)) {
            $pageId = $data['page_id'] ?? $data['pageId'] ?? null;
            if (is_int($pageId) || is_numeric($pageId)) {
                $page = $this->pageRepository->find((int) $pageId);
                $item->setPage($page);
            } elseif ($pageId === null) {
                $item->setPage(null);
            }
        }

        if (array_key_exists('external_url', $data) && is_string($data['external_url'])) {
            $item->setExternalUrl($data['external_url']);
        }

        if (array_key_exists('icon_override', $data) || array_key_exists('iconOverride', $data)) {
            $icon = $data['icon_override'] ?? $data['iconOverride'] ?? null;
            $item->setIconOverride(is_string($icon) && $icon !== '' ? $icon : null);
        }

        if (array_key_exists('position', $data) && is_int($data['position'])) {
            $item->setPosition($data['position']);
        } elseif ($item->getId() === null) {
            $item->setPosition(10);
        }

        if (array_key_exists('child_source', $data) || array_key_exists('childSource', $data)) {
            $code = $data['child_source'] ?? $data['childSource'] ?? LookupService::NAVIGATION_CHILD_SOURCE_MANUAL;
            if (is_string($code)) {
                $childSource = $this->lookupService->findByTypeAndCode(LookupService::NAVIGATION_CHILD_SOURCES, $code);
                if ($childSource) {
                    $item->setChildSource($childSource);
                }
            }
        } elseif ($item->getChildSource() === null) {
            $item->setChildSource($this->lookupService->findByTypeAndCode(
                LookupService::NAVIGATION_CHILD_SOURCES,
                LookupService::NAVIGATION_CHILD_SOURCE_MANUAL
            ));
        }

        if (array_key_exists('auto_include_depth', $data) || array_key_exists('autoIncludeDepth', $data)) {
            $depth = $data['auto_include_depth'] ?? $data['autoIncludeDepth'] ?? null;
            $item->setAutoIncludeDepth(is_int($depth) ? $depth : (is_numeric($depth) ? (int) $depth : null));
        }

        if (array_key_exists('is_active', $data) || array_key_exists('isActive', $data)) {
            $item->setIsActive((bool) ($data['is_active'] ?? $data['isActive'] ?? true));
        }

        $parentId = $data['parent_item_id'] ?? $data['parentItemId'] ?? null;
        if ($parentId === null) {
            if (array_key_exists('parent_item_id', $data) || array_key_exists('parentItemId', $data)) {
                $item->setParentItem(null);
            }
        } elseif (is_int($parentId) || is_numeric($parentId)) {
            $parent = $this->navigationMenuItemRepository->find((int) $parentId);
            if ($parent instanceof NavigationMenuItem) {
                $item->setParentItem($parent);
            }
        }

        $translations = $data['translations'] ?? null;
        if (is_array($translations)) {
            /** @var list<array<string, mixed>> $translationRows */
            $translationRows = array_values($translations);
            $this->syncTranslations($item, $translationRows);
        }
    }

    /**
     * @param list<array<string, mixed>> $translations
     */
    private function syncTranslations(NavigationMenuItem $item, array $translations): void
    {
        foreach ($translations as $row) {
            $languageId = $row['language_id'] ?? $row['languageId'] ?? null;
            if (!is_int($languageId) && !is_numeric($languageId)) {
                continue;
            }
            $languageId = (int) $languageId;
            $existing = $this->navigationMenuItemTranslationRepository->findOneBy([
                'navigationMenuItem' => $item,
                'language' => $languageId,
            ]);
            $translation = $existing ?? new NavigationMenuItemTranslation();
            $translation->setNavigationMenuItem($item);
            $translation->setLanguage($this->entityManager->getReference(\App\Entity\Language::class, $languageId));
            if (array_key_exists('label', $row)) {
                $translation->setLabel(is_string($row['label']) ? $row['label'] : null);
            }
            if (array_key_exists('description', $row)) {
                $translation->setDescription(is_string($row['description']) ? $row['description'] : null);
            }
            if (array_key_exists('aria_label', $row) || array_key_exists('ariaLabel', $row)) {
                $aria = $row['aria_label'] ?? $row['ariaLabel'] ?? null;
                $translation->setAriaLabel(is_string($aria) ? $aria : null);
            }
            $this->entityManager->persist($translation);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMenuDefinition(NavigationMenu $menu): array
    {
        $items = $this->navigationMenuItemRepository->findActiveByMenu($menu);

        return [
            'key' => $menu->getMenuKey()?->getLookupCode(),
            'platform' => $menu->getPlatform()?->getLookupCode(),
            'surface' => $menu->getSurface()?->getLookupCode(),
            'preset' => $menu->getPreset()?->getLookupCode(),
            'max_depth' => $menu->getMaxDepth(),
            'item_limit' => $menu->getItemLimit(),
            'is_system' => $menu->isSystem(),
            'config' => $menu->getConfig(),
            'items' => array_map(fn (NavigationMenuItem $i): array => $this->formatMenuItemEntity($i), $items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMenuItemEntity(NavigationMenuItem $item): array
    {
        $excludedPageIds = $this->navigationMenuItemExclusionRepository->findExcludedPageIdsForItem($item);

        return [
            'id' => $item->getId(),
            'parent_item_id' => $item->getParentItem()?->getId(),
            'item_type' => $item->getItemType()?->getLookupCode(),
            'page_id' => $item->getPage()?->getId(),
            'external_url' => $item->getExternalUrl(),
            'icon_override' => $item->getIconOverride(),
            'position' => $item->getPosition(),
            'child_source' => $item->getChildSource()?->getLookupCode(),
            'auto_include_depth' => $item->getAutoIncludeDepth(),
            'is_active' => $item->isActive(),
            'excluded_page_ids' => $excludedPageIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSettings(?NavigationSettings $settings): array
    {
        if ($settings === null) {
            return [];
        }

        return [
            'web_header_search_mode' => $settings->getWebHeaderSearchMode()?->getLookupCode(),
            'web_header_search_min_chars' => $settings->getWebHeaderSearchMinChars(),
            'web_header_search_result_limit' => $settings->getWebHeaderSearchResultLimit(),
            'search_default_visibility' => $settings->getSearchDefaultVisibility()?->getLookupCode(),
            'search_field_policy' => $settings->getSearchFieldPolicy()?->getLookupCode(),
            'web_guest_start_page_id' => $settings->getWebGuestStartPage()?->getId(),
            'web_user_start_page_id' => $settings->getWebUserStartPage()?->getId(),
            'web_user_start_mode' => $settings->getWebUserStartMode()?->getLookupCode(),
            'mobile_guest_start_page_id' => $settings->getMobileGuestStartPage()?->getId(),
            'mobile_user_start_page_id' => $settings->getMobileUserStartPage()?->getId(),
            'mobile_user_start_mode' => $settings->getMobileUserStartMode()?->getLookupCode(),
            'mobile_start_page_source' => $settings->getMobileStartPageSource()?->getLookupCode(),
            'route_sync_old_route_policy' => $settings->getRouteSyncOldRoutePolicy()?->getLookupCode(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applySettingsPayload(NavigationSettings $settings, array $data): void
    {
        $lookupMap = [
            'web_header_search_mode' => [LookupService::NAVIGATION_SEARCH_MODES, 'setWebHeaderSearchMode'],
            'search_default_visibility' => [LookupService::NAVIGATION_SEARCH_VISIBILITY, 'setSearchDefaultVisibility'],
            'search_field_policy' => [LookupService::NAVIGATION_SEARCH_FIELD_POLICIES, 'setSearchFieldPolicy'],
            'web_user_start_mode' => [LookupService::NAVIGATION_START_MODES, 'setWebUserStartMode'],
            'mobile_user_start_mode' => [LookupService::NAVIGATION_START_MODES, 'setMobileUserStartMode'],
            'mobile_start_page_source' => [LookupService::NAVIGATION_MOBILE_START_SOURCES, 'setMobileStartPageSource'],
            'route_sync_old_route_policy' => [LookupService::NAVIGATION_ROUTE_SYNC_POLICIES, 'setRouteSyncOldRoutePolicy'],
        ];

        foreach ($lookupMap as $field => [$type, $setter]) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $lookup = $this->lookupService->findByTypeAndCode($type, $data[$field]);
                $settings->{$setter}($lookup);
            }
        }

        if (array_key_exists('web_header_search_min_chars', $data) && is_int($data['web_header_search_min_chars'])) {
            $settings->setWebHeaderSearchMinChars($data['web_header_search_min_chars']);
        }
        if (array_key_exists('web_header_search_result_limit', $data) && is_int($data['web_header_search_result_limit'])) {
            $settings->setWebHeaderSearchResultLimit($data['web_header_search_result_limit']);
        }

        $pageMap = [
            'web_guest_start_page_id' => 'setWebGuestStartPage',
            'web_user_start_page_id' => 'setWebUserStartPage',
            'mobile_guest_start_page_id' => 'setMobileGuestStartPage',
            'mobile_user_start_page_id' => 'setMobileUserStartPage',
        ];
        foreach ($pageMap as $field => $setter) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $pageId = $data[$field];
            if ($pageId === null) {
                $settings->{$setter}(null);
            } elseif (is_int($pageId) || is_numeric($pageId)) {
                $settings->{$setter}($this->pageRepository->find((int) $pageId));
            }
        }
    }

    private function logMenuItemChange(string $verb, NavigationMenuItem $item): void
    {
        $this->transactionService->logTransaction(
            $verb === 'insert' ? LookupService::TRANSACTION_TYPES_INSERT : ($verb === 'delete' ? LookupService::TRANSACTION_TYPES_DELETE : LookupService::TRANSACTION_TYPES_UPDATE),
            LookupService::TRANSACTION_BY_BY_USER,
            'navigation_menu_items',
            $item->getId(),
            true,
            "Navigation menu item {$verb}"
        );
    }
}
