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
 * Per-migration round-trip for the pager/branding migration
 * (`Version20260706175630`: add `navigation_menus.show_pager` +
 * `navigation_menu_items.show_pager` (nullable override), the
 * `navigation_settings` branding columns (`logo_asset_path`, `logo_alt`,
 * `id_logo_link_page`), and flip the `register` page headless).
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260706175630RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPagerAndBrandingMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260706175630');
    }
}
