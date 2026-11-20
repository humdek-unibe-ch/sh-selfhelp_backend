<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Access;

/**
 * Pure rendering of the generated access files (finding F239, rule 4.14).
 *
 * - `docs/reference/access/api-routes.json`: the core route table (method,
 *   path, public flag, permissions) and every core permission with its
 *   description. The web's route contract test compares against it.
 * - `docs/reference/permissions.md`: the permission catalogue, one section
 *   per core permission with its routes and the admin screens it opens. The
 *   screens come from the frontend's generated `admin-screens.json`.
 *
 * Output is byte-stable: fixed key order, sorted lists, two-space JSON
 * indentation, `\n` line ends, a trailing newline and no timestamps, so a
 * byte comparison is the staleness check. No database access here; see
 * {@see AccessRouteTableReader} for the input.
 *
 * @phpstan-type AccessRoute array{name: string, methods: list<string>, path: string, is_public: bool, permissions: list<string>}
 * @phpstan-type AccessPermission array{name: string, description: string}
 * @phpstan-type AccessRouteTable array{generated_by: string, routes: list<AccessRoute>, permissions: list<AccessPermission>}
 * @phpstan-type AdminScreen array{id: string, label: string, path: string, group: string, read_permission: string}
 */
final class AccessCatalogueRenderer
{
    public const ROUTES_GENERATED_BY = 'php bin/console app:access:export';

    /**
     * Normalise and sort raw rows into the route table structure.
     *
     * @param list<AccessRoute> $routes
     * @param list<AccessPermission> $permissions
     * @return AccessRouteTable
     */
    public function buildRouteTable(array $routes, array $permissions): array
    {
        $normalisedRoutes = [];
        foreach ($routes as $route) {
            $methods = array_values(array_unique(array_map('strtoupper', $route['methods'])));
            sort($methods, SORT_STRING);
            $routePermissions = array_values(array_unique($route['permissions']));
            sort($routePermissions, SORT_STRING);
            $normalisedRoutes[] = [
                'name' => $route['name'],
                'methods' => $methods,
                'path' => $route['path'],
                'is_public' => $route['is_public'],
                'permissions' => $routePermissions,
            ];
        }
        usort(
            $normalisedRoutes,
            static fn (array $a, array $b): int => [$a['path'], $a['name']] <=> [$b['path'], $b['name']]
        );

        $normalisedPermissions = [];
        foreach ($permissions as $permission) {
            $normalisedPermissions[] = ['name' => $permission['name'], 'description' => $permission['description']];
        }
        usort(
            $normalisedPermissions,
            static fn (array $a, array $b): int => strcmp($a['name'], $b['name'])
        );

        return [
            'generated_by' => self::ROUTES_GENERATED_BY,
            'routes' => $normalisedRoutes,
            'permissions' => $normalisedPermissions,
        ];
    }

    /**
     * @param AccessRouteTable $table
     */
    public function renderRouteTableJson(array $table): string
    {
        $json = json_encode($table, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // json_encode indents with four spaces; the contract uses two.
        $json = (string) preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)),
            $json
        );

        return $json . "\n";
    }

    /**
     * Parse the frontend's generated `admin-screens.json`.
     *
     * @return list<AdminScreen>
     *
     * @throws \RuntimeException when the content is not the agreed shape
     */
    public function parseScreens(string $json, string $sourcePath): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Admin screens file %s is not valid JSON: %s', $sourcePath, $e->getMessage()), 0, $e);
        }
        if (!is_array($data) || !isset($data['screens']) || !is_array($data['screens'])) {
            throw new \RuntimeException(sprintf('Admin screens file %s has no "screens" list.', $sourcePath));
        }

        $screens = [];
        foreach ($data['screens'] as $index => $screen) {
            if (!is_array($screen)) {
                throw new \RuntimeException(sprintf('Admin screens file %s: entry %s is not an object.', $sourcePath, (string) $index));
            }
            $fields = [];
            foreach (['id', 'label', 'path', 'group', 'read_permission'] as $key) {
                $value = $screen[$key] ?? null;
                if (!is_string($value) || $value === '') {
                    throw new \RuntimeException(sprintf('Admin screens file %s: entry %s has no "%s".', $sourcePath, (string) $index, $key));
                }
                $fields[$key] = $value;
            }
            $screens[] = [
                'id' => $fields['id'],
                'label' => $fields['label'],
                'path' => $fields['path'],
                'group' => $fields['group'],
                'read_permission' => $fields['read_permission'],
            ];
        }

        return $screens;
    }

    /**
     * Core permissions that guard no route and open no screen (rule 4.14).
     *
     * @param AccessRouteTable $table
     * @param list<AdminScreen> $screens
     * @return list<string> permission names, sorted
     */
    public function findUnguardedPermissions(array $table, array $screens): array
    {
        $used = [];
        foreach ($table['routes'] as $route) {
            foreach ($route['permissions'] as $name) {
                $used[$name] = true;
            }
        }
        foreach ($screens as $screen) {
            $used[$screen['read_permission']] = true;
        }

        $unguarded = [];
        foreach ($table['permissions'] as $permission) {
            if (!isset($used[$permission['name']])) {
                $unguarded[] = $permission['name'];
            }
        }
        sort($unguarded, SORT_STRING);

        return $unguarded;
    }

    /**
     * @param AccessRouteTable $table
     * @param list<AdminScreen> $screens
     */
    public function renderCatalogue(array $table, array $screens, string $coreVersion): string
    {
        $routesByPermission = [];
        foreach ($table['routes'] as $route) {
            foreach ($route['permissions'] as $name) {
                $routesByPermission[$name][] = sprintf('`%s %s`', implode(',', $route['methods']), $route['path']);
            }
        }
        $screensByPermission = [];
        $sortedScreens = $screens;
        usort($sortedScreens, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        foreach ($sortedScreens as $screen) {
            $screensByPermission[$screen['read_permission']][] = sprintf('%s (`%s`)', $screen['label'], $screen['path']);
        }

        $lines = [
            '# Permission catalogue',
            '',
            'Audience: Developers and administrators.',
            'Status: generated.',
            sprintf('Applies to: SelfHelp2 core %s.', $coreVersion),
            'Last verified: generated, see Source of truth.',
            'Source of truth: the core rows of `api_routes`, `rel_api_routes_permissions` and `permissions`, plus `sh-selfhelp_frontend/docs/reference/admin-screens.json`; generated by `php bin/console app:access:export`. Do not edit by hand: CI fails when this page is stale.',
            '',
            'Every core permission, what it is for, the API routes it unlocks and the admin screens it opens.',
            'A route accepts any one of its permissions. Public routes need none; they are listed in',
            '[access/api-routes.json](access/api-routes.json). Every permission guards at least one route or',
            'screen (rule 4.14); CI fails otherwise.',
        ];

        foreach ($table['permissions'] as $permission) {
            $name = $permission['name'];
            $lines[] = '';
            $lines[] = sprintf('## `%s`', $name);
            $lines[] = '';
            $lines[] = $permission['description'] !== '' ? $permission['description'] : '(no description)';
            $lines[] = '';
            $lines[] = 'Routes:';
            $lines[] = '';
            foreach ($routesByPermission[$name] ?? ['none'] as $routeLine) {
                $lines[] = '- ' . $routeLine;
            }
            $lines[] = '';
            $lines[] = 'Screens:';
            $lines[] = '';
            foreach ($screensByPermission[$name] ?? ['none'] as $screenLine) {
                $lines[] = '- ' . $screenLine;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
