<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\AssetFolderGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssetFolderGroup>
 */
class AssetFolderGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetFolderGroup::class);
    }

    /**
     * All ACL entries for a single folder, ordered by group id for stable output.
     *
     * @return list<AssetFolderGroup>
     */
    public function findByFolder(string $folder): array
    {
        /** @var list<AssetFolderGroup> $result */
        $result = $this->createQueryBuilder('afg')
            ->innerJoin('afg.group', 'g')
            ->addSelect('g')
            ->where('afg.folder = :folder')
            ->setParameter('folder', $folder)
            ->orderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * All ACL entries for a single group, ordered by folder for stable output.
     *
     * @return list<AssetFolderGroup>
     */
    public function findByGroup(int $groupId): array
    {
        /** @var list<AssetFolderGroup> $result */
        $result = $this->createQueryBuilder('afg')
            ->innerJoin('afg.group', 'g')
            ->where('g.id = :groupId')
            ->setParameter('groupId', $groupId)
            ->orderBy('afg.folder', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The set of folder strings that currently have at least one ACL entry.
     *
     * @return list<string>
     */
    public function findRestrictedFolders(): array
    {
        /** @var list<array{folder: string}> $rows */
        $rows = $this->createQueryBuilder('afg')
            ->select('DISTINCT afg.folder AS folder')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['folder'], $rows);
    }

    /**
     * The distinct folders any of the given groups is granted on (read or
     * manage). Used as the visibility allow-list for closed-by-default scoping.
     *
     * @param list<int> $groupIds
     * @return list<string>
     */
    public function findFoldersGrantedToGroups(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        /** @var list<array{folder: string}> $rows */
        $rows = $this->createQueryBuilder('afg')
            ->select('DISTINCT afg.folder AS folder')
            ->innerJoin('afg.group', 'g')
            ->where('g.id IN (:groupIds)')
            ->setParameter('groupIds', $groupIds)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['folder'], $rows);
    }

    /**
     * Access levels the given groups hold on a folder. Returns every matching
     * row so the caller can reduce them to the strongest grant.
     *
     * @param list<int> $groupIds
     * @return list<string>
     */
    public function findAccessLevelsForGroups(string $folder, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        /** @var list<array{access_level: string}> $rows */
        $rows = $this->createQueryBuilder('afg')
            ->select('afg.accessLevel AS access_level')
            ->innerJoin('afg.group', 'g')
            ->where('afg.folder = :folder')
            ->andWhere('g.id IN (:groupIds)')
            ->setParameter('folder', $folder)
            ->setParameter('groupIds', $groupIds)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['access_level'], $rows);
    }
}
