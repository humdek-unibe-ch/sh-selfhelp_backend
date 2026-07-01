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
 * Per-migration round-trip for the admin "Example bundles" listing api_route
 * (`Version20260630132428`, issue #30 decision E): seeds the read-only
 * `GET /admin/pages/examples` endpoint that powers the import UI's ready-made
 * example page bundles, plus its permission link.
 *
 * It is data-only (idempotent `DELETE`-first + `INSERT`/`INSERT IGNORE` up,
 * scoped `DELETE` down), so the round-trip proves the schema stays ORM-aligned
 * across a revert + replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630132428RoundTripTest extends MigrationRoundTripTestCase
{
    public function testExampleBundlesApiRouteRoundTrip(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630132428');
    }
}
