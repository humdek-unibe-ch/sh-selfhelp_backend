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
 * Per-migration round-trip for the API-routes seed migration
 * (`Version20260501000300`, which seeds `api_routes` + `rel_api_routes_permissions`).
 *
 * This is the highest-risk seed migration: the whole DB-driven routing layer
 * depends on it, and its `down()` deletes the seeded rows. Reverting and
 * re-applying it must leave the schema in sync with the ORM mapping.
 *
 * Reference example for the per-version pattern (plan §"Version*Test for
 * existing migrations, highest-risk first"). Copy this class for each
 * additional migration that warrants a dedicated round-trip test.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260501000300RoundTripTest extends MigrationRoundTripTestCase
{
    public function testApiRoutesSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260501000300');
    }
}
