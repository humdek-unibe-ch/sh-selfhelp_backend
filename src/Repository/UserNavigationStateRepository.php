<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserNavigationState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserNavigationState>
 */
class UserNavigationStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserNavigationState::class);
    }

    public function findForUserAndPlatform(User $user, int $platformLookupId): ?UserNavigationState
    {
        $result = $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('IDENTITY(s.platform) = :platformId')
            ->setParameter('user', $user)
            ->setParameter('platformId', $platformLookupId)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof UserNavigationState ? $result : null;
    }
}
