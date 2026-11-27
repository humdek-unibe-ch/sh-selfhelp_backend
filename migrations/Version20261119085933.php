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
 * Role-scope wave (task 2026-11-19-role-scope, part 1): rename/split admin
 * screen permissions, point routes at one read/update right per area (C1), and
 * repair every role that holds a core right without its implied area read (C2).
 *
 * Data-only — generated with `doctrine:migrations:generate` because
 * `make:migration` reported no schema diff.
 */
final class Version20261119085933 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename/split admin screen permissions, fix route rights (C1), repair implied reads (C2)';
    }

    public function up(Schema $schema): void
    {
        // --- Renames (holders and route links stay on the same permission id) ---
        $this->addSql(
            "UPDATE permissions SET name = 'admin.audit.read',"
            . " description = 'Read audit logs and security monitoring data'"
            . " WHERE name = 'admin.audit.view'"
        );
        $this->addSql(
            "UPDATE permissions SET name = 'admin.mobile_preview.read',"
            . " description = 'Can open the full-screen CMS mobile/web live preview'"
            . " WHERE name = 'admin.mobile_preview.view'"
        );

        // --- New permissions ---
        $this->addSql(
            "INSERT IGNORE INTO permissions (name, description) VALUES"
            . " ('admin.language.read', 'Can read CMS languages'),"
            . " ('admin.language.update', 'Can create, update and delete CMS languages'),"
            . " ('admin.section.read', 'Can read unused sections and section utilities that need only read')"
        );

        // Language pair → every role that held admin.settings
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles)
            SELECT p_new.id, rpr.id_roles
            FROM rel_permissions_roles rpr
            JOIN permissions p_old ON p_old.id = rpr.id_permissions AND p_old.name = 'admin.settings'
            JOIN permissions p_new ON p_new.name IN ('admin.language.read', 'admin.language.update')
            SQL);

        // section.read → every role that holds admin.section.delete
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles)
            SELECT p_read.id, rpr.id_roles
            FROM rel_permissions_roles rpr
            JOIN permissions p_del ON p_del.id = rpr.id_permissions AND p_del.name = 'admin.section.delete'
            JOIN permissions p_read ON p_read.name = 'admin.section.read'
            SQL);

        // Superuser role gets all three new names (language pair may already be there)
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles)
            SELECT p.id, r.id
            FROM permissions p
            CROSS JOIN roles r
            WHERE r.is_superuser = 1
              AND p.name IN ('admin.language.read', 'admin.language.update', 'admin.section.read')
            SQL);

        // --- Route permissions (C1) ---
        // Languages: drop admin.settings links, attach read / update
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN api_routes ar ON ar.id = rap.id_api_routes
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE ar.route_name IN (
                'admin_languages_get_all', 'admin_languages_get_one',
                'admin_languages_create', 'admin_languages_update', 'admin_languages_delete'
            )
              AND p.name = 'admin.settings'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.language.read'
            WHERE ar.route_name IN ('admin_languages_get_all', 'admin_languages_get_one')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.language.update'
            WHERE ar.route_name IN (
                'admin_languages_create', 'admin_languages_update', 'admin_languages_delete'
            )
            SQL);

        // Unused sections GET: admin.page.update → admin.section.read
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN api_routes ar ON ar.id = rap.id_api_routes
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE ar.route_name = 'admin_sections_unused_get'
              AND p.name = 'admin.page.update'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.section.read'
            WHERE ar.route_name = 'admin_sections_unused_get'
            SQL);

        // Data-access: GET → admin.role.read only; POST → admin.role.update only
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN api_routes ar ON ar.id = rap.id_api_routes
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE ar.route_name IN (
                'admin_data_access_roles_list',
                'admin_data_access_role_effective_permissions'
            )
              AND p.name = 'admin.role.update'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN api_routes ar ON ar.id = rap.id_api_routes
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE ar.route_name = 'admin_data_access_role_permission_set'
              AND p.name = 'admin.role.read'
            SQL);

        // Drop admin.settings (any leftover links first)
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE p.name = 'admin.settings'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE rpr FROM rel_permissions_roles rpr
            INNER JOIN permissions p ON p.id = rpr.id_permissions
            WHERE p.name = 'admin.settings'
            SQL);
        $this->addSql("DELETE FROM permissions WHERE name = 'admin.settings'");

        // --- C2 repair: every core admin.<area>.<action> implies admin.<area>.read when it exists ---
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles)
            SELECT read_p.id, rpr.id_roles
            FROM rel_permissions_roles rpr
            INNER JOIN permissions held ON held.id = rpr.id_permissions
            INNER JOIN permissions read_p ON read_p.name = CONCAT(
                SUBSTRING_INDEX(held.name, '.', 2),
                '.read'
            )
            WHERE held.name REGEXP '^admin\\.[^.]+\\.[^.]+$'
              AND SUBSTRING_INDEX(held.name, '.', -1) <> 'read'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Restores the old permission names, route links and settings rows.
        // Does NOT remove implied-read links added by the C2 repair in up().

        // Restore admin.settings and give it to roles that currently hold either language right
        $this->addSql(
            "INSERT IGNORE INTO permissions (name, description)"
            . " VALUES ('admin.settings', 'Full access to CMS settings')"
        );
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles)
            SELECT p_settings.id, holders.id_roles
            FROM (
                SELECT DISTINCT rpr.id_roles
                FROM rel_permissions_roles rpr
                JOIN permissions p ON p.id = rpr.id_permissions
                WHERE p.name IN ('admin.language.read', 'admin.language.update')
            ) holders
            CROSS JOIN permissions p_settings
            WHERE p_settings.name = 'admin.settings'
            SQL);

        // Language routes back to admin.settings
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN api_routes ar ON ar.id = rap.id_api_routes
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE ar.route_name IN (
                'admin_languages_get_all', 'admin_languages_get_one',
                'admin_languages_create', 'admin_languages_update', 'admin_languages_delete'
            )
              AND p.name IN ('admin.language.read', 'admin.language.update')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.settings'
            WHERE ar.route_name IN (
                'admin_languages_get_all', 'admin_languages_get_one',
                'admin_languages_create', 'admin_languages_update', 'admin_languages_delete'
            )
            SQL);

        // Unused sections GET back to admin.page.update
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN api_routes ar ON ar.id = rap.id_api_routes
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE ar.route_name = 'admin_sections_unused_get'
              AND p.name = 'admin.section.read'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.page.update'
            WHERE ar.route_name = 'admin_sections_unused_get'
            SQL);

        // Data-access: restore dual permissions on each route
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name IN ('admin.role.read', 'admin.role.update')
            WHERE ar.route_name IN (
                'admin_data_access_roles_list',
                'admin_data_access_role_effective_permissions',
                'admin_data_access_role_permission_set'
            )
            SQL);

        // Drop new language / section.read permissions (and their role links)
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE p.name IN ('admin.language.read', 'admin.language.update', 'admin.section.read')
            SQL);
        $this->addSql(<<<'SQL'
            DELETE rpr FROM rel_permissions_roles rpr
            INNER JOIN permissions p ON p.id = rpr.id_permissions
            WHERE p.name IN ('admin.language.read', 'admin.language.update', 'admin.section.read')
            SQL);
        $this->addSql(
            "DELETE FROM permissions WHERE name IN"
            . " ('admin.language.read', 'admin.language.update', 'admin.section.read')"
        );

        // Rename back (route links stay on the same ids)
        $this->addSql(
            "UPDATE permissions SET name = 'admin.audit.view',"
            . " description = 'View audit logs and security monitoring data'"
            . " WHERE name = 'admin.audit.read'"
        );
        $this->addSql(
            "UPDATE permissions SET name = 'admin.mobile_preview.view',"
            . " description = 'Can open the full-screen CMS mobile/web live preview'"
            . " WHERE name = 'admin.mobile_preview.read'"
        );
    }
}
