<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Admin;

use App\Entity\Language;
use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Entity\NavigationMenuItemTranslation;
use App\Entity\NavigationSettings;
use App\Entity\Page;
use App\Exception\ServiceException;
use App\Navigation\NavigationHeaderLayerSupport;
use App\Navigation\NavigationMenuDepthSupport;
use App\Navigation\NavigationMenuItemTranslationSupport;
use App\Repository\LanguageRepository;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\NavigationMenuItemTranslationRepository;
use App\Repository\NavigationMenuRepository;
use App\Repository\NavigationSettingsRepository;
use App\Repository\PageRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\NavigationMenuService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use App\Service\System\SystemInstanceService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portable navigation bundle export/import ({@see self::BUNDLE_FORMAT} v2.0).
 *
 * Page bundles remain content-only; navigation membership travels in a separate
 * bundle that references pages by keyword and rebuilds menu trees with stable
 * {@code ref}/{@code parent_ref} links. v2.0 is the only supported version:
 * menus carry `preset`/`max_depth`/`item_limit` (no `config`), header root
 * items may carry `layer: "top"`, and validation is strict.
 */
class NavigationExportImportService extends BaseService
{
    public const BUNDLE_FORMAT = 'selfhelp/navigation-bundle';
    public const BUNDLE_VERSION = '2.0';
    public const MIN_CORE_VERSION = '0.1.33';

    public const EXPORT_MODE_FULL_SNAPSHOT = 'full_snapshot';
    public const EXPORT_MODE_BRANCH = 'branch';

    public const MISSING_PAGES_STRICT = 'strict';
    public const MISSING_PAGES_SKIP = 'skip_missing';
    public const MISSING_PAGES_STUBS = 'create_stubs';

    public const POLICY_REPLACE = 'replace';
    public const POLICY_MERGE = 'merge';
    public const POLICY_APPEND = 'append';

