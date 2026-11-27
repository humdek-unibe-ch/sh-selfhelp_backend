<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Repository\Plugin;

use App\Entity\Plugin\PluginSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads and deletes the stored values of plugin settings (contract C14).
 *
 * Every read asks the database and refreshes the rows the entity manager already holds: a worker that
 * lives for hours must see a value that an administrator changed in another process.
 *
 * @extends ServiceEntityRepository<PluginSetting>
 */
class PluginSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PluginSetting::class);
    }

    /**
     * The stored values of a plugin by setting key.
     *
     * @return array<string, PluginSetting>
     */
    public function findByPluginId(string $pluginId): array
    {
        /** @var list<PluginSetting> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where('s.pluginId = :pluginId')
            ->setParameter('pluginId', $pluginId)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->getSettingKey()] = $row;
        }

        return $byKey;
    }

    public function findOneByPluginIdAndKey(string $pluginId, string $key): ?PluginSetting
    {
        $row = $this->createQueryBuilder('s')
            ->where('s.pluginId = :pluginId')
            ->andWhere('s.settingKey = :key')
            ->setParameter('pluginId', $pluginId)
            ->setParameter('key', $key)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row instanceof PluginSetting ? $row : null;
    }

    /**
     * Delete every stored value of a plugin.
     *
     * @return int how many rows were deleted
     */
    public function deleteByPluginId(string $pluginId): int
    {
        $rows = $this->findByPluginId($pluginId);
        foreach ($rows as $row) {
            $this->getEntityManager()->remove($row);
        }
        $this->getEntityManager()->flush();

        return count($rows);
    }
}
