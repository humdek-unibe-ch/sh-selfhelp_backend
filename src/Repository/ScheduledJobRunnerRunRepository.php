<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

use App\Entity\ScheduledJobRunnerRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
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

    /**
     * Delete every run row except the `$maxRuns` most recent, bounding the audit
     * table (the runner writes one row per tick). The auto-increment `id` is
     * monotonic with insertion order, so "most recent" is simply the highest
     * ids: find the id at offset `$maxRuns - 1` (the oldest row to keep) and
     * bulk-delete everything older. Returns the number of pruned rows.
     */
    public function pruneToMostRecent(int $maxRuns): int
    {
        if ($maxRuns < 1) {
            return 0;
        }

        $cutoffId = $this->createQueryBuilder('r')
            ->select('r.id')
            ->orderBy('r.id', 'DESC')
            ->setFirstResult($maxRuns - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR);

        // Fewer than $maxRuns rows exist (null): nothing to prune.
        if (!is_numeric($cutoffId)) {
            return 0;
        }

        $deleted = $this->createQueryBuilder('r')
            ->delete()
            ->where('r.id < :cutoff')
            ->setParameter('cutoff', (int) $cutoffId)
            ->getQuery()
            ->execute();

        return is_int($deleted) ? $deleted : 0;
    }
}
