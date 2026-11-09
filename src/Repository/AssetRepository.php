<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\Asset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Asset>
 */
class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    /**
     * Find all assets
     * 
     * @return Asset[]
     */
    public function findAllAssets(): array
    {
        /** @var list<Asset> $result */
        $result = $this->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find assets with pagination and search
     * 
     * @param int $page
     * @param int $pageSize
     * @param string|null $search
     * @param string|null $folder
     * @param list<string>|null $visibleFolders Closed-by-default allow-list: null = no restriction (admin);
     *        a list = restrict to those folders; an empty list = no folders visible
     * @return array{assets: list<Asset>, total: int}
     */
    public function findAssetsWithPagination(int $page, int $pageSize, ?string $search = null, ?string $folder = null, ?array $visibleFolders = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.assetType', 'at');

        // Add search conditions
        if ($search) {
            $qb->andWhere('a.fileName LIKE :search OR a.folder LIKE :search OR at.lookupValue LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Add folder filter
        if ($folder) {
            $qb->andWhere('a.folder = :folder')
               ->setParameter('folder', $folder);
        }

        // Closed-by-default ACL scoping (null = admin, no restriction).
        if ($visibleFolders !== null) {
            if ($visibleFolders === []) {
                // Non-admin with no granted folders sees nothing.
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('a.folder IN (:visibleFolders)')
                   ->setParameter('visibleFolders', $visibleFolders);
            }
        }

        // Get total count
        $totalQb = clone $qb;
        $total = $totalQb->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Apply pagination
        /** @var list<Asset> $assets */
        $assets = $qb->orderBy('a.id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return [
            'assets' => $assets,
            'total' => (int) $total
        ];
    }

    /**
     * Find asset by filename
     * 
     * @param string $fileName
     * @return Asset|null
     */
    public function findByFileName(string $fileName): ?Asset
    {
        /** @var Asset|null $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.fileName = :fileName')
            ->setParameter('fileName', $fileName)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Find assets by folder
     * 
     * @param string $folder
     * @return Asset[]
     */
    public function findByFolder(string $folder): array
    {
        /** @var list<Asset> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.folder = :folder')
            ->setParameter('folder', $folder)
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Check if multiple filenames exist
     * 
     * @param list<string> $fileNames
     * @return Asset[]
     */
    public function findByFileNames(array $fileNames): array
    {
        /** @var list<Asset> $result */
        $result = $this->createQueryBuilder('a')
            ->where('a.fileName IN (:fileNames)')
            ->setParameter('fileNames', $fileNames)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Assets to include in an export bundle.
     *
     * @param list<string> $folders        Restrict to these folders; empty = all folders
     * @param list<string>|null $visibleFolders Closed-by-default allow-list: null = no restriction (admin);
     *        a list = restrict to those folders; an empty list = no folders visible
     * @return list<Asset>
     */
    public function findForExport(array $folders, ?array $visibleFolders = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.assetType', 'at')
            ->addSelect('at');

        if ($folders !== []) {
            $qb->andWhere('a.folder IN (:folders)')
               ->setParameter('folders', $folders);
        }

        // Closed-by-default ACL scoping (null = admin, no restriction).
        if ($visibleFolders !== null) {
            if ($visibleFolders === []) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('a.folder IN (:visibleFolders)')
                   ->setParameter('visibleFolders', $visibleFolders);
            }
        }

        /** @var list<Asset> $result */
        $result = $qb->orderBy('a.folder', 'ASC')
            ->addOrderBy('a.fileName', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
