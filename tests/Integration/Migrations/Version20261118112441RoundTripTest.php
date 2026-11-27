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
 * Round-trip for Version20261118112441: page ACL down to one right (F03).
 * `up()` drops `acl_insert` / `acl_update` / `acl_delete` from
 * `page_acl_groups` and rewrites `get_user_acl` without them; `down()` adds
 * the columns and the old procedure back.
 */
#[Group('migration')]
final class Version20261118112441RoundTripTest extends MigrationRoundTripTestCase
{
    private const VERSION = 'DoctrineMigrations\Version20261118112441';

    private const BIT_COLUMNS = "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()"
        . " AND table_name = 'page_acl_groups' AND column_name IN ('acl_insert', 'acl_update', 'acl_delete')";

    public function testPageAclSingleRightRoundTrips(): void
    {
        $this->migrate(self::VERSION);
        self::assertSame(0, $this->queryInt(self::BIT_COLUMNS), 'up() must drop the three ACL bit columns.');

        $this->migrate('prev');
        self::assertSame(3, $this->queryInt(self::BIT_COLUMNS), 'down() must restore the three ACL bit columns.');

        $this->migrate(self::VERSION);
        self::assertSame(0, $this->queryInt(self::BIT_COLUMNS));
        self::assertSame(1, $this->queryInt(
            "SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE() AND routine_name = 'get_user_acl'"
        ), 'get_user_acl must exist after up().');

        $this->migrate('latest');
        $this->assertSchemaInSync('after round-trip of ' . self::VERSION);
    }
}
