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
 * Per-migration round-trip for the password-recovery route seed
 * (`Version20260605144355`: public POST /auth/forgot-password and
 * /auth/reset-password rows in `api_routes`).
 *
 * A broken down()/up() would leave orphaned route rows or fail to re-insert
 * them. Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260605144355RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPasswordRecoveryRouteSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260605144355');
    }
}
