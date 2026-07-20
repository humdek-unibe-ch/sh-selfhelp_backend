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
 * Per-migration round-trip for the Users Management route seed
 * (`Version20260716180311`), which inserts the six `/admin/users` routes
 * (stats, bulk-delete, bulk-add-to-group, bulk-send-activation, export,
 * import) into `api_routes`, links each to its permission through
 * `rel_api_routes_permissions`, and re-declares the list route's `params` to
 * document the new `status` / `id_groups` query filters.
 *
 * `down()` removes the six routes plus their permission links and restores the
 * list route's `params`, so reverting and re-applying must leave the schema in
 * sync with the ORM mapping. Mirrors {@see Version20260602091045RoundTripTest}
 * (the route-seed per-version pattern).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260716180311RoundTripTest extends MigrationRoundTripTestCase
{
    public function testUsersManagementRouteSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260716180311');
    }
}
