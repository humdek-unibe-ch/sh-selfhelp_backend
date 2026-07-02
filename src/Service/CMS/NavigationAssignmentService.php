<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use App\Entity\Page;
use App\Repository\NavigationMenuItemRepository;
use App\Repository\NavigationMenuRepository;
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

            $itemType = $this->lookupService->findByTypeAndCode(
                LookupService::NAVIGATION_MENU_ITEM_TYPES,
                LookupService::NAVIGATION_ITEM_TYPE_PAGE
            );
            $childSource = $this->lookupService->findByTypeAndCode(
                LookupService::NAVIGATION_CHILD_SOURCES,
                LookupService::NAVIGATION_CHILD_SOURCE_MANUAL
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
            $item->setAutoIncludeDepth(null);
            $item->setIsActive(true);

            $icon = $assignment['icon'] ?? $assignment['iconOverride'] ?? $assignment['icon_override'] ?? null;
            if (is_string($icon) && $icon !== '') {
                $item->setIcon($icon);
            }

            $mobileIcon = $assignment['mobileIcon'] ?? $assignment['mobile_icon'] ?? null;
            if (is_string($mobileIcon) && $mobileIcon !== '') {
                $item->setMobileIcon($mobileIcon);
            }

            $label = $assignment['label'] ?? null;
            if (is_string($label) && $label !== '') {
                $item->setLabel($label);
            }

            $this->entityManager->persist($item);
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

        return $badges;
    }

    /**
     * @return list<string>
     */
    public function getAutoIncludeMenuKeysForParentPage(int $parentPageId): array
    {
        unset($parentPageId);

        return [];
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
}
