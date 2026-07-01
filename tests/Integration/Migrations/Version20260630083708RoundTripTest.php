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
 * Per-migration round-trip for the initial `page_routes` seed
 * (`Version20260630083708`, issue #30): converts each resolvable `pages.url`
 * into a canonical route and seeds the parameterized auth-flow patterns
 * (`/reset` + `/reset/{user_id}/{token}`, `/validate/{user_id}/{token}`, `/home`
 * + `/`).
 *
 * It is data-only (idempotent `INSERT IGNORE` up, scoped `DELETE` down), so the
 * round-trip proves the schema stays ORM-aligned across a revert + replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630083708RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPageRoutesSeedRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630083708');
    }
}
