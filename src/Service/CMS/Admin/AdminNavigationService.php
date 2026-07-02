<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Admin;

use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Entity\NavigationSettings;
use App\Entity\Page;
use App\Navigation\NavigationMenuChildPagesSupport;
use App\Navigation\NavigationMenuItemTranslationSupport;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\NavigationMenuItemTranslationRepository;
use App\Repository\NavigationMenuRepository;
use App\Repository\NavigationSettingsRepository;
use App\Repository\PageRepository;
use App\Service\CMS\CmsPreferenceService;
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
        private readonly NavigationSettingsRepository $navigationSettingsRepository,
        private readonly PageRepository $pageRepository,
        private readonly LookupService $lookupService,
        private readonly NavigationMenuService $navigationMenuService,
        private readonly NavigationMenuChildPagesSupport $navigationMenuChildPagesSupport,
        private readonly NavigationMenuItemTranslationRepository $navigationMenuItemTranslationRepository,
        private readonly NavigationMenuItemTranslationSupport $navigationMenuItemTranslationSupport,
        private readonly CmsPreferenceService $cmsPreferenceService,
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
        $childPageIds = $this->normalizeIntList($data['child_page_ids'] ?? []);
        $includeDescendants = (bool) ($data['include_descendants'] ?? false);

        $parentPageId = $data['page_id'] ?? $data['pageId'] ?? null;
        $parentPage = null;
        if (is_int($parentPageId) || is_numeric($parentPageId)) {
            $parentPage = $this->pageRepository->find((int) $parentPageId);
            if (!$parentPage instanceof Page) {
                $this->throwBadRequest('Page not found');
            }
        }

        $pagesToCreate = [];
        if ($parentPage instanceof Page && $childPageIds !== []) {
            $pagesToCreate = $this->navigationMenuChildPagesSupport->resolvePagesToCreate(
                $parentPage,
                $childPageIds,
                $includeDescendants,
            );
        }

        $pageIdsToAssign = [];
        if ($parentPage instanceof Page && $parentPage->getId() !== null) {
            $pageIdsToAssign[] = $parentPage->getId();
        }
        foreach ($pagesToCreate as $row) {
            $pageId = $row['page']->getId();
            if ($pageId !== null) {
                $pageIdsToAssign[] = $pageId;
            }
        }
        $this->assertNoDuplicatePagesInMenu($menu, $pageIdsToAssign);

        $itemTypeCode = is_string($data['item_type'] ?? null)
            ? (string) $data['item_type']
            : LookupService::NAVIGATION_ITEM_TYPE_PAGE;
        $this->assertTranslatableLabelsPresent($data, $itemTypeCode);

        $this->entityManager->beginTransaction();
        try {
            $parentItem = $this->buildMenuItemFromPayload($menu, $data, null);
            $this->entityManager->persist($parentItem);
            $this->entityManager->flush();

            $this->syncMenuItemTranslations($parentItem, $data);

            $createdChildren = [];
            if ($parentPage instanceof Page && $pagesToCreate !== []) {
                $createdChildren = $this->createStoredChildMenuItems($menu, $parentItem, $pagesToCreate);
                $this->entityManager->flush();
            }

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        $this->navigationMenuService->invalidateNavigationCaches();
        $this->logMenuItemChange('insert', $parentItem);

        return [
            'item' => $this->formatMenuItemEntity(
                $parentItem,
                $this->navigationMenuItemTranslationRepository->findLabelsByMenuItemIds(
                    $parentItem->getId() !== null ? [$parentItem->getId()] : [],
                ),
            ),
            'children' => array_map(
                fn (NavigationMenuItem $i): array => $this->formatMenuItemEntity($i),
                $createdChildren,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateMenuItem(int $itemId, array $data): array
    {
        $item = $this->requireMenuItem($itemId);
        $itemTypeCode = $item->getItemType()?->getLookupCode() ?? LookupService::NAVIGATION_ITEM_TYPE_PAGE;
        if (array_key_exists('item_type', $data) && is_string($data['item_type'])) {
            $itemTypeCode = $data['item_type'];
        }
        $this->assertTranslatableLabelsPresent($data, $itemTypeCode);
        $this->applyMenuItemPayload($item, $data);
        $this->syncMenuItemTranslations($item, $data);
        $this->entityManager->flush();
        $this->navigationMenuService->invalidateNavigationCaches();
        $this->logMenuItemChange('update', $item);

        return $this->formatMenuItemEntity(
            $item,
            $this->navigationMenuItemTranslationRepository->findLabelsByMenuItemIds([$itemId]),
        );
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

        if (array_key_exists('icon', $data) || array_key_exists('icon_override', $data) || array_key_exists('iconOverride', $data)) {
            $icon = $data['icon'] ?? $data['icon_override'] ?? $data['iconOverride'] ?? null;
            $item->setIcon(is_string($icon) && $icon !== '' ? $icon : null);
        }

        if (array_key_exists('mobile_icon', $data) || array_key_exists('mobileIcon', $data)) {
            $mobileIcon = $data['mobile_icon'] ?? $data['mobileIcon'] ?? null;
            $item->setMobileIcon(is_string($mobileIcon) && $mobileIcon !== '' ? $mobileIcon : null);
        }

        if (array_key_exists('label', $data)) {
            $label = $data['label'];
            $item->setLabel(is_string($label) && $label !== '' ? $label : null);
        }

        if (array_key_exists('position', $data) && is_int($data['position'])) {
            $item->setPosition($data['position']);
        } elseif ($item->getId() === null) {
            $item->setPosition(10);
        }

        $manualSource = $this->lookupService->findByTypeAndCode(
            LookupService::NAVIGATION_CHILD_SOURCES,
            LookupService::NAVIGATION_CHILD_SOURCE_MANUAL
        );
        if ($manualSource) {
            $item->setChildSource($manualSource);
        }
        $item->setAutoIncludeDepth(null);

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
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMenuDefinition(NavigationMenu $menu): array
    {
        $items = $this->navigationMenuItemRepository->findActiveByMenu($menu);
        $itemIds = array_values(array_filter(array_map(
            static fn (NavigationMenuItem $item): ?int => $item->getId(),
            $items,
        )));
        $translationMap = $this->navigationMenuItemTranslationRepository->findLabelsByMenuItemIds($itemIds);

        return [
            'key' => $menu->getMenuKey()?->getLookupCode(),
            'platform' => $menu->getPlatform()?->getLookupCode(),
            'surface' => $menu->getSurface()?->getLookupCode(),
            'preset' => $menu->getPreset()?->getLookupCode(),
            'max_depth' => $menu->getMaxDepth(),
            'item_limit' => $menu->getItemLimit(),
            'is_system' => $menu->isSystem(),
            'config' => $menu->getConfig(),
            'items' => array_map(
                fn (NavigationMenuItem $i): array => $this->formatMenuItemEntity($i, $translationMap),
                $items,
            ),
        ];
    }

    /**
     * @param array<int, array<int, string>> $translationMap
     *
     * @return array<string, mixed>
     */
    private function formatMenuItemEntity(NavigationMenuItem $item, array $translationMap = []): array
    {
        $itemId = $item->getId();
        $typeCode = $item->getItemType()?->getLookupCode() ?? LookupService::NAVIGATION_ITEM_TYPE_PAGE;
        $formatted = [
            'id' => $itemId,
            'parent_item_id' => $item->getParentItem()?->getId(),
            'item_type' => $typeCode,
            'page_id' => $item->getPage()?->getId(),
            'external_url' => $item->getExternalUrl(),
            'icon' => $item->getIcon(),
            'mobile_icon' => $item->getMobileIcon(),
            'label' => $item->getLabel(),
            'position' => $item->getPosition(),
            'is_active' => $item->isActive(),
        ];

        if ($itemId !== null && $this->navigationMenuItemTranslationSupport->isTranslatableItemType($typeCode)) {
            $formatted['translations'] = $this->navigationMenuItemTranslationSupport->formatTranslationsForAdmin(
                $itemId,
                $translationMap,
            );
        }

        return $formatted;
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

    /**
     * @param list<int> $pageIds
     */
    private function assertNoDuplicatePagesInMenu(NavigationMenu $menu, array $pageIds): void
    {
        $existingPageIds = array_fill_keys($this->navigationMenuItemRepository->findActivePageIdsForMenu($menu), true);
        foreach ($pageIds as $pageId) {
            if (isset($existingPageIds[$pageId])) {
                $page = $this->pageRepository->find($pageId);
                $label = $page instanceof Page ? ($page->getKeyword() ?? (string) $pageId) : (string) $pageId;
                $this->throwBadRequest(sprintf('Page "%s" is already in this menu.', $label));
            }
        }
    }

    /**
     * @param list<array{page: Page, parent_page_id: int}> $pagesToCreate
     *
     * @return list<NavigationMenuItem>
     */
    private function createStoredChildMenuItems(
        NavigationMenu $menu,
        NavigationMenuItem $rootParentItem,
        array $pagesToCreate,
    ): array {
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

        /** @var array<int, NavigationMenuItem> $menuItemByPageId */
        $menuItemByPageId = [];
        $rootPageId = $rootParentItem->getPage()?->getId();
        if ($rootPageId !== null) {
            $menuItemByPageId[$rootPageId] = $rootParentItem;
        }

        $created = [];
        $position = 10;
        foreach ($pagesToCreate as $row) {
            $page = $row['page'];
            $cmsParentPageId = $row['parent_page_id'];
            $menuParent = $menuItemByPageId[$cmsParentPageId] ?? $rootParentItem;

            $childItem = new NavigationMenuItem();
            $childItem->setNavigationMenu($menu);
            $childItem->setParentItem($menuParent);
            $childItem->setItemType($itemType);
            $childItem->setPage($page);
            $childItem->setPosition($position);
            $childItem->setChildSource($manualSource);
            $childItem->setIsActive(true);
            $this->entityManager->persist($childItem);
            $created[] = $childItem;

            $pageId = $page->getId();
            if ($pageId !== null) {
                $menuItemByPageId[$pageId] = $childItem;
            }
            $position += 10;
        }

        return $created;
    }

    /**
     * @return list<int>
     */
    private function normalizeIntList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_int($entry)) {
                $out[] = $entry;
            } elseif (is_numeric($entry)) {
                $out[] = (int) $entry;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertTranslatableLabelsPresent(array $data, string $typeCode): void
    {
        try {
            $this->navigationMenuItemTranslationSupport->assertTranslatableLabelsPresent(
                $data,
                $typeCode,
                $this->resolveDefaultLanguageId(),
            );
        } catch (\InvalidArgumentException $e) {
            $this->throwBadRequest($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncMenuItemTranslations(NavigationMenuItem $item, array $data): void
    {
        $this->navigationMenuItemTranslationSupport->syncMenuItemTranslations(
            $item,
            $data,
            $this->resolveDefaultLanguageId(),
        );
    }

    private function resolveDefaultLanguageId(): int
    {
        return $this->cmsPreferenceService->getDefaultLanguageId() ?? 1;
    }
}
