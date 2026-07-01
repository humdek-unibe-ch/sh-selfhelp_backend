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
 * Per-migration round-trip for the navigation-pages + page-icons field seed
 * (`Version20260630130327`): seeds the `icon` / `mobile_icon` / `web_nav_render`
 * / `mobile_nav_render` page property fields (+ the `select-icon-mobile` /
 * `select-nav-render-web` / `select-nav-render-mobile` editor field types) and
 * links them to the `core` + `experiment` page types.
 *
 * It is data-only (idempotent `INSERT IGNORE` up, FK-safe `DELETE` down), so the
 * round-trip proves the schema stays ORM-aligned across a revert + replay.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260630130327RoundTripTest extends MigrationRoundTripTestCase
{
    public function testNavigationFieldsSeedRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260630130327');
    }
}
