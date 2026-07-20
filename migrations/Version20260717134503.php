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
 * Register POST /admin/users/bulk-remove-from-group, the counterpart to the
 * bulk add-to-group route seeded by Version20260716180311.
 *
 * Same permission (`admin.user.update`) and same request shape as bulk-add —
 * only the verb differs — so the two share one JSON schema
 * (`requests/admin/bulk_group_membership`).
 *
 * A separate migration rather than an edit to Version20260716180311: that one
 * is already applied, and an applied migration must not be rewritten for new
 * behaviour.
 */
final class Version20260717134503 extends AbstractMigration
{
    private const ROUTE_NAME = 'admin_users_bulk_remove_from_group_v1';

    public function getDescription(): string
    {
        return 'Seed the api_route + permission link for POST /admin/users/bulk-remove-from-group.';
    }

    public function up(Schema $schema): void
    {
        $controller = 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminUserController::bulkRemoveUsersFromGroups';
        $routeName = self::ROUTE_NAME;

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                '{$routeName}',
                'v1',
                'POST',
                '/admin/users/bulk-remove-from-group',
                '{$controller}',
                NULL,
                JSON_OBJECT(
                    'user_ids', JSON_OBJECT('in', 'body', 'required', true),
                    'group_ids', JSON_OBJECT('in', 'body', 'required', true)
                )
            )
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = 'admin.user.update'
            WHERE ar.route_name = '{$routeName}'
              AND ar.version = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $routeName = self::ROUTE_NAME;

        $this->addSql(<<<SQL
            DELETE rap FROM `rel_api_routes_permissions` rap
            JOIN `api_routes` ar ON ar.id = rap.id_api_routes
            WHERE ar.route_name = '{$routeName}'
              AND ar.version = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE route_name = '{$routeName}'
              AND version = 'v1'
        SQL);
    }
}
