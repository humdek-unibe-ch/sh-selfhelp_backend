<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\ScheduledJobRecipient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledJobRecipient>
 */
class ScheduledJobRecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledJobRecipient::class);
    }

    /**
     * Find all recipient snapshots for a scheduled job, ordered so the
     * primary `to` recipient comes first.
     *
     * @param int $jobId
     *   The scheduled job id.
     *
     * @return ScheduledJobRecipient[]
     *   The recipient snapshots for the job.
     */
    public function findByScheduledJob(int $jobId): array
    {
        /** @var list<ScheduledJobRecipient> $result */
        $result = $this->createQueryBuilder('r')
            ->andWhere('IDENTITY(r.scheduledJob) = :jobId')
            ->setParameter('jobId', $jobId)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
