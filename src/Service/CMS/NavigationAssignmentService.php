<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Entity\Page;
use App\Repository\NavigationMenuItemExclusionRepository;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\NavigationMenuRepository;
use App\Repository\PageRepository;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates navigation menu items when pages are assigned to menus at create/update time.
 */
class NavigationAssignmentService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NavigationMenuRepository $navigationMenuRepository,
        private readonly NavigationMenuItemRepository $navigationMenuItemRepository,
        private readonly NavigationMenuItemExclusionRepository $navigationMenuItemExclusionRepository,
        private readonly PageRepository $pageRepository,
        private readonly LookupService $lookupService,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $assignments
     */
    public function applyAssignmentsForPage(Page $page, array $assignments): void
    {
        if ($assignments === []) {
            return;
        }

        foreach ($assignments as $assignment) {
            $menuKey = $assignment['menuKey'] ?? $assignment['menu_key'] ?? null;
            if (!is_string($menuKey) || $menuKey === '') {
                continue;
            }

            $menu = $this->resolveMenuByKey($menuKey);
            if (!$menu instanceof NavigationMenu) {
                $this->throwNotFound("Navigation menu '{$menuKey}' not found");
            }

            $parentItem = null;
            $parentItemId = $assignment['parentItemId'] ?? $assignment['parent_item_id'] ?? null;
            if (is_int($parentItemId) || (is_string($parentItemId) && $parentItemId !== '' && is_numeric($parentItemId))) {
                $parentItem = $this->navigationMenuItemRepository->find((int) $parentItemId);
                if (!$parentItem instanceof NavigationMenuItem) {
                    $this->throwNotFound('Parent menu item not found');
                }
                if ($parentItem->getNavigationMenu()?->getId() !== $menu->getId()) {
                    $this->throwBadRequest('Parent menu item does not belong to the target menu');
                }
            }

            $position = $assignment['position'] ?? null;
            if (!is_int($position)) {
                $position = $this->nextPositionForParent($menu, $parentItem);
            }

            $childSourceCode = $assignment['childSource'] ?? $assignment['child_source'] ?? LookupService::NAVIGATION_CHILD_SOURCE_MANUAL;
            if (!is_string($childSourceCode)) {
                $childSourceCode = LookupService::NAVIGATION_CHILD_SOURCE_MANUAL;
            }

            $itemType = $this->lookupService->findByTypeAndCode(
                LookupService::NAVIGATION_MENU_ITEM_TYPES,
                LookupService::NAVIGATION_ITEM_TYPE_PAGE
            );
            $childSource = $this->lookupService->findByTypeAndCode(
                LookupService::NAVIGATION_CHILD_SOURCES,
                $childSourceCode
            );
            if (!$itemType || !$childSource) {
                $this->throwNotFound('Navigation lookup configuration is incomplete');
            }

            $item = new NavigationMenuItem();
            $item->setNavigationMenu($menu);
            $item->setParentItem($parentItem);
            $item->setItemType($itemType);
            $item->setPage($page);
            $item->setPosition((int) $position);
            $item->setChildSource($childSource);
            $item->setIsActive(true);

            $iconOverride = $assignment['iconOverride'] ?? $assignment['icon_override'] ?? null;
            if (is_string($iconOverride) && $iconOverride !== '') {
                $item->setIconOverride($iconOverride);
            }

            $autoDepth = $assignment['autoIncludeDepth'] ?? $assignment['auto_include_depth'] ?? null;
            if ($childSourceCode === LookupService::NAVIGATION_CHILD_SOURCE_PAGE_CHILDREN) {
                $item->setAutoIncludeDepth(is_int($autoDepth) ? $autoDepth : 1);
            }

            $this->entityManager->persist($item);

            $labels = $assignment['labels'] ?? $assignment['labelOverrides'] ?? null;
            if (is_array($labels)) {
                $this->persistLabelOverrides($item, $labels);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMembershipBadgesForPage(int $pageId): array
    {
        $badges = [];
        $items = $this->navigationMenuItemRepository->findBy(['page' => $pageId, 'isActive' => true]);
        foreach ($items as $item) {
            $menuKey = $item->getNavigationMenu()?->getMenuKey()?->getLookupCode();
            if ($menuKey === null) {
                continue;
            }
            $badges[] = [
                'menu_key' => $menuKey,
                'menu_item_id' => $item->getId(),
                'explicit' => true,
            ];
        }

        foreach ($this->resolveAutoIncludedMemberships($pageId) as $autoBadge) {
            $badges[] = $autoBadge;
        }

        return $badges;
    }

    /**
     * Menu keys where a new child of {@see $parentPageId} will appear via page_children auto-include.
     *
     * @return list<string>
     */
    public function getAutoIncludeMenuKeysForParentPage(int $parentPageId): array
    {
        $keys = [];
        foreach ($this->navigationMenuItemRepository->findActiveAutoIncludeItemsForPage($parentPageId) as $item) {
            $menuKey = $item->getNavigationMenu()?->getMenuKey()?->getLookupCode();
            if (is_string($menuKey) && $menuKey !== '') {
                $keys[] = $menuKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<array{menu_key: string, menu_item_id: int, explicit: false}>
     */
    private function resolveAutoIncludedMemberships(int $pageId): array
    {
        $page = $this->pageRepository->find($pageId);
        if (!$page instanceof Page) {
            return [];
        }

        $parentPage = $page->getParentPage();
        if ($parentPage === null) {
            return [];
        }

        $parentPageId = $parentPage->getId();
        if ($parentPageId === null) {
            return [];
        }

        $badges = [];
        foreach ($this->navigationMenuItemRepository->findActiveAutoIncludeItemsForPage($parentPageId) as $item) {
            $excluded = $this->navigationMenuItemExclusionRepository->findExcludedPageIdsForItem($item);
            if (in_array($pageId, $excluded, true)) {
                continue;
            }

            $menuKey = $item->getNavigationMenu()?->getMenuKey()?->getLookupCode();
            if ($menuKey === null) {
                continue;
            }

            $badges[] = [
                'menu_key' => $menuKey,
                'menu_item_id' => (int) $item->getId(),
                'explicit' => false,
            ];
        }

        return $badges;
    }

    /**
     * @param list<int> $pageIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function getMembershipBadgesForPageIds(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        /** @var list<NavigationMenuItem> $items */
        $items = $this->navigationMenuItemRepository->createQueryBuilder('i')
            ->andWhere('i.page IN (:pageIds)')
            ->andWhere('i.isActive = 1')
            ->setParameter('pageIds', $pageIds)
            ->getQuery()
            ->getResult();

        $byPage = [];
        foreach ($items as $item) {
            $pageId = $item->getPage()?->getId();
            if ($pageId === null) {
                continue;
            }
            $menuKey = $item->getNavigationMenu()?->getMenuKey()?->getLookupCode();
            if ($menuKey === null) {
                continue;
            }
            $byPage[$pageId][] = [
                'menu_key' => $menuKey,
                'menu_item_id' => $item->getId(),
                'explicit' => true,
            ];
        }

        foreach ($pageIds as $pageId) {
            foreach ($this->resolveAutoIncludedMemberships($pageId) as $autoBadge) {
                $byPage[$pageId][] = $autoBadge;
            }
        }

        return $byPage;
    }

    private function resolveMenuByKey(string $menuKey): ?NavigationMenu
    {
        $lookupId = $this->lookupService->getLookupIdByCode(LookupService::NAVIGATION_MENU_KEYS, $menuKey);

        return $this->navigationMenuRepository->findByMenuKeyLookupId((int) $lookupId);
    }

    private function nextPositionForParent(NavigationMenu $menu, ?NavigationMenuItem $parentItem): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('MAX(i.position)')
            ->from(NavigationMenuItem::class, 'i')
            ->andWhere('i.navigationMenu = :menu')
            ->setParameter('menu', $menu);

        if ($parentItem !== null) {
            $qb->andWhere('i.parentItem = :parent')->setParameter('parent', $parentItem);
        } else {
            $qb->andWhere('i.parentItem IS NULL');
        }

        $max = $qb->getQuery()->getSingleScalarResult();

        return $max !== null ? ((int) $max) + 10 : 10;
    }

    /**
     * @param array<int|string, mixed> $labels languageId => label or { languageId: label }
     */
    private function persistLabelOverrides(NavigationMenuItem $item, array $labels): void
    {
        foreach ($labels as $languageId => $label) {
            if (!is_numeric($languageId) || !is_string($label) || $label === '') {
                continue;
            }
            $translation = new \App\Entity\NavigationMenuItemTranslation();
            $translation->setNavigationMenuItem($item);
            $language = $this->entityManager->getReference(\App\Entity\Language::class, (int) $languageId);
            $translation->setLanguage($language);
            $translation->setLabel($label);
            $this->entityManager->persist($translation);
        }
    }
}
