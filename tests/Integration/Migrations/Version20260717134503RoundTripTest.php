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
 * Per-migration round-trip for the bulk remove-from-group route seed
 * (`Version20260717134503`), which inserts the
 * POST /admin/users/bulk-remove-from-group row into `api_routes` and links it
 * to `admin.user.update`.
 *
 * `down()` removes the route and its permission link, so reverting and
 * re-applying must leave the schema in sync with the ORM mapping. Mirrors
 * {@see Version20260716180311RoundTripTest}.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260717134503RoundTripTest extends MigrationRoundTripTestCase
{
    public function testBulkRemoveFromGroupRouteSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260717134503');
    }
}
