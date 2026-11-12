<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\Asset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * Find assets with pagination and search
     *
     * @param list<int>|null $assetIds Assets the caller may manage: null = all (admin)
     * @return array{assets: list<Asset>, total: int}
     */
    public function findAssetsWithPagination(int $page, int $pageSize, ?string $search = null, ?int $groupId = null, ?array $assetIds = null, ?int $folderId = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.assetType', 'at');

        if ($search) {
            $qb->andWhere('a.name LIKE :search OR a.uuid LIKE :search OR a.alt LIKE :search OR a.mimeType LIKE :search OR at.lookupValue LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($groupId !== null) {
            $this->restrictToGroups($qb, [$groupId], 'filterGroups');
        }

        // 0 = only assets in no folder (the top level).
        if ($folderId === 0) {
            $qb->andWhere('a.folder IS NULL');
        } elseif ($folderId !== null) {
            $qb->andWhere('IDENTITY(a.folder) = :folderId')->setParameter('folderId', $folderId);
        }

        $this->restrictToIds($qb, $assetIds);

        $totalQb = clone $qb;
        $total = $totalQb->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

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
     * How many assets sit directly in each folder.
     *
     * @param list<int>|null $assetIds Assets the caller may manage: null = all (admin)
     * @return array<int, int> folder id => count
     */
    public function countByFolder(?array $assetIds = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.folder) AS folderId', 'COUNT(a.id) AS total')
            ->andWhere('a.folder IS NOT NULL')
            ->groupBy('a.folder');
        $this->restrictToIds($qb, $assetIds);

        /** @var list<array{folderId: int|string, total: int|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['folderId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * All assets in the given folders, whoever may manage them: a folder
     * action must know about every asset it touches, also those the caller
     * cannot see.
     *
     * @param list<int> $folderIds
     * @return list<Asset>
     */
    public function findInFolders(array $folderIds): array
    {
        if ($folderIds === []) {
            return [];
        }

        /** @var list<Asset> $assets */
        $assets = $this->createQueryBuilder('a')
            ->andWhere('IDENTITY(a.folder) IN (:folderIds)')
            ->setParameter('folderIds', $folderIds)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $assets;
    }

    /**
     * How many assets sit directly in a folder, whoever may manage them.
     */
    public function countInFolder(int $folderId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('IDENTITY(a.folder) = :folderId')
            ->setParameter('folderId', $folderId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Other assets with exactly these bytes, newest first.
     *
     * @param list<int>|null $assetIds Assets the caller may manage: null = all (admin)
     * @return list<Asset>
     */
    public function findDuplicates(string $contentHash, int $exceptAssetId, ?array $assetIds, int $limit): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.contentHash = :hash')
            ->andWhere('a.id != :exceptId')
            ->setParameter('hash', $contentHash)
            ->setParameter('exceptId', $exceptAssetId);
        $this->restrictToIds($qb, $assetIds);

        /** @var list<Asset> $assets */
        $assets = $qb->orderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $assets;
    }

    public function findOneByUuid(string $uuid): ?Asset
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * @param list<string> $uuids
     * @return array<string, Asset> keyed by uuid
     */
    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        /** @var list<Asset> $assets */
        $assets = $this->findBy(['uuid' => array_values(array_unique($uuids))]);

        $byUuid = [];
        foreach ($assets as $asset) {
            $byUuid[$asset->getUuid()] = $asset;
        }

        return $byUuid;
    }

    /**
     * @param list<int> $ids
     * @return list<Asset>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<Asset> $result */
        $result = $this->findBy(['id' => array_values(array_unique($ids))]);

        return $result;
    }

    /**
     * Assets to include in an export bundle.
     *
     * @param list<int> $ids Restrict to these assets; empty = all
     * @param list<int>|null $assetIds Assets the caller may manage: null = all (admin)
     * @return list<Asset>
     */
    public function findForExport(array $ids, ?array $assetIds = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.assetType', 'at')
            ->addSelect('at');

        if ($ids !== []) {
            $qb->andWhere('a.id IN (:ids)')
               ->setParameter('ids', $ids);
        }

        $this->restrictToIds($qb, $assetIds);

        /** @var list<Asset> $result */
        $result = $qb->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @param list<int>|null $assetIds null = no restriction; [] matches nothing
     */
    private function restrictToIds(QueryBuilder $qb, ?array $assetIds): void
    {
        if ($assetIds === null) {
            return;
        }
        if ($assetIds === []) {
            $qb->andWhere('1 = 0');

            return;
        }
        $qb->andWhere('a.id IN (:manageableIds)')->setParameter('manageableIds', $assetIds);
    }

    /**
     * Keep only assets tagged with at least one of the given groups (an empty
     * list matches nothing). A subquery keeps the main query one row per asset.
     *
     * @param list<int> $groupIds
     */
    private function restrictToGroups(QueryBuilder $qb, array $groupIds, string $parameter): void
    {
        if ($groupIds === []) {
            $qb->andWhere('1 = 0');

            return;
        }

        $alias = $parameter . 'Asset';
        $groupAlias = $parameter . 'Group';
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select("{$alias}.id")
            ->from(Asset::class, $alias)
            ->innerJoin("{$alias}.groups", $groupAlias)
            ->where("{$groupAlias}.id IN (:{$parameter})");

        $qb->andWhere($qb->expr()->in('a.id', $sub->getDQL()))
           ->setParameter($parameter, $groupIds);
    }
}
