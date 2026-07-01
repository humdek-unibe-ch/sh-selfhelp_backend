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
 * Per-migration round-trip for the `page_surface` axis
 * (`Version20260630090150`, issue #30 CMS-in-CMS): adds the core-owned/closed
 * lookup group `pageSurface` (`public` | `cms`) and the nullable FK
 * `pages.id_page_surface` → `lookups.id` (existing pages backfilled to `public`).
 *
 * Reverting and re-applying it must leave the schema in sync with the ORM mapping
 * (proves `down()` drops the FK/column + lookup rows and `up()` re-adds them).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630090150RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPageSurfaceLookupAndColumnRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630090150');
    }
}
