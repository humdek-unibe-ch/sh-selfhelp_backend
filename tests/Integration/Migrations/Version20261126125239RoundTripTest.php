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
 * Round-trip for Version20261126125239 (contract C20): `up()` adds the revision
 * source `plugin` to the `dataRevisionSources` lookup and converts nothing;
 * `down()` re-points the revisions that used the door to `system` (history is
 * never deleted) and removes the lookup row.
 */
#[Group('migration')]
final class Version20261126125239RoundTripTest extends MigrationRoundTripTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261126125239';
    private const TABLE = 'qa_plugin_door_table';

    public function testThePluginDoorIsAddedAndRemovedAndItsRevisionsGoBackToSystem(): void
    {
        $this->migrate(self::VERSION);
        $this->migrate('prev'); // down()

        self::assertSame(0, $this->doors('plugin'), 'down(): the plugin door is not a revision source.');
        $siblings = $this->queryInt("SELECT COUNT(*) FROM lookups WHERE type_code = 'dataRevisionSources'");
        self::assertSame(1, $this->doors('system'), 'the other doors are there');

        $this->migrate(self::VERSION); // up()

        self::assertSame(1, $this->doors('plugin'), 'up(): `plugin` is a revision source.');
        self::assertSame($siblings + 1, $this->queryInt("SELECT COUNT(*) FROM lookups WHERE type_code = 'dataRevisionSources'"), 'up(): only that one row was added.');
        self::assertSame(
            1,
            $this->queryInt("SELECT COUNT(*) FROM lookups WHERE type_code = 'dataRevisionSources' AND lookup_code = 'plugin' AND lookup_value = 'Plugin' AND lookup_description IS NOT NULL"),
            'up(): the door has its name and its description.'
        );

        // A record that was written through the door, and one that was not.
        $this->execSql("INSERT INTO data_tables (name, timestamp) VALUES ('" . self::TABLE . "', NOW())");
        $table = "(SELECT id FROM data_tables WHERE name = '" . self::TABLE . "')";
        $this->execSql("INSERT INTO data_rows (id_data_tables, timestamp) VALUES ({$table}, NOW())");
        $row = "(SELECT MIN(id) FROM data_rows WHERE id_data_tables = {$table})";
        foreach ([[1, 'plugin'], [2, 'system']] as [$version, $door]) {
            $this->execSql(
                'INSERT INTO data_revisions (version, created_at, id_data_rows, id_data_tables, id_operations, id_sources)'
                . " VALUES ({$version}, NOW(), {$row}, {$table}, {$this->lookup('dataRevisionOperations', 'update')}, {$this->lookup('dataRevisionSources', $door)})"
            );
        }
        $revisions = "SELECT COUNT(*) FROM data_revisions WHERE id_data_rows = {$row}";
        $through = static fn (string $door): string => $revisions . " AND id_sources = (SELECT id FROM lookups WHERE type_code = 'dataRevisionSources' AND lookup_code = '{$door}')";
        self::assertSame(1, $this->queryInt($through('plugin')), 'Setup: one revision through the plugin door.');
        self::assertSame(1, $this->queryInt($through('system')), 'Setup: one through the system door.');

        $this->migrate('prev'); // down(), with history in place

        self::assertSame(0, $this->doors('plugin'), 'down(): the door is gone.');
        self::assertSame($siblings, $this->queryInt("SELECT COUNT(*) FROM lookups WHERE type_code = 'dataRevisionSources'"), 'down(): every other door stays.');
        self::assertSame(2, $this->queryInt($revisions), 'down(): no revision is deleted, history is kept.');
        self::assertSame(2, $this->queryInt($through('system')), 'down(): the revision of the door now says it was written by the system.');

        $this->migrate(self::VERSION); // up() again

        self::assertSame(1, $this->doors('plugin'), 'up() again: the door is back.');
        self::assertSame(0, $this->queryInt($through('plugin')), 'up() again: an old revision is not given back to the plugin.');
        self::assertSame(2, $this->queryInt($through('system')), 'up() again: the history is unchanged.');

        $this->migrate('latest');
        $this->assertSchemaInSync('after round-trip of ' . self::VERSION);
    }

    /** How many `dataRevisionSources` rows have this code. */
    private function doors(string $code): int
    {
        return $this->queryInt("SELECT COUNT(*) FROM lookups WHERE type_code = 'dataRevisionSources' AND lookup_code = '{$code}'");
    }

    /** A sub-select for the id of a lookup row. */
    private function lookup(string $type, string $code): string
    {
        return "(SELECT id FROM lookups WHERE type_code = '{$type}' AND lookup_code = '{$code}')";
    }
}
