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
 * Per-migration round-trip for `Version20260616094205` (move the seeded
 * maintenance alert body from the wrong `value` field to the renderer's
 * `content` field).
 *
 * Proves the data move applies, down() reverts it cleanly (content rows
 * back to value rows), and the ORM mapping stays in sync. Release-tier
 * (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260616094205RoundTripTest extends MigrationRoundTripTestCase
{
    public function testMaintenanceAlertContentFieldFixRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260616094205');
    }
}
