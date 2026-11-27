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
 * Round-trip for Version20261118115252 (F09, F10): `down()` drops
 * `api_routes.is_public`, `roles.is_superuser` and `users.is_system`; `up()`
 * adds them again and seeds `is_superuser` on the role that was named `admin`
 * and `is_system` on the system users (named `admin` / `tpf`).
 */
#[Group('migration')]
final class Version20261118115252RoundTripTest extends MigrationRoundTripTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261118115252';

    private const FLAG_COLUMNS = "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ("
        . "(table_name = 'api_routes' AND column_name = 'is_public')"
        . " OR (table_name = 'roles' AND column_name = 'is_superuser')"
        . " OR (table_name = 'users' AND column_name = 'is_system'))";

    public function testFlagsRoundTripAndAreSeeded(): void
    {
        $this->migrate(self::VERSION);
        self::assertSame(3, $this->queryInt(self::FLAG_COLUMNS));

        $this->migrate('prev');
        self::assertSame(0, $this->queryInt(self::FLAG_COLUMNS), 'down() must drop the three flag columns.');

        // A system user and a plain user, written while the flags do not exist.
        $lookup = '(SELECT id FROM lookups ORDER BY id LIMIT 1)';
        $this->execSql(
            "INSERT INTO users (email, name, id_status, id_user_types) VALUES"
            . " ('qa.migration-system@selfhelp.test', 'tpf', {$lookup}, {$lookup}),"
            . " ('qa.migration-plain@selfhelp.test', 'qa_migration_plain', {$lookup}, {$lookup})"
        );

        $this->migrate(self::VERSION);
        self::assertSame(3, $this->queryInt(self::FLAG_COLUMNS), 'up() must add the three flag columns.');
        self::assertSame(1, $this->queryInt("SELECT COUNT(*) FROM roles WHERE name = 'admin' AND is_superuser = 1"), 'up() must flag the role named admin.');
        self::assertSame(1, $this->queryInt('SELECT COUNT(*) FROM roles WHERE is_superuser = 1'), 'Exactly one superuser role.');
        self::assertSame(1, $this->queryInt("SELECT is_system FROM users WHERE email = 'qa.migration-system@selfhelp.test'"), 'up() must flag the system user.');
        self::assertSame(0, $this->queryInt("SELECT is_system FROM users WHERE email = 'qa.migration-plain@selfhelp.test'"), 'A plain user stays unflagged.');
        self::assertGreaterThan(0, $this->queryInt('SELECT COUNT(*) FROM api_routes WHERE is_public = 1'), 'up() must seed the public routes.');

        $this->migrate('latest');
        $this->assertSchemaInSync('after round-trip of ' . self::VERSION);
    }
}
