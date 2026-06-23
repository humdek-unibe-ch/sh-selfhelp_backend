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
 * Per-migration round-trip for `Version20260618143215` (style render targeting:
 * `styleRenderTargets` lookup + nullable `styles.id_render_target` FK + backfill
 * to `both`). No page column is added — `pages.id_page_access_types` stays the
 * single page-platform source of truth.
 *
 * Proves the column/FK/index + lookup rows apply, down() drops them cleanly,
 * and the ORM mapping stays in sync. Release-tier (`#[Group('migration')]`):
 * needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260618143215RoundTripTest extends MigrationRoundTripTestCase
{
    public function testStyleRenderTargetRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260618143215');
    }
}
