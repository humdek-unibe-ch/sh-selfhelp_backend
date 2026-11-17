<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\Lookup;
use App\Plugin\Event\DataAccessResourcesEvent;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Data-access resource types that enabled plugins add (H6), for the
 * "Permissions for role" screen: the types next to pages, data tables,
 * assets and groups, and the resources of one type. A plugin declares the
 * type as an entry of the `resourceTypes` lookup in its manifest (written by
 * the installer, tagged with the plugin) and lists its resources through
 * {@see DataAccessResourcesEvent}.
 */
class PluginResourceTypeService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return list<array{id: int, code: string, label: string, plugin_id: string}>
     */
    public function listTypes(): array
    {
        /** @var list<Lookup> $lookups */
        $lookups = $this->entityManager->createQueryBuilder()
            ->select('l', 'p')
            ->from(Lookup::class, 'l')
            ->innerJoin('l.plugin', 'p')
            ->where('l.typeCode = :type')
            ->andWhere('p.enabled = true')
            ->setParameter('type', LookupService::RESOURCE_TYPES)
            ->orderBy('l.lookupValue', 'ASC')
            ->getQuery()
            ->getResult();

        $types = [];
        foreach ($lookups as $lookup) {
            $code = $lookup->getLookupCode();
            $plugin = $lookup->getPlugin();
            if ($code === null || $plugin === null) {
                continue;
            }
            $types[] = [
                'id' => (int) $lookup->getId(),
                'code' => $code,
                'label' => $lookup->getLookupValue() ?? $code,
                'plugin_id' => $plugin->getPluginId(),
            ];
        }

        return $types;
    }

    /**
     * @return list<array{id: int, name: string, description: string|null}>
     * @throws \App\Exception\ServiceException 404 when no enabled plugin declares the type
     */
    public function listResources(string $typeCode): array
    {
        $declared = array_filter($this->listTypes(), static fn (array $type): bool => $type['code'] === $typeCode);
        if ($declared === []) {
            $this->throwNotFound('Unknown plugin resource type');
        }

        $event = new DataAccessResourcesEvent($typeCode);
        $this->eventDispatcher->dispatch($event);

        return $event->getResources();
    }
}
