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
 * Per-migration round-trip for the /register page rebuild
 * (`Version20260603095911`: drops the duplicate `register-sys-%` sections left
 * by two earlier seeds and recreates one container + one register section).
 *
 * Data-only migration: proves the DELETE / INSERT...SELECT statements apply and
 * revert without error and the schema stays in sync. Release-tier
 * (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260603095911RoundTripTest extends MigrationRoundTripTestCase
{
    public function testRegisterPageRebuildMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260603095911');
    }
}
