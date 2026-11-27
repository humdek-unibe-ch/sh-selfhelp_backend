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
 * Round-trip for Version20261126124832 (contract C19): `up()` adds the nullable
 * `content_ref` column (VARCHAR 191) to `data_rows` and `data_revisions` and
 * converts nothing (existing records stay "unknown"); `down()` drops both.
 */
#[Group('migration')]
final class Version20261126124832RoundTripTest extends MigrationRoundTripTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261126124832';

    public function testTheColumnsAreAddedWithoutTouchingExistingRecordsAndDropped(): void
    {
        $this->migrate(self::VERSION);
        $this->migrate('prev'); // down()

        self::assertSame(0, $this->contentRefColumns('data_rows'), 'down(): data_rows has no content_ref.');
        self::assertSame(0, $this->contentRefColumns('data_revisions'), 'down(): data_revisions has no content_ref.');

        // A record that exists before the reference does.
        $this->execSql("INSERT INTO data_tables (name, timestamp) VALUES ('qa_content_ref_table', NOW())");
        $this->execSql(
            'INSERT INTO data_rows (id_data_tables, timestamp)'
            . " VALUES ((SELECT id FROM data_tables WHERE name = 'qa_content_ref_table'), NOW())"
        );
        $existing = "SELECT COUNT(*) FROM data_rows WHERE id_data_tables = (SELECT id FROM data_tables WHERE name = 'qa_content_ref_table')";
        self::assertSame(1, $this->queryInt($existing), 'Setup: one record without history or reference.');

        $this->migrate(self::VERSION); // up()

        self::assertSame(1, $this->contentRefColumns('data_rows'), 'up(): data_rows has a nullable content_ref of 191 characters.');
        self::assertSame(1, $this->contentRefColumns('data_revisions'), 'up(): data_revisions has a nullable content_ref of 191 characters.');
        self::assertSame(1, $this->queryInt($existing), 'up(): the record is still there.');
        self::assertSame(
            0,
            $this->queryInt("SELECT COUNT(*) FROM data_rows WHERE content_ref IS NOT NULL AND id_data_tables = (SELECT id FROM data_tables WHERE name = 'qa_content_ref_table')"),
            'up(): nothing is converted; an existing record has no reference.'
        );

        $this->migrate('prev'); // down() again, with data in place

        self::assertSame(0, $this->contentRefColumns('data_rows'), 'down(): the column is dropped.');
        self::assertSame(0, $this->contentRefColumns('data_revisions'), 'down(): the column is dropped.');
        self::assertSame(1, $this->queryInt($existing), 'down(): the record stays.');

        $this->migrate('latest');
        $this->assertSchemaInSync('after round-trip of ' . self::VERSION);
    }

    /** 1 when the table has a nullable `content_ref` VARCHAR(191), else 0. */
    private function contentRefColumns(string $table): int
    {
        return $this->queryInt(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()'
            . " AND table_name = '{$table}' AND column_name = 'content_ref'"
            . " AND is_nullable = 'YES' AND data_type = 'varchar' AND character_maximum_length = 191"
        );
    }
}
