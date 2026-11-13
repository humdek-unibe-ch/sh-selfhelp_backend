<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

/**
 * State of a fully migrated database after the SH1 cleanup
 * (`Version20261112163406`): the dead SelfHelp 1 leftovers are gone and what
 * `docs/reference/database-inventory.md` marks as kept is still there.
 *
 * Reads `information_schema` only, so it is safe under the DAMA transaction.
 */
#[Group('integration')]
final class Sh1CleanupTest extends QaKernelTestCase
{
    /** Procedures and functions the inventory lists as used and kept (F20/F36). */
    private const KEPT_ROUTINES = [
        'FUNCTION build_dynamic_columns',
        'FUNCTION build_dynamic_columns_with_selection',
        'FUNCTION build_exclude_deleted_filter',
        'FUNCTION build_language_filter',
        'FUNCTION build_time_period_filter',
        'FUNCTION convert_entry_date_timezone',
        'PROCEDURE get_data_table_all_languages',
        'PROCEDURE get_data_table_filtered',
        'PROCEDURE get_data_table_for_user_groups',
        'PROCEDURE get_page_sections_hierarchical',
        'PROCEDURE get_user_acl',
    ];

    public function testDeadSh1TablesAreGone(): void
    {
        self::assertFalse($this->tableExists('libraries'), 'The SH1 `libraries` table must be dropped.');
        self::assertFalse($this->tableExists('rel_sections_navigation'), 'The SH1 `rel_sections_navigation` table must be dropped.');
    }

    public function testUsersHasNoInternColumn(): void
    {
        $count = $this->scalarCount(
            'SELECT COUNT(*) FROM information_schema.columns '
            . "WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'intern'"
        );

        self::assertSame(0, $count, '`users.intern` must be dropped.');
    }

    public function testHookTypesLookupRowsAreGone(): void
    {
        $count = $this->scalarCount("SELECT COUNT(*) FROM lookups WHERE type_code = 'hookTypes'");

        self::assertSame(0, $count, 'The `hookTypes` lookup rows must be deleted.');
    }

    public function testKeptTablesStillExist(): void
    {
        foreach (['messenger_messages', 'page_view_referrers', 'user_navigation_state'] as $table) {
            self::assertTrue($this->tableExists($table), sprintf('`%s` is kept and must still exist.', $table));
        }
    }

    public function testStoredRoutinesAreUntouched(): void
    {
        $rows = $this->connection()->fetchFirstColumn(
            "SELECT CONCAT(routine_type, ' ', routine_name) FROM information_schema.routines "
            . 'WHERE routine_schema = DATABASE() ORDER BY routine_type, routine_name'
        );

        self::assertSame(self::KEPT_ROUTINES, $rows, 'The 5 procedures and 6 functions are kept as they are.');
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }

    private function tableExists(string $table): bool
    {
        return $this->scalarCount(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ' . $this->connection()->quote($table)
        ) === 1;
    }

    private function scalarCount(string $sql): int
    {
        $value = $this->connection()->fetchOne($sql);
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
