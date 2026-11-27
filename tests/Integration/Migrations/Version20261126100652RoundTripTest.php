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
 * Round-trip for Version20261126100652 (F280): `up()` deletes the
 * `role_data_access` rows of pages, groups, data tables and assets that no
 * longer exist and keeps every other row; `down()` has nothing to restore.
 */
#[Group('migration')]
final class Version20261126100652RoundTripTest extends MigrationRoundTripTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261126100652';

    public function testOrphanGrantsOfEveryCoreTypeAreRemovedAndValidRowsStay(): void
    {
        $this->migrate(self::VERSION);
        $this->migrate('prev'); // down() is a no-op

        $type = static fn (string $code): string => "(SELECT id FROM lookups WHERE type_code = 'resourceTypes' AND lookup_code = '{$code}')";

        // Resources that exist (the seed has pages and groups; a table and an asset are added).
        $this->execSql("INSERT INTO data_tables (name, timestamp) VALUES ('qa_orphan_table', NOW())");
        $this->execSql(
            "INSERT INTO assets (uuid, id_asset_types, name) VALUES ('00000000-0000-4000-8000-00000000f280',"
            . " (SELECT id FROM lookups WHERE type_code = 'assetTypes' ORDER BY id LIMIT 1), 'qa_orphan_asset')"
        );
        $this->execSql("INSERT INTO lookups (type_code, lookup_code, lookup_value) VALUES ('resourceTypes', 'qa_item', 'QA item')");
        $this->execSql("INSERT INTO roles (name, description) VALUES ('qa_orphan_role', 'QA role with stale grants')");
        $role = "(SELECT id FROM roles WHERE name = 'qa_orphan_role')";

        // One grant on an existing resource and one on a missing id, per core type.
        $existing = [
            'pages' => '(SELECT MIN(id) FROM pages)',
            'group' => '(SELECT MIN(id) FROM `groups`)',
            'data_table' => "(SELECT id FROM data_tables WHERE name = 'qa_orphan_table')",
            'asset' => "(SELECT id FROM assets WHERE name = 'qa_orphan_asset')",
        ];
        $missing = [
            'pages' => '(SELECT MAX(id) + 1000 FROM pages)',
            'group' => '(SELECT MAX(id) + 1000 FROM `groups`)',
            'data_table' => '(SELECT MAX(id) + 1000 FROM data_tables)',
            'asset' => '(SELECT MAX(id) + 1000 FROM assets)',
        ];
        foreach ($existing as $code => $resource) {
            $this->execSql("INSERT INTO role_data_access (id_roles, id_resource_types, resource_id, crud_permissions) VALUES ({$role}, {$type($code)}, {$resource}, 2)");
        }
        foreach ($missing as $code => $resource) {
            $this->execSql("INSERT INTO role_data_access (id_roles, id_resource_types, resource_id, crud_permissions) VALUES ({$role}, {$type($code)}, {$resource}, 15)");
        }
        // A plugin type is not touched, whether or not its item exists.
        $this->execSql("INSERT INTO role_data_access (id_roles, id_resource_types, resource_id, crud_permissions) VALUES ({$role}, {$type('qa_item')}, 987654, 2)");

        $held = "SELECT COUNT(*) FROM role_data_access WHERE id_roles = {$role}";
        self::assertSame(9, $this->queryInt($held), 'Setup: four valid, four orphan and one plugin-type row.');

        $this->migrate(self::VERSION);

        self::assertSame(5, $this->queryInt($held), 'The four orphans are gone; the valid rows and the plugin-type row stay.');
        self::assertSame(
            0,
            $this->queryInt("SELECT COUNT(*) FROM role_data_access WHERE id_roles = {$role} AND crud_permissions = 15"),
            'Every orphan (rights 15) was removed.'
        );
        self::assertSame(
            1,
            $this->queryInt("SELECT COUNT(*) FROM role_data_access WHERE id_roles = {$role} AND id_resource_types = {$type('qa_item')}"),
            'A plugin-type row is kept.'
        );

        $this->migrate('latest');
        $this->assertSchemaInSync('after round-trip of ' . self::VERSION);
    }
}
