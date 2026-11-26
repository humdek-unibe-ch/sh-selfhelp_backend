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
 * Per-migration round-trip for the plugin resource type admin
 * routes (`Version20261117103339`, H6).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261117103339RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPluginResourceTypeRoutesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261117103339');
    }
}
