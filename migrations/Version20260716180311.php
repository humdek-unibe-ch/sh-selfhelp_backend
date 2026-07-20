<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Register the Users Management admin routes: status/count tiles, bulk
 * delete / add-to-group / send-activation, and CSV export/import.
 *
 * The `status` + `id_groups` filters on GET /admin/users are query params on
 * the existing `admin_users_get_all` route, so they need no route row.
 *
 * Route ordering note: `/admin/users/stats` and `/admin/users/export` are
 * literal paths that could collide with the existing `/admin/users/{userId}`
 * route. Two things prevent that: `admin_users_get_one_v1` constrains
 * `userId` to `[0-9]+`, and `ApiRouteLoader` registers static paths before
 * dynamic ones. No requirements are needed on the new literal routes.
 */
final class Version20260716180311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed api_routes + permissions for admin user stats, bulk operations, and CSV export/import.';
    }

    public function up(Schema $schema): void
    {
        // (1) GET /admin/users/stats — tiles for the Users Management page.
        //     Takes no params: the tiles describe the caller's whole visible
        //     population, deliberately ignoring the list's active filters.
        $this->addRoute(
            'admin_users_stats_v1',
            'GET',
            '/admin/users/stats',
            'AdminUserController::getUserStats',
            'admin.user.read'
        );

        // (2) POST /admin/users/bulk-delete
        $this->addRoute(
            'admin_users_bulk_delete_v1',
            'POST',
            '/admin/users/bulk-delete',
            'AdminUserController::bulkDeleteUsers',
            'admin.user.delete',
            "JSON_OBJECT('user_ids', JSON_OBJECT('in', 'body', 'required', true))"
        );

        // (3) POST /admin/users/bulk-add-to-group
        $this->addRoute(
            'admin_users_bulk_add_to_group_v1',
            'POST',
            '/admin/users/bulk-add-to-group',
            'AdminUserController::bulkAddUsersToGroups',
            'admin.user.update',
            "JSON_OBJECT('user_ids', JSON_OBJECT('in', 'body', 'required', true), 'group_ids', JSON_OBJECT('in', 'body', 'required', true))"
        );

        // (4) POST /admin/users/bulk-send-activation
        $this->addRoute(
            'admin_users_bulk_send_activation_v1',
            'POST',
            '/admin/users/bulk-send-activation',
            'AdminUserController::bulkSendActivationMail',
            'admin.user.update',
            "JSON_OBJECT('user_ids', JSON_OBJECT('in', 'body', 'required', true))"
        );

        // (5) GET /admin/users/export — raw CSV, not the JSON envelope.
        //     Honours the same filters as the list (no pagination: the full
        //     filtered set).
        $this->addRoute(
            'admin_users_export_v1',
            'GET',
            '/admin/users/export',
            'AdminUserController::exportUsers',
            'admin.user.read',
            "JSON_OBJECT('search', JSON_OBJECT('in', 'query', 'required', false), 'status', JSON_OBJECT('in', 'query', 'required', false), 'id_groups', JSON_OBJECT('in', 'query', 'required', false))"
        );

        // (6) POST /admin/users/import — multipart CSV upload.
        $this->addRoute(
            'admin_users_import_v1',
            'POST',
            '/admin/users/import',
            'AdminUserController::importUsers',
            'admin.user.create'
        );

        // (7) The `status` + `id_groups` filters are new query params on the
        //     existing list route. Re-declare its params so the route metadata
        //     documents them alongside the pagination/search params.
        //
        //     The list route is named `admin_users_get_all` (no `_v1` suffix) —
        //     the suffix convention is not universal in `api_routes`, so match
        //     on the path/method instead of guessing the name.
        $this->addSql(<<<SQL
            UPDATE `api_routes`
            SET `params` = JSON_OBJECT(
                'page', JSON_OBJECT('in', 'query', 'required', false),
                'pageSize', JSON_OBJECT('in', 'query', 'required', false),
                'search', JSON_OBJECT('in', 'query', 'required', false),
                'sort', JSON_OBJECT('in', 'query', 'required', false),
                'sortDirection', JSON_OBJECT('in', 'query', 'required', false),
                'status', JSON_OBJECT('in', 'query', 'required', false),
                'id_groups', JSON_OBJECT('in', 'query', 'required', false)
            )
            WHERE `path` = '/admin/users'
              AND `methods` = 'GET'
              AND `version` = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $routeNames = "'admin_users_stats_v1', 'admin_users_bulk_delete_v1', 'admin_users_bulk_add_to_group_v1', 'admin_users_bulk_send_activation_v1', 'admin_users_export_v1', 'admin_users_import_v1'";

        $this->addSql(<<<SQL
            DELETE rap FROM `rel_api_routes_permissions` rap
            JOIN `api_routes` ar ON ar.id = rap.id_api_routes
            WHERE ar.route_name IN ({$routeNames})
              AND ar.version = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE route_name IN ({$routeNames})
              AND version = 'v1'
        SQL);

        // Restore the list route's params to their pre-migration shape
        // (without `status` / `id_groups`).
        $this->addSql(<<<SQL
            UPDATE `api_routes`
            SET `params` = JSON_OBJECT(
                'page', JSON_OBJECT('in', 'query', 'required', false),
                'pageSize', JSON_OBJECT('in', 'query', 'required', false),
                'search', JSON_OBJECT('in', 'query', 'required', false),
                'sort', JSON_OBJECT('in', 'query', 'required', false),
                'sortDirection', JSON_OBJECT('in', 'query', 'required', false)
            )
            WHERE `path` = '/admin/users'
              AND `methods` = 'GET'
              AND `version` = 'v1'
        SQL);
    }

    /**
     * Insert one v1 API route and link it to a single permission.
     *
     * Both statements INSERT IGNORE on their unique keys so the migration is
     * safe to re-run.
     *
     * @param string|null $paramsSql raw `JSON_OBJECT(...)` expression, or null for no params
     */
    private function addRoute(string $routeName, string $method, string $path, string $controller, string $permission, ?string $paramsSql = null): void
    {
        $fqcn = 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\' . $controller;
        $params = $paramsSql ?? 'NULL';

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                '{$routeName}',
                'v1',
                '{$method}',
                '{$path}',
                '{$fqcn}',
                NULL,
                {$params}
            )
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = '{$permission}'
            WHERE ar.route_name = '{$routeName}'
              AND ar.version = 'v1'
        SQL);
    }
}
