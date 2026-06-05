<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

use App\Entity\ScheduledJobRunnerRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledJobRunnerRun>
 */
class ScheduledJobRunnerRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledJobRunnerRun::class);
    }

    /**
     * Return the most recently started run, or null when none exists.
     */
    public function findLatestRun(): ?ScheduledJobRunnerRun
    {
        /** @var ScheduledJobRunnerRun|null $result */
        $result = $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Return the most recently finished run, or null when none exists.
     */
    public function findLatestFinishedRun(): ?ScheduledJobRunnerRun
    {
        /** @var ScheduledJobRunnerRun|null $result */
        $result = $this->createQueryBuilder('r')
            ->andWhere('r.finishedAt IS NOT NULL')
            ->orderBy('r.finishedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }
}
