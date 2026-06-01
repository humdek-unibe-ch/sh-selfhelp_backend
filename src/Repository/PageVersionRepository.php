<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Repository;

use App\Entity\PageVersion;
use App\Entity\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * PageVersionRepository
 * 
 * Repository for PageVersion entity providing methods to:
 * - Find versions by page
 * - Get the latest version number for a page
 * - Find published version for a page
 * - Retrieve version history with filtering
 * 
 * @extends ServiceEntityRepository<PageVersion>
 */
class PageVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageVersion::class);
    }

    /**
     * Get all versions for a specific page ordered by version number descending
     * 
     * @param int $pageId The page ID
     * @return PageVersion[] Array of PageVersion entities
     */
    public function findByPage(int $pageId): array
    {
        /** @var list<PageVersion> $result */
        $result = $this->createQueryBuilder('pv')
            ->where('pv.page = :pageId')
            ->setParameter('pageId', $pageId)
            ->orderBy('pv.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get the latest version number for a page
     * 
     * @param int $pageId The page ID
     * @return int The latest version number (0 if no versions exist)
     */
    public function getLatestVersionNumber(int $pageId): int
    {
        $result = $this->createQueryBuilder('pv')
            ->select('MAX(pv.versionNumber) as maxVersion')
            ->where('pv.page = :pageId')
            ->setParameter('pageId', $pageId)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (int)$result : 0;
    }

    /**
     * Get the currently published version for a page
     * 
     * @param int $pageId The page ID
     * @return PageVersion|null The published version or null if no version is published
     */
    public function getPublishedVersion(int $pageId): ?PageVersion
    {
        /** @var PageVersion|null $result */
        $result = $this->createQueryBuilder('pv')
            ->where('pv.page = :pageId')
            ->andWhere('pv.publishedAt IS NOT NULL')
            ->setParameter('pageId', $pageId)
            ->orderBy('pv.publishedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Find a specific version by page ID and version number
     * 
     * @param int $pageId The page ID
     * @param int $versionNumber The version number
     * @return PageVersion|null The version or null if not found
     */
    public function findByPageAndVersionNumber(int $pageId, int $versionNumber): ?PageVersion
    {
        /** @var PageVersion|null $result */
        $result = $this->createQueryBuilder('pv')
            ->where('pv.page = :pageId')
            ->andWhere('pv.versionNumber = :versionNumber')
            ->setParameter('pageId', $pageId)
            ->setParameter('versionNumber', $versionNumber)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Get version history with pagination
     * 
     * @param int $pageId The page ID
     * @param int $limit Maximum number of versions to return
     * @param int $offset Offset for pagination
     * @return list<PageVersion> Array of PageVersion entities
     */
    public function getVersionHistory(int $pageId, int $limit = 10, int $offset = 0): array
    {
        /** @var list<PageVersion> $result */
        $result = $this->createQueryBuilder('pv')
            ->where('pv.page = :pageId')
            ->setParameter('pageId', $pageId)
            ->orderBy('pv.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Count total versions for a page
     * 
     * @param int $pageId The page ID
     * @return int Total number of versions
     */
    public function countVersionsByPage(int $pageId): int
    {
        return (int) $this->createQueryBuilder('pv')
            ->select('COUNT(pv.id)')
            ->where('pv.page = :pageId')
            ->setParameter('pageId', $pageId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Delete old versions keeping only the last N versions for a page
     * Used for retention policy implementation
     * 
     * @param int $pageId The page ID
     * @param int $keepCount Number of recent versions to keep
     * @return int Number of versions deleted
     */
    public function deleteOldVersions(int $pageId, int $keepCount = 10): int
    {
        // Get IDs of versions to keep (most recent N versions)
        /** @var list<array{id: int|string}> $versionsToKeep */
        $versionsToKeep = $this->createQueryBuilder('pv')
            ->select('pv.id')
            ->where('pv.page = :pageId')
            ->setParameter('pageId', $pageId)
            ->orderBy('pv.versionNumber', 'DESC')
            ->setMaxResults($keepCount)
            ->getQuery()
            ->getResult();

        $idsToKeep = array_map(fn(array $v) => $v['id'], $versionsToKeep);

        if (empty($idsToKeep)) {
            return 0;
        }

        // Delete versions not in the keep list
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('pv')
            ->delete()
            ->where('pv.page = :pageId')
            ->andWhere('pv.id NOT IN (:idsToKeep)')
            ->setParameter('pageId', $pageId)
            ->setParameter('idsToKeep', $idsToKeep)
            ->getQuery()
            ->execute();

        return $deleted;
    }

    /**
     * Get versions created by a specific user
     * 
     * @param int $userId The user ID
     * @param int $limit Maximum number of versions to return
     * @return PageVersion[] Array of PageVersion entities
     */
    public function findByCreatedBy(int $userId, int $limit = 50): array
    {
        /** @var list<PageVersion> $result */
        $result = $this->createQueryBuilder('pv')
            ->where('pv.createdBy = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('pv.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find versions created within a date range
     * 
     * @param \DateTimeInterface $startDate Start date
     * @param \DateTimeInterface $endDate End date
     * @param int|null $pageId Optional page ID filter
     * @return PageVersion[] Array of PageVersion entities
     */
    public function findByDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate, ?int $pageId = null): array
    {
        $qb = $this->createQueryBuilder('pv')
            ->where('pv.createdAt >= :startDate')
            ->andWhere('pv.createdAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        if ($pageId !== null) {
            $qb->andWhere('pv.page = :pageId')
               ->setParameter('pageId', $pageId);
        }

        /** @var list<PageVersion> $result */
        $result = $qb->orderBy('pv.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}

