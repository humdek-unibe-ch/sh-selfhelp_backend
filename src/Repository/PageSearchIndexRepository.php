<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

use App\Entity\PageSearchIndex;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageSearchIndex>
 */
class PageSearchIndexRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageSearchIndex::class);
    }

    public function findOneByPageAndLanguage(int $pageId, int $languageId): ?PageSearchIndex
    {
        return $this->findOneBy([
            'page' => $pageId,
            'language' => $languageId,
        ]);
    }

    public function deleteForPage(int $pageId): void
    {
        $this->createQueryBuilder('psi')
            ->delete()
            ->where('IDENTITY(psi.page) = :pageId')
            ->setParameter('pageId', $pageId)
            ->getQuery()
            ->execute();
    }
}