    private const ISSUE_ERROR = 'error';
    private const ISSUE_WARNING = 'warning';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly NavigationMenuRepository $navigationMenuRepository,
        private readonly NavigationMenuItemRepository $navigationMenuItemRepository,
        private readonly NavigationMenuItemTranslationRepository $navigationMenuItemTranslationRepository,
        private readonly NavigationSettingsRepository $navigationSettingsRepository,
        private readonly PageRepository $pageRepository,
        private readonly LanguageRepository $languageRepository,
        private readonly LookupService $lookupService,
        private readonly NavigationMenuService $navigationMenuService,
        private readonly NavigationMenuItemTranslationSupport $translationSupport,
        private readonly NavigationMenuDepthSupport $depthSupport,
        private readonly NavigationHeaderLayerSupport $headerLayerSupport,
        private readonly PageExportImportService $pageExportImportService,
        private readonly AdminPageService $adminPageService,
        private readonly TransactionService $transactionService,
        private readonly SystemInstanceService $instance,
        private readonly CacheService $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function exportBundle(array $options = []): array
    {
        $mode = $this->asString($options['mode'] ?? self::EXPORT_MODE_FULL_SNAPSHOT);
        $menuKeys = $this->resolveMenuKeys($options['menu_keys'] ?? null);
        $includePages = (bool) ($options['include_pages'] ?? false);
        $includeSettings = (bool) ($options['include_settings'] ?? false);
        $seedKeywords = $this->stringList($options['page_keywords'] ?? null);
        $seedPageIds = $this->intList($options['page_ids'] ?? null);

        if ($mode === self::EXPORT_MODE_BRANCH && $seedKeywords === [] && $seedPageIds === []) {
            $this->throwBadRequest('Branch export requires page_keywords or page_ids.');
        }

        $menus = [];
        $referencedPageIds = [];
        foreach ($menuKeys as $menuKey) {
            $menu = $this->requireMenuByKey($menuKey);
            $items = $this->navigationMenuItemRepository->findActiveByMenu($menu);
            if ($mode === self::EXPORT_MODE_BRANCH) {
                $items = $this->filterBranchItems($items, $seedKeywords, $seedPageIds);
            }

            $exported = $this->exportMenuItems($menuKey, $items);
            $menus[$menuKey] = array_merge($this->exportMenuConfig($menu), [
                'items' => $exported['items'],
            ]);
            foreach ($exported['page_ids'] as $pageId) {
                $referencedPageIds[$pageId] = true;
            }
        }

        $bundle = [
            'format' => self::BUNDLE_FORMAT,
            'version' => self::BUNDLE_VERSION,
            'min_core_version' => self::MIN_CORE_VERSION,
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'core_version' => $this->instance->getCmsVersion(),
            'export_mode' => $mode,
            'import_hints' => [
                'default_keyword_prefix' => $this->asString($options['default_keyword_prefix'] ?? ''),
            ],
            'menus' => $menus,
        ];

        if ($includeSettings) {
            $bundle['settings'] = $this->exportSettings($this->navigationSettingsRepository->getSingleton());
        }

        if ($includePages && $referencedPageIds !== []) {
            // The bundle must be self-contained: menu-referenced pages may hang
            // under structural parent pages that are not linked from any menu
            // item (e.g. a "legal" holder). Embed the ancestor chain too,
            // otherwise re-importing the export fails with missing_parent.
            foreach (array_keys($referencedPageIds) as $pageId) {
                $parent = $this->pageRepository->find($pageId)?->getParentPage();
                while ($parent !== null) {
                    $parentId = $parent->getId();
                    if ($parentId === null || isset($referencedPageIds[$parentId])) {
                        break;
                    }
                    $referencedPageIds[$parentId] = true;
                    $parent = $parent->getParentPage();
                }
            }

            $bundle['pages'] = $this->pageExportImportService->exportBundle(
                array_keys($referencedPageIds),
            )['pages'] ?? [];
        }

        return $bundle;
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $options
     *
     * @return array{valid: bool, issues: list<array{level: string, code: string, message: string, menu_key: ?string}>}
     */
    public function validateImport(array $bundle, array $options = []): array
    {
        $issues = [];
        $this->validateBundleEnvelope($bundle, $issues);

        $keywordPrefix = $this->importHintsPrefix($options, $bundle);
        $missingMode = $this->asString($options['missing_pages_mode'] ?? self::MISSING_PAGES_STRICT);
        $menuPolicies = is_array($options['menu_policies'] ?? null) ? $options['menu_policies'] : [];

        $embeddedPages = $this->bundlePages($bundle);
        $keywordToExists = $this->buildKeywordExistenceMap($embeddedPages, $keywordPrefix);

        $menus = $this->bundleMenus($bundle);
        foreach ($menus as $menuKey => $menuPayload) {
            $this->validateMenuPayload($menuKey, $menuPayload, $keywordPrefix, $missingMode, $keywordToExists, $issues);
            $policy = $this->asString($menuPolicies[$menuKey] ?? self::POLICY_MERGE);
            if (!in_array($policy, [self::POLICY_REPLACE, self::POLICY_MERGE, self::POLICY_APPEND], true)) {
                $issues[] = $this->issue(self::ISSUE_ERROR, 'invalid_menu_policy', sprintf('Unknown import policy "%s" for menu "%s".', $policy, $menuKey), $menuKey);
            }
        }

        $this->validateEmbeddedPages($bundle, $options, $issues);

        $hasError = false;
        foreach ($issues as $issue) {
            if ($issue['level'] === self::ISSUE_ERROR) {
                $hasError = true;
                break;
            }
        }

        return ['valid' => !$hasError, 'issues' => $issues];
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $options
     *
     * @return array{imported_menus: list<string>, created_items: int, skipped_items: int, imported_pages: list<array{keyword: string, page_id: int}>}
     */
    public function importBundle(array $bundle, array $options = []): array
    {
        $validation = $this->validateImport($bundle, $options);
        if (!$validation['valid']) {
            throw new ServiceException(
                'Navigation import validation failed.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['issues' => $validation['issues']],
            );
        }

        $keywordPrefix = $this->importHintsPrefix($options, $bundle);
        $missingMode = $this->asString($options['missing_pages_mode'] ?? self::MISSING_PAGES_STRICT);
        $menuPolicies = is_array($options['menu_policies'] ?? null) ? $options['menu_policies'] : [];
        $importSettings = (bool) ($options['import_settings'] ?? false);

        $connection = $this->entityManager->getConnection();
        $previousSavepoints = $connection->getNestTransactionsWithSavepoints();
        $connection->setNestTransactionsWithSavepoints(true);

        $importedPages = [];
        $createdItems = 0;
        $skippedItems = 0;
        $importedMenus = [];

        $this->entityManager->beginTransaction();
        try {
            $embeddedPages = $this->bundlePages($bundle);
            if ($embeddedPages !== []) {
                $pageBundle = [
                    'format' => PageExportImportService::BUNDLE_FORMAT,
                    'version' => PageExportImportService::BUNDLE_VERSION,
                    'pages' => $embeddedPages,
                ];
                $pageResult = $this->pageExportImportService->importBundle(
                    $pageBundle,
                    $this->pageImportOptionsFromNavigation($options, $bundle),
                );
                $importedPages = $pageResult['created'];
            }

            if ($importSettings) {
                $settingsPayload = $bundle['settings'] ?? null;
                if (is_array($settingsPayload)) {
                    $settings = $this->navigationSettingsRepository->getSingleton();
                    if ($settings instanceof NavigationSettings) {
                        /** @var array<string, mixed> $settingsPayload */
                        $this->applySettingsImport($settings, $settingsPayload, $keywordPrefix);
                    }
                }
            }

            $menus = $this->bundleMenus($bundle);
            foreach ($menus as $menuKey => $menuPayload) {
                $menu = $this->requireMenuByKey($menuKey);
                $policy = $this->asString($menuPolicies[$menuKey] ?? self::POLICY_MERGE);
                $result = $this->importMenu($menu, $menuKey, $menuPayload, $policy, $keywordPrefix, $missingMode);
                $createdItems += $result['created'];
                $skippedItems += $result['skipped'];
                $importedMenus[] = $menuKey;
                $this->applyMenuConfigImport($menu, $menuKey, $menuPayload);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                try {
                    $this->entityManager->rollback();
                } catch (\Throwable) {
                    // Nested page/section services may have already closed the EM
                    // on a DB error; the outer rollback is best-effort only.
                }
            }

            // Inner page/section services may have bumped caches before the outer
            // transaction rolled back — refresh the affected caches so clients do
            // not briefly see pages that were never committed.
            $this->invalidateImportAffectedCaches();

            if (!$this->entityManager->isOpen()) {
                $this->managerRegistry->resetManager();
            }

            throw $e instanceof ServiceException ? $e : new ServiceException(
                'Navigation import failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous_exception' => $e->getMessage()],
            );
        } finally {
            $connection->setNestTransactionsWithSavepoints($previousSavepoints);
        }

        // Invalidate AFTER the commit. The nested createPage/addGroupAcl calls
        // already invalidated these categories, but that happened inside the
        // still-open transaction — any concurrent request could re-cache the
        // pre-import pages/ACL lists before the data became visible, leaving
        // imported pages hidden ("not in navigation") and seemingly without
        // ACL access until the TTL expired.
        $this->invalidateImportAffectedCaches();

        return [
            'imported_menus' => $importedMenus,
            'created_items' => $createdItems,
            'skipped_items' => $skippedItems,
            'imported_pages' => $importedPages,
        ];
    }

    /**
     * Refresh every cache family a bundle import can touch: navigation payloads,
     * page lists (admin + per-user accessible pages), and permission lists
     * (per-user ACL snapshots for embedded pages created with ACL rows).
     */
    private function invalidateImportAffectedCaches(): void
    {
        $this->navigationMenuService->invalidateNavigationCaches();
        $this->cache
            ->withCategory(CacheService::CATEGORY_PAGES)
            ->invalidateAllListsInCategory();
        $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->invalidateAllListsInCategory();
    }

    /**
     * @param list<NavigationMenuItem> $items
     * @param list<string> $seedKeywords
     * @param list<int> $seedPageIds
     *
     * @return list<NavigationMenuItem>
     */
    private function filterBranchItems(array $items, array $seedKeywords, array $seedPageIds): array
    {
        $seedKeywordSet = array_fill_keys($seedKeywords, true);
        $seedPageIdSet = array_fill_keys($seedPageIds, true);
        foreach ($seedKeywords as $keyword) {
            $page = $this->pageRepository->findOneBy(['keyword' => $keyword]);
            if ($page instanceof Page && $page->getId() !== null) {
                $seedPageIdSet[$page->getId()] = true;
            }
        }

        /** @var array<int, NavigationMenuItem> $byId */
        $byId = [];
        foreach ($items as $item) {
            $id = $item->getId();
            if ($id !== null) {
                $byId[$id] = $item;
            }
        }

        $selected = [];
        foreach ($items as $item) {
            if (!$this->itemMatchesSeed($item, $seedKeywordSet, $seedPageIdSet)) {
                continue;
            }
            $this->collectItemWithAncestorsAndSiblings($item, $byId, $selected);
        }

        return array_values($selected);
    }

    /**
     * @param array<string, true> $seedKeywordSet
     * @param array<int, true> $seedPageIdSet
     */
    private function itemMatchesSeed(NavigationMenuItem $item, array $seedKeywordSet, array $seedPageIdSet): bool
    {
        $page = $item->getPage();
        if ($page instanceof Page) {
            $keyword = $page->getKeyword() ?? '';
            $pageId = $page->getId();
            if ($keyword !== '' && isset($seedKeywordSet[$keyword])) {
                return true;
            }
            if ($pageId !== null && isset($seedPageIdSet[$pageId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, NavigationMenuItem> $byId
     * @param array<int, NavigationMenuItem> $selected
     */
    private function collectItemWithAncestorsAndSiblings(
        NavigationMenuItem $item,
        array $byId,
        array &$selected,
    ): void {
        $current = $item;
        while ($current instanceof NavigationMenuItem) {
            $id = $current->getId();
            if ($id !== null) {
                $selected[$id] = $current;
            }
            $current = $current->getParentItem();
        }

        $parent = $item->getParentItem();
        $parentId = $parent?->getId();
        foreach ($byId as $candidate) {
            $candidateParentId = $candidate->getParentItem()?->getId();
            if ($parentId === null && $candidateParentId === null) {
                continue;
            }
            if ($parentId !== null && $candidateParentId === $parentId) {
                $candidateId = $candidate->getId();
                if ($candidateId !== null) {
                    $selected[$candidateId] = $candidate;
                }
            }
        }
    }

    /**
     * @param list<NavigationMenuItem> $items
     *
     * @return array{items: list<array<string, mixed>>, page_ids: list<int>}
     */
    private function exportMenuItems(string $menuKey, array $items): array
    {
        $itemIds = array_values(array_filter(array_map(
            static fn (NavigationMenuItem $item): ?int => $item->getId(),
            $items,
        )));
        $translationMap = $this->navigationMenuItemTranslationRepository->findPortableTranslationsByMenuItemIds($itemIds);

        /** @var array<int, string> $idToRef */
        $idToRef = [];
        $refCounter = 0;
        foreach ($items as $item) {
            $id = $item->getId();
            if ($id === null) {
                continue;
            }
            $idToRef[$id] = sprintf('%s-%04d', $menuKey, ++$refCounter);
        }

        $exportedItems = [];
        $pageIds = [];
        foreach ($items as $item) {
            $itemId = $item->getId();
            if ($itemId === null) {
                continue;
            }
            $ref = $idToRef[$itemId];
            $parentId = $item->getParentItem()?->getId();
            $parentRef = $parentId !== null && isset($idToRef[$parentId]) ? $idToRef[$parentId] : null;

            $typeCode = $item->getItemType()?->getLookupCode() ?? LookupService::NAVIGATION_ITEM_TYPE_PAGE;
            $row = [
                'ref' => $ref,
                'parent_ref' => $parentRef,
                'item_type' => $typeCode,
                'position' => $item->getPosition(),
                'layer' => $item->getLayer(),
                'children_nav' => $item->getChildrenNav()?->getLookupCode(),
                'show_pager' => $item->getShowPager(),
                'icon' => $item->getIcon(),
                'mobile_icon' => $item->getMobileIcon(),
                'label' => $item->getLabel(),
                'external_url' => $item->getExternalUrl(),
                'is_active' => $item->isActive(),
                'translations' => $this->exportItemTranslations($translationMap[$itemId] ?? []),
            ];

            $page = $item->getPage();
            if ($page instanceof Page) {
                $row['page_keyword'] = $page->getKeyword();
                $pageId = $page->getId();
                if ($pageId !== null) {
                    $pageIds[$pageId] = $pageId;
                }
            }

            $exportedItems[] = $row;
        }

        usort($exportedItems, static fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);

        return ['items' => $exportedItems, 'page_ids' => array_values($pageIds)];
    }

    /**
     * @param list<array{language_id: int, locale: string, label: ?string, description: ?string, aria_label: ?string}> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function exportItemTranslations(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $entry = [
                'locale' => $row['locale'],
                'label' => $row['label'],
            ];
            if ($row['description'] !== null && $row['description'] !== '') {
                $entry['description'] = $row['description'];
            }
            if ($row['aria_label'] !== null && $row['aria_label'] !== '') {
                $entry['aria_label'] = $row['aria_label'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportMenuConfig(NavigationMenu $menu): array
    {
        $config = [
            'preset' => $menu->getPreset()?->getLookupCode(),
            'max_depth' => $this->depthSupport->normalizeMenuMaxDepth($menu->getMaxDepth()),
            'item_limit' => $menu->getItemLimit(),
        ];

        if ($menu->getPlatform()?->getLookupCode() === 'web') {
            $config['children_nav'] = $menu->getChildrenNav()?->getLookupCode();
            $config['show_breadcrumbs'] = $menu->isShowBreadcrumbs();
            $config['show_pager'] = $menu->isShowPager();
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportSettings(?NavigationSettings $settings): array
    {
        if (!$settings instanceof NavigationSettings) {
            return [];
        }

        return [
            'web_header_search_mode' => $settings->getWebHeaderSearchMode()?->getLookupCode(),
            'web_header_search_min_chars' => $settings->getWebHeaderSearchMinChars(),
            'web_header_search_result_limit' => $settings->getWebHeaderSearchResultLimit(),
            'search_default_visibility' => $settings->getSearchDefaultVisibility()?->getLookupCode(),
            'search_field_policy' => $settings->getSearchFieldPolicy()?->getLookupCode(),
            'web_guest_start_page_keyword' => $settings->getWebGuestStartPage()?->getKeyword(),
            'web_user_start_page_keyword' => $settings->getWebUserStartPage()?->getKeyword(),
            'web_user_start_mode' => $settings->getWebUserStartMode()?->getLookupCode(),
            'mobile_guest_start_page_keyword' => $settings->getMobileGuestStartPage()?->getKeyword(),
            'mobile_user_start_page_keyword' => $settings->getMobileUserStartPage()?->getKeyword(),
            'mobile_user_start_mode' => $settings->getMobileUserStartMode()?->getLookupCode(),
            'mobile_start_page_source' => $settings->getMobileStartPageSource()?->getLookupCode(),
            'route_sync_old_route_policy' => $settings->getRouteSyncOldRoutePolicy()?->getLookupCode(),
        ];
    }

    /**
     * @param array<string, mixed> $menuPayload
     *
     * @return array{created: int, skipped: int}
     */
    private function importMenu(
        NavigationMenu $menu,
        string $menuKey,
        array $menuPayload,
        string $policy,
        string $keywordPrefix,
        string $missingMode,
    ): array {
        if ($policy === self::POLICY_REPLACE) {
            $this->deleteAllMenuItems($menu);
        }

        $rawItems = $menuPayload['items'] ?? null;
        if (!is_array($rawItems)) {
            $rawItems = [];
        }
        /** @var list<array<string, mixed>> $items */
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            /** @var array<string, mixed> $assoc */
            $assoc = [];
            foreach ($rawItem as $key => $value) {
                $assoc[(string) $key] = $value;
            }
            $items[] = $assoc;
        }
        /** @var array<string, NavigationMenuItem> $refToEntity */
        $refToEntity = [];
        $created = 0;
        $skipped = 0;

        // Create parents before their children regardless of position values:
        // sort by real ref-chain depth first (root=0, child=1, grandchild=2, …),
        // then by position. Sorting only by "has parent_ref" broke three-level
        // menus — a grandchild with a lower position than its parent was created
        // before the parent existed and silently became a root item.
        /** @var array<string, array<string, mixed>> $rowsByRef */
        $rowsByRef = [];
        foreach ($items as $row) {
            $ref = $this->asString($row['ref'] ?? '');
            if ($ref !== '') {
                $rowsByRef[$ref] = $row;
            }
        }
        /** @var list<array{0: int, 1: int, 2: int, 3: array<string, mixed>}> $sortable */
        $sortable = [];
        foreach ($items as $index => $row) {
            $depth = 0;
            $parentRef = $row['parent_ref'] ?? null;
            while (is_string($parentRef) && $parentRef !== '' && isset($rowsByRef[$parentRef]) && $depth < 10) {
                ++$depth;
                $parentRef = $rowsByRef[$parentRef]['parent_ref'] ?? null;
            }
            $sortable[] = [$depth, $this->itemPosition($row), $index, $row];
        }
        usort($sortable, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $items = array_map(static fn (array $tuple): array => $tuple[3], $sortable);

        foreach ($items as $typedRow) {
            $importResult = $this->importMenuItemRow($menu, $menuKey, $typedRow, $policy, $keywordPrefix, $missingMode, $refToEntity);
            if ($importResult === null) {
                ++$skipped;
                continue;
            }
            $ref = $this->asString($typedRow['ref'] ?? '');
            if ($ref !== '') {
                $refToEntity[$ref] = $importResult;
            }
            ++$created;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, NavigationMenuItem> $refToEntity
     */
    private function importMenuItemRow(
        NavigationMenu $menu,
        string $menuKey,
        array $row,
        string $policy,
        string $keywordPrefix,
        string $missingMode,
        array &$refToEntity,
    ): ?NavigationMenuItem {
        $itemType = $this->asString($row['item_type'] ?? LookupService::NAVIGATION_ITEM_TYPE_PAGE);
        $pageKeyword = $this->prefixKeyword($keywordPrefix, $this->asString($row['page_keyword'] ?? ''));
        $parentRef = $row['parent_ref'] ?? null;
        $parent = null;
        if (is_string($parentRef) && $parentRef !== '' && isset($refToEntity[$parentRef])) {
            $parent = $refToEntity[$parentRef];
            $this->depthSupport->assertDepthAllowed($parent);
        }

        $page = null;
        if ($itemType === LookupService::NAVIGATION_ITEM_TYPE_PAGE) {
            if ($pageKeyword === '') {
                return null;
            }
            $page = $this->pageRepository->findOneBy(['keyword' => $pageKeyword]);
            if (!$page instanceof Page) {
                if ($missingMode === self::MISSING_PAGES_SKIP) {
                    return null;
                }
                if ($missingMode === self::MISSING_PAGES_STUBS) {
                    $page = $this->createStubPage($pageKeyword);
                } else {
                    $this->throwBadRequest(sprintf('Page "%s" required by menu "%s" is not installed.', $pageKeyword, $menuKey));
                }
            }

            if ($policy === self::POLICY_APPEND || $policy === self::POLICY_MERGE) {
                $existing = $this->navigationMenuItemRepository->findActiveByMenuAndPageId($menu, (int) $page->getId());
                if ($existing instanceof NavigationMenuItem) {
                    if ($policy === self::POLICY_APPEND) {
                        return null;
                    }
                    $this->applyImportedItemFields($existing, $row, $parent);
                    $this->syncImportedTranslations($existing, $row);

                    return $existing;
                }
            }
        }

        $item = new NavigationMenuItem();
        $item->setNavigationMenu($menu);
        $item->setParentItem($parent);
        $type = $this->lookupService->findByTypeAndCode(LookupService::NAVIGATION_MENU_ITEM_TYPES, $itemType);
        if ($type) {
            $item->setItemType($type);
        }
        $item->setPage($page);
        $this->applyImportedItemFields($item, $row, $parent);
        $this->entityManager->persist($item);
        $this->entityManager->flush();
        $this->syncImportedTranslations($item, $row);
        $this->transactionService->logTransaction(
            LookupService::TRANSACTION_TYPES_INSERT,
            LookupService::TRANSACTION_BY_BY_USER,
            'navigation_menu_items',
            $item->getId(),
            true,
            'Navigation bundle import',
        );

        return $item;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function applyImportedItemFields(NavigationMenuItem $item, array $row, ?NavigationMenuItem $parent): void
    {
        $item->setParentItem($parent);
        $item->setPosition(is_int($row['position'] ?? null) ? $row['position'] : 10);
        $item->setIsActive((bool) ($row['is_active'] ?? true));
        $icon = $row['icon'] ?? null;
        $item->setIcon(is_string($icon) && $icon !== '' ? $icon : null);
        $mobileIcon = $row['mobile_icon'] ?? null;
        $item->setMobileIcon(is_string($mobileIcon) && $mobileIcon !== '' ? $mobileIcon : null);
        $label = $row['label'] ?? null;
        $item->setLabel(is_string($label) && $label !== '' ? $label : null);
        $externalUrl = $row['external_url'] ?? null;
        $item->setExternalUrl(is_string($externalUrl) && $externalUrl !== '' ? $externalUrl : null);

        $menu = $item->getNavigationMenu();
        $layer = $this->headerLayerSupport->normalizeLayer($row['layer'] ?? null);
        if ($menu instanceof NavigationMenu) {
            $this->headerLayerSupport->assertLayerAssignable($menu, $parent, $layer);
        }
        $item->setLayer($layer);

        $childrenNav = $row['children_nav'] ?? null;
        $item->setChildrenNav(
            is_string($childrenNav)
                && in_array($childrenNav, LookupService::NAVIGATION_CHILDREN_NAV_MODE_CODES, true)
                && $menu?->getPlatform()?->getLookupCode() === 'web'
                ? $this->lookupService->findByTypeAndCode(LookupService::NAVIGATION_CHILDREN_NAV_MODES, $childrenNav)
                : null,
        );

        $showPager = $row['show_pager'] ?? null;
        $item->setShowPager(is_bool($showPager) ? $showPager : null);
    }

    /**
     * Imports translation rows for label-driven items (group/external) AND
     * page items (presentation-only description/aria rows for mega menus).
     *
     * @param array<string, mixed> $row
     */
    private function syncImportedTranslations(NavigationMenuItem $item, array $row): void
    {
        $translations = is_array($row['translations'] ?? null) ? $row['translations'] : [];
        if ($translations === []) {
            return;
        }

        $payloadTranslations = [];
        foreach ($translations as $translation) {
            if (!is_array($translation)) {
                continue;
            }
            $locale = $this->asString($translation['locale'] ?? '');
            $languageId = $this->resolveLanguageId($locale);
            if ($languageId === null) {
                continue;
            }
            $payloadTranslations[$languageId] = [
                'language_id' => $languageId,
                'label' => $translation['label'] ?? null,
                'description' => $translation['description'] ?? null,
                'aria_label' => $translation['aria_label'] ?? null,
            ];
        }

        $this->translationSupport->syncMenuItemTranslations($item, [
            'translations' => array_values($payloadTranslations),
        ], $this->resolveDefaultLanguageId());
    }

    private function createStubPage(string $keyword): Page
    {
        return $this->adminPageService->createPage(
            $keyword,
            LookupService::PAGE_ACCESS_TYPES_WEB,
            false,
            true,
            '/' . $keyword,
        );
    }

    private function deleteAllMenuItems(NavigationMenu $menu): void
    {
        foreach ($this->navigationMenuItemRepository->findActiveByMenu($menu) as $item) {
            // Remove translations through the ORM too: the DB FK cascade would
            // delete the rows anyway, but translation entities hydrated earlier
            // (e.g. by an export in the same request) would stay managed and
            // point at the removed item, breaking the final import flush.
            $itemId = $item->getId();
            if ($itemId !== null) {
                foreach ($this->navigationMenuItemTranslationRepository->findByMenuItemId($itemId) as $translation) {
                    $this->entityManager->remove($translation);
                }
            }
            $this->entityManager->remove($item);
        }
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $menuPayload
     */
    private function applyMenuConfigImport(NavigationMenu $menu, string $menuKey, array $menuPayload): void
    {
        if (array_key_exists('preset', $menuPayload) && is_string($menuPayload['preset'])) {
            $allowed = LookupService::allowedNavigationPresetsForMenuKey($menuKey);
            if (in_array($menuPayload['preset'], $allowed, true)) {
                $menu->setPreset($this->lookupService->findByTypeAndCode(
                    LookupService::NAVIGATION_MENU_PRESETS,
                    $menuPayload['preset'],
                ));
            }
        }
        if (array_key_exists('max_depth', $menuPayload)) {
            $maxDepth = is_int($menuPayload['max_depth']) ? $menuPayload['max_depth'] : null;
            $menu->setMaxDepth($this->depthSupport->normalizeMenuMaxDepth($maxDepth));
        }
        if (array_key_exists('item_limit', $menuPayload)) {
            $menu->setItemLimit(is_int($menuPayload['item_limit']) ? $menuPayload['item_limit'] : null);
        }
        if ($menu->getPlatform()?->getLookupCode() === 'web') {
            if (array_key_exists('children_nav', $menuPayload)) {
                $childrenNav = $menuPayload['children_nav'];
                $menu->setChildrenNav(
                    is_string($childrenNav)
                        && in_array($childrenNav, LookupService::NAVIGATION_CHILDREN_NAV_MODE_CODES, true)
                        ? $this->lookupService->findByTypeAndCode(LookupService::NAVIGATION_CHILDREN_NAV_MODES, $childrenNav)
                        : null,
                );
            }
            if (array_key_exists('show_breadcrumbs', $menuPayload)) {
                $menu->setShowBreadcrumbs((bool) $menuPayload['show_breadcrumbs']);
            }
            if (array_key_exists('show_pager', $menuPayload)) {
                $menu->setShowPager((bool) $menuPayload['show_pager']);
            }
        }
    }

    /**
     * @param array<string, mixed> $settingsPayload
     */
    private function applySettingsImport(NavigationSettings $settings, array $settingsPayload, string $keywordPrefix): void
    {
        $lookupFields = [
            'web_header_search_mode' => [LookupService::NAVIGATION_SEARCH_MODES, 'setWebHeaderSearchMode'],
            'search_default_visibility' => [LookupService::NAVIGATION_SEARCH_VISIBILITY, 'setSearchDefaultVisibility'],
            'search_field_policy' => [LookupService::NAVIGATION_SEARCH_FIELD_POLICIES, 'setSearchFieldPolicy'],
            'web_user_start_mode' => [LookupService::NAVIGATION_START_MODES, 'setWebUserStartMode'],
            'mobile_user_start_mode' => [LookupService::NAVIGATION_START_MODES, 'setMobileUserStartMode'],
            'mobile_start_page_source' => [LookupService::NAVIGATION_MOBILE_START_SOURCES, 'setMobileStartPageSource'],
            'route_sync_old_route_policy' => [LookupService::NAVIGATION_ROUTE_SYNC_POLICIES, 'setRouteSyncOldRoutePolicy'],
        ];
        foreach ($lookupFields as $field => [$type, $setter]) {
            if (array_key_exists($field, $settingsPayload) && is_string($settingsPayload[$field])) {
                $settings->{$setter}($this->lookupService->findByTypeAndCode($type, $settingsPayload[$field]));
            }
        }

        $pageFields = [
            'web_guest_start_page_keyword' => 'setWebGuestStartPage',
            'web_user_start_page_keyword' => 'setWebUserStartPage',
            'mobile_guest_start_page_keyword' => 'setMobileGuestStartPage',
            'mobile_user_start_page_keyword' => 'setMobileUserStartPage',
        ];
        foreach ($pageFields as $field => $setter) {
            if (!array_key_exists($field, $settingsPayload)) {
                continue;
            }
            $keyword = $settingsPayload[$field];
            if ($keyword === null) {
                $settings->{$setter}(null);
                continue;
            }
            if (!is_string($keyword) || $keyword === '') {
                continue;
            }
            $prefixed = $this->prefixKeyword($keywordPrefix, $keyword);
            $settings->{$setter}($this->pageRepository->findOneBy(['keyword' => $prefixed]));
        }
    }

    /**
     * @param array<string, mixed> $bundle
     * @param list<array{level: string, code: string, message: string, menu_key: ?string}> $issues
     */
    private function validateBundleEnvelope(array $bundle, array &$issues): void
    {
        $format = $this->asString($bundle['format'] ?? '');
        if ($format !== self::BUNDLE_FORMAT) {
            $issues[] = $this->issue(self::ISSUE_ERROR, 'invalid_format', sprintf('Expected format "%s".', self::BUNDLE_FORMAT), null);
        }
        $version = $this->asString($bundle['version'] ?? '');
        if ($version !== self::BUNDLE_VERSION) {
            $issues[] = $this->issue(self::ISSUE_ERROR, 'unsupported_version', sprintf('Bundle version "%s" is not supported; only "%s" bundles can be imported. Re-export the bundle with the current version.', $version, self::BUNDLE_VERSION), null);
        }
    }

    /**
     * @param array<string, mixed> $menuPayload
     * @param array<string, bool> $keywordToExists
     * @param list<array{level: string, code: string, message: string, menu_key: ?string}> $issues
     */
    private function validateMenuPayload(
        string $menuKey,
        array $menuPayload,
        string $keywordPrefix,
        string $missingMode,
        array $keywordToExists,
        array &$issues,
    ): void {
        $presetValue = $menuPayload['preset'] ?? null;
        if ($presetValue !== null) {
            $allowed = LookupService::allowedNavigationPresetsForMenuKey($menuKey);
            if (!is_string($presetValue) || !in_array($presetValue, $allowed, true)) {
                $issues[] = $this->issue(self::ISSUE_ERROR, 'invalid_preset', sprintf(
                    'Preset "%s" is not valid for menu "%s".%s',
                    is_scalar($presetValue) ? (string) $presetValue : gettype($presetValue),
                    $menuKey,
                    $allowed === [] ? ' This menu does not support presets.' : sprintf(' Allowed: %s.', implode(', ', $allowed)),
                ), $menuKey);
            }
        }

        $items = is_array($menuPayload['items'] ?? null) ? $menuPayload['items'] : [];
        $refs = [];
        /** @var array<string, ?string> $parentRefByRef */
        $parentRefByRef = [];
        /** @var array<string, true> $topLayerRefs */
        $topLayerRefs = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = $this->asString($row['ref'] ?? '');
            if ($ref === '') {
                $issues[] = $this->issue(self::ISSUE_ERROR, 'missing_item_ref', 'Every menu item must have a ref.', $menuKey);
                continue;
            }
            $refs[$ref] = true;
            $parentRef = $row['parent_ref'] ?? null;
            $parentRefByRef[$ref] = is_string($parentRef) && $parentRef !== '' ? $parentRef : null;

            if ($parentRef !== null && $parentRef !== '' && !is_string($parentRef)) {
                $issues[] = $this->issue(self::ISSUE_ERROR, 'invalid_parent_ref', sprintf('Item "%s" has an invalid parent_ref.', $ref), $menuKey);
            } elseif (is_string($parentRef) && $parentRef !== '' && $parentRef === $ref) {
                $issues[] = $this->issue(self::ISSUE_ERROR, 'self_parent_ref', sprintf('Item "%s" cannot be its own parent.', $ref), $menuKey);
            }

            $depth = $this->computeBundleItemDepth($ref, $parentRefByRef);
            if ($depth > NavigationMenuDepthSupport::MAX_LEVEL) {
                $issues[] = $this->issue(self::ISSUE_ERROR, 'menu_depth_exceeded', sprintf('Item "%s" exceeds the maximum menu depth of 2 levels.', $ref), $menuKey);
            }

            $layer = $row['layer'] ?? null;
            if ($layer !== null && $layer !== '') {
                if ($layer !== NavigationHeaderLayerSupport::LAYER_TOP) {
                    $issues[] = $this->issue(self::ISSUE_ERROR, 'invalid_layer', sprintf('Item "%s" has unknown layer "%s"; allowed values are "top" or null.', $ref, is_scalar($layer) ? (string) $layer : gettype($layer)), $menuKey);
                } elseif ($menuKey !== LookupService::NAVIGATION_MENU_KEY_WEB_HEADER) {
                    $issues[] = $this->issue(self::ISSUE_ERROR, 'layer_not_allowed', sprintf('Item "%s" sets layer "top" but only web_header items support layers.', $ref), $menuKey);
                } elseif ($parentRefByRef[$ref] !== null) {
                    $issues[] = $this->issue(self::ISSUE_ERROR, 'layer_on_nested_item', sprintf('Item "%s" sets layer "top" but only top-level header items can be assigned to the top row.', $ref), $menuKey);
                } else {
                    $topLayerRefs[$ref] = true;
                }
            }

            $itemType = $this->asString($row['item_type'] ?? LookupService::NAVIGATION_ITEM_TYPE_PAGE);
            if ($itemType === LookupService::NAVIGATION_ITEM_TYPE_PAGE) {
                $keyword = $this->prefixKeyword($keywordPrefix, $this->asString($row['page_keyword'] ?? ''));
                if ($keyword === '') {
                    $issues[] = $this->issue(self::ISSUE_ERROR, 'missing_page_keyword', sprintf('Page item "%s" is missing page_keyword.', $ref), $menuKey);
                    continue;
                }
                if ($missingMode === self::MISSING_PAGES_STRICT && !isset($keywordToExists[$keyword])) {
                    $issues[] = $this->issue(self::ISSUE_ERROR, 'missing_page', sprintf('Page "%s" is not installed and is required for menu "%s".', $keyword, $menuKey), $menuKey);
                } elseif ($missingMode === self::MISSING_PAGES_SKIP && !isset($keywordToExists[$keyword])) {
                    $issues[] = $this->issue(self::ISSUE_WARNING, 'missing_page_skipped', sprintf('Page "%s" is missing and will be skipped on import.', $keyword), $menuKey);
                }
            }
        }

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parentRef = $row['parent_ref'] ?? null;
            if (is_string($parentRef) && $parentRef !== '' && !isset($refs[$parentRef])) {
                $refValue = $row['ref'] ?? '';
                $issues[] = $this->issue(self::ISSUE_ERROR, 'orphan_parent_ref', sprintf('Item "%s" references unknown parent_ref "%s".', is_string($refValue) ? $refValue : '', $parentRef), $menuKey);
            }
            if (is_string($parentRef) && isset($topLayerRefs[$parentRef])) {
                $refValue = $row['ref'] ?? '';
                $issues[] = $this->issue(self::ISSUE_ERROR, 'child_under_top_layer', sprintf('Item "%s" nests under top-row link "%s"; top-row links cannot have sub-items.', is_string($refValue) ? $refValue : '', $parentRef), $menuKey);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $embeddedPages
     *
     * @return array<string, bool>
     */
    private function buildKeywordExistenceMap(array $embeddedPages, string $keywordPrefix): array
    {
        $map = [];
        foreach ($embeddedPages as $page) {
            $keyword = $this->prefixKeyword($keywordPrefix, $this->asString($page['keyword'] ?? ''));
            if ($keyword !== '') {
                $map[$keyword] = true;
            }
        }

        foreach ($this->pageRepository->findAll() as $page) {
            $keyword = $page->getKeyword();
            if (is_string($keyword) && $keyword !== '') {
                $map[$keyword] = true;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function resolveMenuKeys(mixed $menuKeys): array
    {
        $all = [
            LookupService::NAVIGATION_MENU_KEY_WEB_HEADER,
            LookupService::NAVIGATION_MENU_KEY_WEB_FOOTER,
            LookupService::NAVIGATION_MENU_KEY_MOBILE_DRAWER,
            LookupService::NAVIGATION_MENU_KEY_MOBILE_BOTTOM_TABS,
        ];
        if (!is_array($menuKeys) || $menuKeys === []) {
            return $all;
        }

        $out = [];
        foreach ($menuKeys as $key) {
            if (is_string($key) && in_array($key, $all, true)) {
                $out[] = $key;
            }
        }

        return $out !== [] ? $out : $all;
    }

    private function requireMenuByKey(string $menuKey): NavigationMenu
    {
        $lookupId = $this->lookupService->getLookupIdByCode(LookupService::NAVIGATION_MENU_KEYS, $menuKey);
        $menu = $this->navigationMenuRepository->findByMenuKeyLookupId((int) $lookupId);
        if (!$menu instanceof NavigationMenu) {
            $this->throwNotFound("Navigation menu '{$menuKey}' not found");
        }

        return $menu;
    }

    private function prefixKeyword(string $prefix, string $keyword): string
    {
        if ($prefix === '' || $keyword === '') {
            return $keyword;
        }

        return str_starts_with($keyword, $prefix) ? $keyword : $prefix . $keyword;
    }

    private function resolveLanguageId(string $locale): ?int
    {
        if ($locale === '') {
            return null;
        }
        $language = $this->languageRepository->findOneBy(['locale' => $locale]);
        if ($language instanceof Language) {
            return $language->getId();
        }

        return null;
    }

    private function resolveDefaultLanguageId(): int
    {
        $language = $this->languageRepository->findOneBy(['locale' => 'en-GB']);
        if ($language instanceof Language && $language->getId() !== null) {
            return $language->getId();
        }

        return 1;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
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

        return $out;
    }

    /**
     * @param array<string, ?string> $parentRefByRef
     */
    private function computeBundleItemDepth(string $ref, array $parentRefByRef): int
    {
        $depth = 0;
        $current = $ref;
        $guard = 0;
        while (true) {
            $parent = $parentRefByRef[$current] ?? null;
            if ($parent === null || $parent === '') {
                break;
            }
            ++$depth;
            $current = $parent;
            if (++$guard > 16) {
                break;
            }
        }

        return $depth;
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $options
     */
    private function importHintsPrefix(array $options, array $bundle): string
    {
        if (array_key_exists('keyword_prefix', $options) && is_string($options['keyword_prefix'])) {
            return $options['keyword_prefix'];
        }

        $hints = $bundle['import_hints'] ?? null;
        if (is_array($hints) && is_string($hints['default_keyword_prefix'] ?? null)) {
            return $hints['default_keyword_prefix'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $bundle
     */
    private function importHintsRoutePrefix(array $options, array $bundle): string
    {
        if (array_key_exists('route_prefix', $options) && is_string($options['route_prefix'])) {
            return $options['route_prefix'];
        }

        $hints = $bundle['import_hints'] ?? null;
        if (is_array($hints) && is_string($hints['default_route_prefix'] ?? null)) {
            return $hints['default_route_prefix'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private function pageImportOptionsFromNavigation(array $options, array $bundle): array
    {
        $pageOptions = [
            'keywordPrefix' => $this->importHintsPrefix($options, $bundle),
            'routePrefix' => $this->importHintsRoutePrefix($options, $bundle),
            'skipConflictingRoutes' => (bool) ($options['skip_conflicting_routes'] ?? false),
            'activateRoutes' => !array_key_exists('activate_routes', $options) || (bool) $options['activate_routes'],
        ];
        $accessGroups = $this->intList($options['access_groups'] ?? $options['accessGroups'] ?? null);
        if ($accessGroups !== []) {
            $pageOptions['accessGroups'] = $accessGroups;
        }

        return $pageOptions;
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $options
     * @param list<array{level: string, code: string, message: string, menu_key: ?string, page_keyword?: ?string}> $issues
     */
    private function validateEmbeddedPages(array $bundle, array $options, array &$issues): void
    {
        $embeddedPages = $this->bundlePages($bundle);
        if ($embeddedPages === []) {
            return;
        }

        $pageBundle = [
            'format' => PageExportImportService::BUNDLE_FORMAT,
            'version' => PageExportImportService::BUNDLE_VERSION,
            'pages' => $embeddedPages,
        ];
        $validation = $this->pageExportImportService->validateImport(
            $pageBundle,
            $this->pageImportOptionsFromNavigation($options, $bundle),
        );

        foreach ($validation['issues'] as $pageIssue) {
            $issues[] = [
                'level' => $pageIssue['level'],
                'code' => $pageIssue['code'],
                'message' => $pageIssue['message'],
                'menu_key' => null,
                'page_keyword' => $pageIssue['page_keyword'],
            ];
        }
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<string, array<string, mixed>>
     */
    private function bundleMenus(array $bundle): array
    {
        $rawMenus = $bundle['menus'] ?? null;
        if (!is_array($rawMenus)) {
            return [];
        }

        $menus = [];
        foreach ($rawMenus as $menuKey => $menuPayload) {
            if (!is_string($menuKey) || !is_array($menuPayload)) {
                continue;
            }
            /** @var array<string, mixed> $assoc */
            $assoc = [];
            foreach ($menuPayload as $key => $value) {
                $assoc[(string) $key] = $value;
            }
            $menus[$menuKey] = $assoc;
        }

        return $menus;
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return list<array<string, mixed>>
     */
    private function bundlePages(array $bundle): array
    {
        $rawPages = $bundle['pages'] ?? null;
        if (!is_array($rawPages)) {
            return [];
        }

        $pages = [];
        foreach ($rawPages as $page) {
            if (!is_array($page)) {
                continue;
            }
            /** @var array<string, mixed> $assoc */
            $assoc = [];
            foreach ($page as $key => $value) {
                $assoc[(string) $key] = $value;
            }
            $pages[] = $assoc;
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function itemPosition(array $row): int
    {
        $position = $row['position'] ?? 0;

        return is_int($position) ? $position : (is_numeric($position) ? (int) $position : 0);
    }

    /**
     * @return array{level: string, code: string, message: string, menu_key: ?string}
     */
    private function issue(string $level, string $code, string $message, ?string $menuKey): array
    {
        return [
            'level' => $level,
            'code' => $code,
            'message' => $message,
            'menu_key' => $menuKey,
        ];
    }
}
