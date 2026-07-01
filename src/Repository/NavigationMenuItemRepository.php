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
     * Active menu items linked to {@see $pageId} with child_source = page_children.
     *
     * @return list<NavigationMenuItem>
     */
    public function findActiveAutoIncludeItemsForPage(int $pageId): array
    {
        /** @var list<NavigationMenuItem> $items */
        $items = $this->createQueryBuilder('i')
            ->innerJoin('i.page', 'p')
            ->innerJoin('i.childSource', 'cs')
            ->andWhere('p.id = :pageId')
            ->andWhere('i.isActive = 1')
            ->andWhere('cs.lookupCode = :childSource')
            ->setParameter('pageId', $pageId)
            ->setParameter('childSource', 'page_children')
            ->orderBy('i.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }
}
