<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\PageType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageType>
 */
class PageTypeRepository extends ServiceEntityRepository
{
    /** @var list<int>|null */
    private ?array $publicNavigableIds = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageType::class);
    }

    /**
     * Ids of the page types public navigation may render, resolved from
     * PageType::PUBLIC_NAVIGABLE_PAGE_TYPES by name. Memoized per request.
     *
     * @return list<int>
     */
    public function findPublicNavigableIds(): array
    {
        if ($this->publicNavigableIds !== null) {
            return $this->publicNavigableIds;
        }

        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('pt')
            ->select('pt.id')
            ->andWhere('pt.name IN (:names)')
            ->setParameter('names', PageType::PUBLIC_NAVIGABLE_PAGE_TYPES)
            ->getQuery()
            ->getArrayResult();

        return $this->publicNavigableIds = array_map(static fn (array $row): int => $row['id'], $rows);
    }
}
