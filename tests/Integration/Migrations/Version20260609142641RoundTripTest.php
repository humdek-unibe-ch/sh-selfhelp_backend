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
 * Per-migration round-trip for the plugin version-pinning migration
 * (`Version20260609142641`: `plugins.pinned` column + admin pin/unpin API routes).
 *
 * It touches both schema (new boolean column) and seeded routes whose
 * `api_routes.requirements` is a JSON column populated via `JSON_OBJECT(...)`, so
 * a clean up()+down() proves the JSON value is valid (a regression guard for the
 * earlier "Invalid JSON text" defect) and that the routes + column revert cleanly.
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260609142641RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPluginPinningMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260609142641');
    }
}
