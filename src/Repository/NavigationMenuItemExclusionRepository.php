<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

use App\Entity\NavigationMenuItem;
use App\Entity\NavigationMenuItemExclusion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NavigationMenuItemExclusion>
 */
class NavigationMenuItemExclusionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NavigationMenuItemExclusion::class);
    }

    /**
     * @return list<int> excluded page ids
     */
    public function findExcludedPageIdsForItem(NavigationMenuItem $item): array
    {
        /** @var list<array{id_pages:int|string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.page) AS id_pages')
            ->andWhere('e.navigationMenuItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): int => (int) $r['id_pages'], $rows);
    }
}
