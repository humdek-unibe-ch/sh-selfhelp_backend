<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Support\MigrationRoundTripTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Round-trip for Version20261119085933 (role-scope C1/C2): permission renames,
 * route rights, implied-read repair. `down()` restores the old names and links
 * but keeps implied reads added by the repair.
 */
#[Group('migration')]
final class Version20261119085933RoundTripTest extends MigrationRoundTripTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261119085933';

    public function testPermissionRenameAndRouteRightsRoundTrip(): void
    {
        $this->migrate(self::VERSION);
        $this->assertMigratedState('after first up');

        $this->migrate('prev');
        self::assertSame(
            1,
            $this->queryInt("SELECT COUNT(*) FROM permissions WHERE name = 'admin.settings'"),
            'down() must restore admin.settings'
        );
        self::assertSame(
            1,
            $this->queryInt("SELECT COUNT(*) FROM permissions WHERE name = 'admin.audit.view'"),
            'down() must restore admin.audit.view'
        );
        self::assertSame(
            1,
            $this->queryInt("SELECT COUNT(*) FROM permissions WHERE name = 'admin.mobile_preview.view'"),
            'down() must restore admin.mobile_preview.view'
        );
        self::assertSame(
            0,
            $this->queryInt(
                "SELECT COUNT(*) FROM permissions WHERE name IN"
                . " ('admin.language.read','admin.language.update','admin.section.read',"
                . "'admin.audit.read','admin.mobile_preview.read')"
            ),
            'down() must remove the new/renamed permission names'
        );
        self::assertSame(
            1,
            $this->queryInt(
                "SELECT COUNT(*) FROM rel_api_routes_permissions rap"
                . " JOIN api_routes ar ON ar.id = rap.id_api_routes"
                . " JOIN permissions p ON p.id = rap.id_permissions"
                . " WHERE ar.route_name = 'admin_languages_get_all' AND p.name = 'admin.settings'"
            ),
            'down() must restore the languages GET → admin.settings link'
        );
        self::assertSame(
            1,
            $this->queryInt(
                "SELECT COUNT(*) FROM rel_api_routes_permissions rap"
                . " JOIN api_routes ar ON ar.id = rap.id_api_routes"
                . " JOIN permissions p ON p.id = rap.id_permissions"
                . " WHERE ar.route_name = 'admin_sections_unused_get' AND p.name = 'admin.page.update'"
            ),
            'down() must restore unused GET → admin.page.update'
        );

        $this->migrate(self::VERSION);
        $this->assertMigratedState('after second up');

        $this->migrate('latest');
        $this->assertSchemaInSync('after round-trip of ' . self::VERSION);
    }

    private function assertMigratedState(string $when): void
    {
        self::assertSame(
            0,
            $this->queryInt(
                "SELECT COUNT(*) FROM permissions WHERE name IN"
                . " ('admin.settings','admin.audit.view','admin.mobile_preview.view')"
            ),
            "{$when}: old permission names must be gone"
        );
        foreach (
            [
                'admin.language.read',
                'admin.language.update',
                'admin.section.read',
                'admin.audit.read',
                'admin.mobile_preview.read',
            ] as $name
        ) {
            self::assertSame(
                1,
                $this->queryInt("SELECT COUNT(*) FROM permissions WHERE name = '{$name}'"),
                "{$when}: {$name} must exist"
            );
        }

        $routeExpectations = [
            'admin_languages_get_all' => 'admin.language.read',
            'admin_languages_get_one' => 'admin.language.read',
            'admin_languages_create' => 'admin.language.update',
            'admin_languages_update' => 'admin.language.update',
            'admin_languages_delete' => 'admin.language.update',
            'admin_audit_data_access_list' => 'admin.audit.read',
            'admin_audit_data_access_detail' => 'admin.audit.read',
            'admin_audit_data_access_stats' => 'admin.audit.read',
            'admin_sections_unused_get' => 'admin.section.read',
            'admin_sections_unused_delete' => 'admin.section.delete',
            'admin_sections_unused_delete_all' => 'admin.section.delete',
            'admin_data_access_roles_list' => 'admin.role.read',
            'admin_data_access_role_effective_permissions' => 'admin.role.read',
            'admin_data_access_role_permission_set' => 'admin.role.update',
        ];
        foreach ($routeExpectations as $routeName => $permission) {
            self::assertSame(
                1,
                $this->queryInt(
                    "SELECT COUNT(*) FROM rel_api_routes_permissions rap"
                    . " JOIN api_routes ar ON ar.id = rap.id_api_routes"
                    . " JOIN permissions p ON p.id = rap.id_permissions"
                    . " WHERE ar.route_name = '{$routeName}' AND p.name = '{$permission}'"
                ),
                "{$when}: {$routeName} must link to {$permission}"
            );
            self::assertSame(
                1,
                $this->queryInt(
                    "SELECT COUNT(*) FROM rel_api_routes_permissions rap"
                    . " JOIN api_routes ar ON ar.id = rap.id_api_routes"
                    . " WHERE ar.route_name = '{$routeName}'"
                ),
                "{$when}: {$routeName} must have exactly one permission"
            );
        }

        self::assertSame(
            0,
            $this->queryInt(
                "SELECT COUNT(*) FROM rel_permissions_roles rpr"
                . " JOIN permissions held ON held.id = rpr.id_permissions"
                . " JOIN permissions read_p ON read_p.name = CONCAT(SUBSTRING_INDEX(held.name, '.', 2), '.read')"
                . " LEFT JOIN rel_permissions_roles has_read"
                . "   ON has_read.id_roles = rpr.id_roles AND has_read.id_permissions = read_p.id"
                . " WHERE held.name REGEXP '^admin\\\\.[^.]+\\\\.[^.]+$'"
                . "   AND SUBSTRING_INDEX(held.name, '.', -1) <> 'read'"
                . "   AND has_read.id_permissions IS NULL"
            ),
            "{$when}: every core area right must carry its implied read"
        );

        self::assertSame(
            3,
            $this->queryInt(
                "SELECT COUNT(*) FROM rel_permissions_roles rpr"
                . " JOIN roles r ON r.id = rpr.id_roles AND r.is_superuser = 1"
                . " JOIN permissions p ON p.id = rpr.id_permissions"
                . " WHERE p.name IN ('admin.language.read','admin.language.update','admin.section.read')"
            ),
            "{$when}: superuser must hold the three new permissions"
        );
    }
}
