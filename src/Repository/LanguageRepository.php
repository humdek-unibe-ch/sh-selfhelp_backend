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
     * Find all languages with ID greater than 1
     * 
     * @return Language[]
     */
    public function findAllExceptInternal(): array
    {
        /** @var list<Language> $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.id > 1')
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
    
}
