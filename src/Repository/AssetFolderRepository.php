<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Entity\AssetFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssetFolder>
 */
class AssetFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetFolder::class);
    }

    public function findOneByFolder(string $folder): ?AssetFolder
    {
        return $this->findOneBy(['folder' => $folder]);
    }

    /**
     * Every folder, ordered by name for stable admin output.
     *
     * @return list<AssetFolder>
     */
    public function findAllOrdered(): array
    {
        /** @var list<AssetFolder> $result */
        $result = $this->createQueryBuilder('af')
            ->orderBy('af.folder', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The folder names flagged open-access. These are readable by everyone,
     * including anonymous callers, so they are unioned into every caller's
     * visible-folder allow-list.
     *
     * @return list<string>
     */
    public function findOpenAccessFolders(): array
    {
        /** @var list<array{folder: string}> $rows */
        $rows = $this->createQueryBuilder('af')
            ->select('af.folder AS folder')
            ->where('af.isOpenAccess = true')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['folder'], $rows);
    }

    public function isOpenAccess(string $folder): bool
    {
        return $this->findOneByFolder($folder)?->isOpenAccess() === true;
    }
}
