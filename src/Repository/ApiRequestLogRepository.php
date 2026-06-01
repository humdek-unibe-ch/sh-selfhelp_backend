<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\ApiRequestLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ApiRequestLogRepository
 * 
 * Repository for managing ApiRequestLog entities
 *
 * @extends ServiceEntityRepository<ApiRequestLog>
 */
class ApiRequestLogRepository extends ServiceEntityRepository
{
    /**
     * Constructor
     * 
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiRequestLog::class);
    }

    /**
     * Find logs by path
     * 
     * @param string $path API path
     * @return ApiRequestLog[]
     */
    public function findByPath(string $path)
    {
        /** @var list<ApiRequestLog> $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.path = :path')
            ->setParameter('path', $path)
            ->orderBy('l.requestTime', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find logs by user ID
     * 
     * @param int $userId User ID
     * @return ApiRequestLog[]
     */
    public function findByUserId(int $userId)
    {
        /** @var list<ApiRequestLog> $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('l.requestTime', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find logs with errors (non-2xx status codes)
     * 
     * @return ApiRequestLog[]
     */
    public function findErrors()
    {
        /** @var list<ApiRequestLog> $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.statusCode >= 400')
            ->orderBy('l.requestTime', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find slow requests (taking more than specified milliseconds)
     * 
     * @param int $minDuration Minimum duration in milliseconds
     * @return ApiRequestLog[]
     */
    public function findSlowRequests(int $minDuration = 1000)
    {
        /** @var list<ApiRequestLog> $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.durationMs >= :minDuration')
            ->setParameter('minDuration', $minDuration)
            ->orderBy('l.durationMs', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
