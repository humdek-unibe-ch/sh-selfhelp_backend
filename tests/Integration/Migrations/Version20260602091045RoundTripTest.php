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
 * Per-migration round-trip for the public health-route seed
 * (`Version20260602091045`, which inserts the permission-less
 * `/cms-api/v1/health` row into `api_routes`).
 *
 * `down()` deletes that row, so reverting and re-applying must leave the
 * schema in sync with the ORM mapping. Mirrors
 * {@see Version20260501000300RoundTripTest} (the per-version pattern).
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260602091045RoundTripTest extends MigrationRoundTripTestCase
{
    public function testHealthRouteSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260602091045');
    }
}
