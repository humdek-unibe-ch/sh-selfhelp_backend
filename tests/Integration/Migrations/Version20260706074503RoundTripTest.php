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
 * Per-migration round-trip for the navigation cleanup migration
 * (`Version20260706074503`: add `navigation_menu_items.layer`, drop
 * `id_child_source` / `auto_include_depth` / `navigation_menus.config`,
 * seed `columns`/`inline` footer presets, carry `config.footer_layout`
 * over to `id_preset`, delete the `navigationChildSources` lookups).
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260706074503RoundTripTest extends MigrationRoundTripTestCase
{
    public function testNavigationCleanupMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260706074503');
    }
}
