<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Repository\Plugin;

use App\Entity\Plugin\Plugin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Plugin>
 */
class PluginRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plugin::class);
    }

    public function findOneByPluginId(string $pluginId): ?Plugin
    {
        return $this->findOneBy(['pluginId' => $pluginId]);
    }

    /** @return list<Plugin> */
    public function findEnabled(): array
    {
        return array_values($this->findBy(['enabled' => true]));
    }

    /** @return list<Plugin> */
    public function findAllOrderedByName(): array
    {
        return array_values($this->findBy([], ['name' => 'ASC']));
    }
}
