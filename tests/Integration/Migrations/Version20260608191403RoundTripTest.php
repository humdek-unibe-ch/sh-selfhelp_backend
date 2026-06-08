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
 * Per-migration round-trip for `Version20260608191403` (maintenance-mode
 * read/write API): creates the `admin.system.maintenance` permission + admin
 * role grant and the two `/admin/system/maintenance` `api_routes` rows + their
 * permission links.
 *
 * Proves the data INSERTs apply, down() DELETEs revert them cleanly, and the ORM
 * mapping stays in sync. Release-tier (`#[Group('migration')]`): needs
 * CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260608191403RoundTripTest extends MigrationRoundTripTestCase
{
    public function testMaintenanceModeRoutesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260608191403');
    }
}
