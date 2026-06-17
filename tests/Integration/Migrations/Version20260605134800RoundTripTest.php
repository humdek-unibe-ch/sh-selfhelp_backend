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
 * Per-migration round-trip for the CMS error-surface seed
 * (`Version20260605134800`: registers the noAccess/missing/notFound styles,
 * their fields and style-field links, and wires one section onto each of the
 * no_access / no_access_guest / missing system pages).
 *
 * Data-only migration: proves the INSERT IGNORE / guarded INSERT...SELECT
 * statements apply and the DELETE-based down() reverts without error and the
 * schema stays in sync. Release-tier (`#[Group('migration')]`): needs CREATE
 * DATABASE.
 */
#[Group('migration')]
final class Version20260605134800RoundTripTest extends MigrationRoundTripTestCase
{
    public function testErrorSurfaceStylesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260605134800');
    }
}
