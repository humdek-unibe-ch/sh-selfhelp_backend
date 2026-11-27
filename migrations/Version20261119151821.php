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
 * Permission catalogue cleanup (decided 2026-11-19 with the user):
 *
 * - `admin.action_translation.read` guarded only the read of an action's
 *   translations, which are written through `admin.action.update`: the route
 *   moves to `admin.action.read`, roles that held it get `admin.action.read`,
 *   and the permission is removed.
 * - Removed because no route or check used them: `admin.page_version.create`
 *   (versions are created by publishing), `admin.page_version.unpublish`
 *   (unpublishing needs `admin.page_version.publish`), `admin.user.unblock`
 *   (the block route needs `admin.user.block` both ways),
 *   `admin.cms_preferences.update` (preferences are edited on their
 *   configuration page: `admin.page.update` and the grant on that page).
 *
 * Data-only — generated with `doctrine:migrations:generate` (no schema diff).
 * down() brings the permissions back for the superuser role and puts the
 * translations route back on its own permission; who else held them is not kept.
 */
final class Version20261119151821 extends AbstractMigration
{
    private const REMOVED = [
        'admin.action_translation.read' => 'Can read action translations',
        'admin.page_version.create' => 'Can create page versions',
        'admin.page_version.unpublish' => 'Can unpublish page versions',
        'admin.user.unblock' => 'Can unblock users',
        'admin.cms_preferences.update' => 'Can update CMS preferences',
    ];

    public function getDescription(): string
    {
        return 'Fold admin.action_translation.read into admin.action.read; remove four permissions that guard nothing';
    }

    public function up(Schema $schema): void
    {
        // Holders of the translations read right keep reading through admin.action.read.
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles)
            SELECT p_new.id, rpr.id_roles
            FROM rel_permissions_roles rpr
            JOIN permissions p_old ON p_old.id = rpr.id_permissions AND p_old.name = 'admin.action_translation.read'
            JOIN permissions p_new ON p_new.name = 'admin.action.read'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.action.read'
            WHERE ar.route_name = 'admin_actions_translations_get_all'
            SQL);

        $names = $this->quotedNames();
        $this->addSql('DELETE rpr FROM rel_permissions_roles rpr INNER JOIN permissions p ON p.id = rpr.id_permissions WHERE p.name IN (' . $names . ')');
        $this->addSql('DELETE rap FROM rel_api_routes_permissions rap INNER JOIN permissions p ON p.id = rap.id_permissions WHERE p.name IN (' . $names . ')');
        $this->addSql('DELETE FROM permissions WHERE name IN (' . $names . ')');
    }

    public function down(Schema $schema): void
    {
        foreach (self::REMOVED as $name => $description) {
            $this->addSql('INSERT IGNORE INTO permissions (name, description) VALUES (?, ?)', [$name, $description]);
        }
        $this->addSql(
            'INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles)'
            . ' SELECT p.id, r.id FROM permissions p CROSS JOIN roles r'
            . ' WHERE r.is_superuser = 1 AND p.name IN (' . $this->quotedNames() . ')'
        );
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN api_routes ar ON ar.id = rap.id_api_routes
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE ar.route_name = 'admin_actions_translations_get_all' AND p.name = 'admin.action.read'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.action_translation.read'
            WHERE ar.route_name = 'admin_actions_translations_get_all'
            SQL);
    }

    private function quotedNames(): string
    {
        return "'" . implode("', '", array_keys(self::REMOVED)) . "'";
    }
}
