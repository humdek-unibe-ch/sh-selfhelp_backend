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
 * Per-migration round-trip for the plugin-manager permission + route seed
 * (`Version20260522062459`: admin.plugins.* permissions plus the plugin
 * admin/public API routes and their rel_api_routes_permissions links).
 *
 * Reverting must remove the routes AND their permission links cleanly so a
 * re-apply does not collide on the unique route keys. Release-tier
 * (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260522062459RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPluginPermissionsAndRoutesSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260522062459');
    }
}
