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
 * Per-migration round-trip for the children-navigation migration
 * (`Version20260706143547`: seed the `navigationChildrenNavModes` lookups
 * (`sidebar` / `pills` / `none`), add `navigation_menus.id_children_nav`
 * + `navigation_menus.show_breadcrumbs`, and the per-item override
 * `navigation_menu_items.id_children_nav`).
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260706143547RoundTripTest extends MigrationRoundTripTestCase
{
    public function testChildrenNavigationMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260706143547');
    }
}
