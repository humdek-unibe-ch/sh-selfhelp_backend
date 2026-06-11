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
 * Per-migration round-trip for `Version20260608160348` (SelfHelp Manager MVP):
 * creates the `system_update_operations` table, the two `admin.system.*`
 * permissions, the admin role grants, and the four `/admin/system` `api_routes`
 * rows + permission links.
 *
 * Proves the schema DDL + data INSERTs apply, the down() drops/DELETEs revert
 * them, and the ORM mapping stays in sync. Release-tier (`#[Group('migration')]`):
 * needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260608160348RoundTripTest extends MigrationRoundTripTestCase
{
    public function testSystemUpdateRoutesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260608160348');
    }
}
