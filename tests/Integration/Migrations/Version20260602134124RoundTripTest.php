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
 * Per-migration round-trip for the admin CMS-preferences route wiring
 * (`Version20260602134124`: registers GET /admin/cms-preferences, links it to
 * `admin.cms_preferences.read`, grants the permission to the admin role).
 *
 * Data-only migration: proves the INSERT IGNORE / DELETE statements apply and
 * revert without error and the schema stays in sync. Release-tier
 * (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260602134124RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAdminCmsPreferencesRouteMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260602134124');
    }
}
