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
 * Per-migration round-trip for `Version20260610124237`: registers the
 * `admin_system_update_releases` `api_routes` row + its `admin.system.read`
 * permission link, and reverses both in down() (the shared permission is owned
 * by the earlier system-routes migration and is left intact).
 *
 * Release-tier (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260610124237RoundTripTest extends MigrationRoundTripTestCase
{
    public function testUpdateReleasesRouteMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260610124237');
    }
}
