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
 * Per-migration round-trip for the unified interpolation-picker route seed
 * (`Version20260629110606`, which inserts the `admin_interpolation_variables_get`
 * row — `GET /admin/interpolation/variables` — into `api_routes` plus its
 * `admin.page.read` link in `rel_api_routes_permissions`; issue #56 v2).
 *
 * `down()` deletes both rows, so reverting and re-applying must leave the schema
 * in sync with the ORM mapping. Mirrors {@see Version20260602091045RoundTripTest}
 * (the per-version route-seed pattern).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260629110606RoundTripTest extends MigrationRoundTripTestCase
{
    public function testInterpolationVariablesRouteSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260629110606');
    }
}
