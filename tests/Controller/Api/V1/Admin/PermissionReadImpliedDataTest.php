<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Tests\Support\QaWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Post-migration data checks for role-scope C1/C2 (task 2026-11-19-role-scope P1).
 * Runs against the migrated test database after `composer test:reset-db`.
 */
#[Group('security')]
final class PermissionReadImpliedDataTest extends QaWebTestCase
{
    /** @var array<string, string> */
    private const ROUTE_PERMISSIONS = [
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

    public function testRoutePermissionsMatchC1Exactly(): void
    {
        $connection = $this->connection();
        foreach (self::ROUTE_PERMISSIONS as $routeName => $expected) {
            $names = $connection->fetchFirstColumn(
                'SELECT p.name FROM rel_api_routes_permissions rap'
                . ' JOIN api_routes ar ON ar.id = rap.id_api_routes'
                . ' JOIN permissions p ON p.id = rap.id_permissions'
                . ' WHERE ar.route_name = ? ORDER BY p.name',
                [$routeName]
            );
            self::assertSame(
                [$expected],
                $names,
                "Route {$routeName} must list exactly [{$expected}]"
            );
        }
    }

    public function testOldPermissionNamesGoneAndHoldersRepaired(): void
    {
        $connection = $this->connection();

        self::assertSame(
            0,
            self::coerceInt($connection->fetchOne(
                "SELECT COUNT(*) FROM permissions WHERE name IN"
                . " ('admin.settings','admin.audit.view','admin.mobile_preview.view')"
            )),
            'Old permission names must not exist after migration'
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
                self::coerceInt($connection->fetchOne('SELECT COUNT(*) FROM permissions WHERE name = ?', [$name])),
                "{$name} must exist"
            );
        }

        // Every holder of section.delete also holds section.read
        self::assertSame(
            0,
            self::coerceInt($connection->fetchOne(
                "SELECT COUNT(*) FROM rel_permissions_roles rpr"
                . " JOIN permissions p_del ON p_del.id = rpr.id_permissions AND p_del.name = 'admin.section.delete'"
                . " WHERE NOT EXISTS ("
                . "   SELECT 1 FROM rel_permissions_roles r2"
                . "   JOIN permissions p_read ON p_read.id = r2.id_permissions AND p_read.name = 'admin.section.read'"
                . "   WHERE r2.id_roles = rpr.id_roles"
                . " )"
            )),
            'Every admin.section.delete holder must also hold admin.section.read'
        );

        // Language pair travels together for every role the migration repaired. The roles with the
        // `qa_` prefix are the QA fixture's restricted personas (QaBaselineFixture seeds `qa_read_language`
        // with the read right alone, on purpose), so they are left out of the comparison; the second check
        // below covers every role, QA personas included.
        $readHolders = self::coerceInt($connection->fetchOne(
            "SELECT COUNT(DISTINCT rpr.id_roles) FROM rel_permissions_roles rpr"
            . " JOIN roles r ON r.id = rpr.id_roles AND LEFT(r.name, 3) <> 'qa_'"
            . " JOIN permissions p ON p.id = rpr.id_permissions AND p.name = 'admin.language.read'"
        ));
        $updateHolders = self::coerceInt($connection->fetchOne(
            "SELECT COUNT(DISTINCT rpr.id_roles) FROM rel_permissions_roles rpr"
            . " JOIN roles r ON r.id = rpr.id_roles AND LEFT(r.name, 3) <> 'qa_'"
            . " JOIN permissions p ON p.id = rpr.id_permissions AND p.name = 'admin.language.update'"
        ));
        self::assertSame(
            $readHolders,
            $updateHolders,
            'admin.language.read and admin.language.update must have the same holders (roles without the qa_ prefix)'
        );
        self::assertGreaterThan(0, $readHolders, 'At least the former settings holders must have the language pair');

        // Whoever updates languages may also read them (rule 4.13), whatever the role is called.
        self::assertSame(
            0,
            self::coerceInt($connection->fetchOne(
                "SELECT COUNT(*) FROM rel_permissions_roles rpr"
                . " JOIN permissions p_upd ON p_upd.id = rpr.id_permissions AND p_upd.name = 'admin.language.update'"
                . " WHERE NOT EXISTS ("
                . "   SELECT 1 FROM rel_permissions_roles r2"
                . "   JOIN permissions p_read ON p_read.id = r2.id_permissions AND p_read.name = 'admin.language.read'"
                . "   WHERE r2.id_roles = rpr.id_roles"
                . " )"
            )),
            'Every admin.language.update holder must also hold admin.language.read'
        );

        // Superuser holds all new names
        self::assertSame(
            3,
            self::coerceInt($connection->fetchOne(
                "SELECT COUNT(*) FROM rel_permissions_roles rpr"
                . " JOIN roles r ON r.id = rpr.id_roles AND r.is_superuser = 1"
                . " JOIN permissions p ON p.id = rpr.id_permissions"
                . " WHERE p.name IN ('admin.language.read','admin.language.update','admin.section.read')"
            )),
            'Superuser role must hold the three new permissions'
        );
    }

    public function testNoRoleHoldsACoreRightWithoutItsImpliedRead(): void
    {
        $missing = self::coerceInt($this->connection()->fetchOne(
            "SELECT COUNT(*) FROM rel_permissions_roles rpr"
            . " JOIN permissions held ON held.id = rpr.id_permissions"
            . " JOIN permissions read_p ON read_p.name = CONCAT(SUBSTRING_INDEX(held.name, '.', 2), '.read')"
            . " LEFT JOIN rel_permissions_roles has_read"
            . "   ON has_read.id_roles = rpr.id_roles AND has_read.id_permissions = read_p.id"
            . " WHERE held.name REGEXP '^admin\\\\.[^.]+\\\\.[^.]+$'"
            . "   AND SUBSTRING_INDEX(held.name, '.', -1) <> 'read'"
            . "   AND has_read.id_permissions IS NULL"
        ));
        self::assertSame(0, $missing, 'C2 repair: every core area right must carry its implied read');
    }

    private function connection(): Connection
    {
        return $this->service(EntityManagerInterface::class)->getConnection();
    }
}
