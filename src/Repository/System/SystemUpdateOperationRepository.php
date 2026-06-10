<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Repository\System;

use App\Entity\System\SystemUpdateOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemUpdateOperation>
 */
class SystemUpdateOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemUpdateOperation::class);
    }

    /**
     * Latest operation for an instance, or null. Instance-scoped on purpose:
     * the CMS only ever reports on its own instance.
     */
    public function findLatestForInstance(string $instanceId): ?SystemUpdateOperation
    {
        return $this->findOneBy(['instanceId' => $instanceId], ['requestedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findByOperationId(string $operationId): ?SystemUpdateOperation
    {
        return $this->findOneBy(['operationId' => $operationId]);
    }

    /**
     * Latest operation the SelfHelp Manager may claim for execution: the most
     * recent `requested` operation for this instance. Instance-scoped so the
     * manager can never claim another instance's work.
     */
    public function findLatestClaimableForInstance(string $instanceId): ?SystemUpdateOperation
    {
        return $this->findOneBy(
            ['instanceId' => $instanceId, 'status' => SystemUpdateOperation::STATUS_REQUESTED],
            ['requestedAt' => 'ASC', 'id' => 'ASC']
        );
    }
}
