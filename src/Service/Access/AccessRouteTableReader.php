<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Access;

use Doctrine\DBAL\Connection;

/**
 * Reads the core route table and the core permissions from the database
 * (`api_routes`, `rel_api_routes_permissions`, `permissions`, rows with
 * `id_plugins IS NULL`). Plugin routes and permissions are left out: they
 * come with the plugin work (F238).
 *
 * @phpstan-import-type AccessRouteTable from AccessCatalogueRenderer
 */
final class AccessRouteTableReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AccessCatalogueRenderer $renderer,
    ) {
    }

    /**
     * @return AccessRouteTable
     */
    public function read(): array
    {
        $permissionsByRoute = [];
        $links = $this->connection->fetchAllAssociative(
            'SELECT rp.id_api_routes AS route_id, p.name AS name
               FROM rel_api_routes_permissions rp
               JOIN permissions p ON p.id = rp.id_permissions
               JOIN api_routes r ON r.id = rp.id_api_routes
              WHERE r.id_plugins IS NULL'
        );
        foreach ($links as $link) {
            $permissionsByRoute[$this->toInt($link['route_id'] ?? null)][] = $this->toString($link['name'] ?? null);
        }

        $routes = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, route_name, path, methods, is_public FROM api_routes WHERE id_plugins IS NULL'
        );
        foreach ($rows as $row) {
            $methods = array_values(array_filter(
                array_map('trim', explode(',', $this->toString($row['methods'] ?? null))),
                static fn (string $m): bool => $m !== ''
            ));
            $routes[] = [
                'name' => $this->toString($row['route_name'] ?? null),
                'methods' => $methods,
                'path' => $this->toString($row['path'] ?? null),
                'is_public' => (bool) $this->toInt($row['is_public'] ?? null),
                'permissions' => $permissionsByRoute[$this->toInt($row['id'] ?? null)] ?? [],
            ];
        }

        $permissions = [];
        $permissionRows = $this->connection->fetchAllAssociative(
            'SELECT name, description FROM permissions WHERE id_plugins IS NULL'
        );
        foreach ($permissionRows as $row) {
            $permissions[] = [
                'name' => $this->toString($row['name'] ?? null),
                'description' => $this->toString($row['description'] ?? null),
            ];
        }

        return $this->renderer->buildRouteTable($routes, $permissions);
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
