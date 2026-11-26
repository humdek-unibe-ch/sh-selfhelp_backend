<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when the "Permissions for role" screen lists the resources of a
 * plugin's data-access resource type (H6). The plugin that declared the type
 * (an entry of the `resourceTypes` lookup in `plugin.json#lookups`) listens
 * and adds every resource of that type through {@see addResource()}, for
 * example each survey. Grants are stored in `role_data_access` and checked by
 * {@see \App\Service\Security\DataAccessSecurityService::hasPermission()} with
 * the type code, like pages and data tables.
 */
final class DataAccessResourcesEvent extends Event
{
    /** @var array<int, array{id: int, name: string, description: string|null}> */
    private array $resources = [];

    public function __construct(public readonly string $resourceTypeCode)
    {
    }

    public function addResource(int $id, string $name, ?string $description = null): void
    {
        $this->resources[$id] = ['id' => $id, 'name' => $name, 'description' => $description];
    }

    /** @return list<array{id: int, name: string, description: string|null}> */
    public function getResources(): array
    {
        $resources = array_values($this->resources);
        usort($resources, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $resources;
    }
}
