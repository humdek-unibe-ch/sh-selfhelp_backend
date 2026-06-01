<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Repository\Plugin;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginFeatureFlag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PluginFeatureFlag>
 */
class PluginFeatureFlagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PluginFeatureFlag::class);
    }

    public function findOneByScope(Plugin $plugin, string $flagKey, string $scope, string $scopeValue = ''): ?PluginFeatureFlag
    {
        return $this->findOneBy([
            'plugin' => $plugin,
            'flagKey' => $flagKey,
            'scope' => $scope,
            'scopeValue' => $scopeValue,
        ]);
    }

    public function findOneByKey(Plugin $plugin, string $flagKey, string $scope = PluginFeatureFlag::SCOPE_GLOBAL, ?string $scopeValue = null): ?PluginFeatureFlag
    {
        return $this->findOneByScope($plugin, $flagKey, $scope, $scopeValue ?? '');
    }

    /** @return list<PluginFeatureFlag> */
    public function findGlobalFlags(Plugin $plugin): array
    {
        return array_values($this->findBy([
            'plugin' => $plugin,
            'scope' => PluginFeatureFlag::SCOPE_GLOBAL,
        ]));
    }

    /** @return list<PluginFeatureFlag> */
    public function findByPlugin(Plugin $plugin): array
    {
        return array_values($this->findBy(['plugin' => $plugin]));
    }
}
