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
 * F224 (decided 2026-11-20 with the user): the permission catalogue
 * (`GET /admin/permissions`, route `admin_permissions_get_all`) is read by the
 * role editor too. The route passes for `admin.permission.read` (kept) or
 * `admin.role.update`, so a role manager without the catalogue right still
 * sees permission names and which ones they may hand out (`can_assign`).
 *
 * Data-only, generated with `doctrine:migrations:generate` (no schema diff).
 * The generated copies docs/reference/access/api-routes.json and
 * docs/reference/permissions.md are re-exported in the same change (rule 4.14).
 */
final class Version20261120083532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let admin.role.update also read the permission catalogue (admin_permissions_get_all)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.role.update'
            WHERE ar.route_name = 'admin_permissions_get_all'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN api_routes ar ON ar.id = rap.id_api_routes
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE ar.route_name = 'admin_permissions_get_all' AND p.name = 'admin.role.update'
            SQL);
    }
}
