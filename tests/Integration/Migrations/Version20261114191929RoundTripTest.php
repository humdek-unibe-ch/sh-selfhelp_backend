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
 * Per-migration round-trip for the admin data-browser history routes
 * (`Version20261114191929`). The migration only registers API routes; the
 * round trip proves `down()` removes them and re-applied `up()` leaves routes
 * in step with the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261114191929RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAdminDataHistoryRoutesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261114191929');
    }
}
