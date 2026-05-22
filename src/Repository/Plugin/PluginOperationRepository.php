<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Repository\Plugin;

use App\Entity\Plugin\PluginOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PluginOperation>
 */
class PluginOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PluginOperation::class);
    }

    /** @return list<PluginOperation> */
    public function findActive(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status IN (:active)')
            ->setParameter('active', [PluginOperation::STATUS_REQUESTED, PluginOperation::STATUS_RUNNING])
            ->getQuery()
            ->getResult();
    }

    /** @return list<PluginOperation> */
    public function findByPluginId(string $pluginId, int $limit = 50): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.pluginId = :pid')
            ->setParameter('pid', $pluginId)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
