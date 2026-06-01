<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Repository\Plugin;

use App\Entity\Plugin\PluginSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PluginSource>
 */
class PluginSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PluginSource::class);
    }

    /** @return list<PluginSource> */
    public function findEnabled(): array
    {
        /** @var list<PluginSource> $result */
        $result = $this->createQueryBuilder('s')
            ->where('s.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
