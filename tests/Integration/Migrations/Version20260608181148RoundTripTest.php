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
 * Per-migration round-trip for the aggregated system health route
 * (`Version20260608181148`: inserts the GET /admin/system/health `api_routes`
 * row and links it to the `admin.system.read` permission).
 *
 * Data-only migration: proves the INSERT IGNORE / INSERT...SELECT statements
 * apply and the down() DELETEs revert them without error and the schema stays
 * in sync. Release-tier (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260608181148RoundTripTest extends MigrationRoundTripTestCase
{
    public function testSystemHealthRouteMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260608181148');
    }
}
