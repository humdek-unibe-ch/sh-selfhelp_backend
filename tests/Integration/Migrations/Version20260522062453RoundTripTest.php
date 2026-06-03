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
 * Per-migration round-trip for the plugin-layer schema
 * (`Version20260522062453`: plugins, plugin_operations, plugin_sources,
 * plugin_feature_flags tables plus the id_plugins FKs added to styles,
 * api_routes, fields, permissions, lookups and data_tables).
 *
 * This is the most structural plugin migration: its down() must drop the FKs
 * and tables in the right order, otherwise a revert/re-apply fails on dangling
 * constraints. Release-tier (`#[Group('migration')]`): slow + needs CREATE
 * DATABASE.
 */
#[Group('migration')]
final class Version20260522062453RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPluginLayerSchemaMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260522062453');
    }
}
