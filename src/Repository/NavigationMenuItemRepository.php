<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

use App\Entity\NavigationMenu;
use App\Entity\NavigationMenuItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NavigationMenuItem>
 */
class NavigationMenuItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NavigationMenuItem::class);
    }

    /**
     * @return list<NavigationMenuItem>
     */
    public function findActiveByMenu(NavigationMenu $menu): array
    {
        /** @var list<NavigationMenuItem> $items */
        $items = $this->createQueryBuilder('i')
            ->andWhere('i.navigationMenu = :menu')
            ->andWhere('i.isActive = 1')
            ->setParameter('menu', $menu)
            ->orderBy('i.position', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * @return list<int>
     */
    public function findActivePageIdsForMenu(NavigationMenu $menu): array
    {
        /** @var list<int> $pageIds */
        $pageIds = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.page)')
            ->andWhere('i.navigationMenu = :menu')
            ->andWhere('i.isActive = 1')
            ->andWhere('i.page IS NOT NULL')
            ->setParameter('menu', $menu)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (mixed $id): int => (int) $id, $pageIds);
    }

    public function findActiveByMenuAndPageId(NavigationMenu $menu, int $pageId): ?NavigationMenuItem
    {
        $item = $this->createQueryBuilder('i')
            ->andWhere('i.navigationMenu = :menu')
            ->andWhere('i.isActive = 1')
            ->andWhere('i.page = :pageId')
            ->setParameter('menu', $menu)
            ->setParameter('pageId', $pageId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $item instanceof NavigationMenuItem ? $item : null;
    }
}
