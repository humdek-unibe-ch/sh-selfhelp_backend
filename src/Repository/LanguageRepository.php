<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\Language;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Language>
 */
class LanguageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Language::class);
    }

    /**
     * All real languages (excludes the language-independent row).
     *
     * @return Language[]
     */
    public function findRealLanguages(): array
    {
        /** @var list<Language> $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.id > :independentId')
            ->setParameter('independentId', Language::INDEPENDENT_ID)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find all languages
     * 
     * @return Language[]
     */
    public function findAllLanguages(): array
    {
        /** @var list<Language> $result */
        $result = $this->createQueryBuilder('l')
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Lowest id among real languages (never the language-independent row).
     */
    public function findLowestRealLanguageId(): int
    {
        $id = $this->createQueryBuilder('l')
            ->select('MIN(l.id)')
            ->where('l.id > :independentId')
            ->setParameter('independentId', Language::INDEPENDENT_ID)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $id;
    }
}
