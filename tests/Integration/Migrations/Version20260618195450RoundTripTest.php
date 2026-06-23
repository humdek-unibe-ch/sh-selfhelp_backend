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
 * Per-migration round-trip for `Version20260618195450` (narrow shared_size to
 * sm|md|lg and shared_radius to none|sm|md|lg|full + normalise stored values).
 *
 * Data-only migration: schema is unchanged, so the round-trip proves up()/down()
 * apply cleanly and reversibly without altering the schema snapshot. Release-tier
 * (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260618195450RoundTripTest extends MigrationRoundTripTestCase
{
    public function testSharedScaleNarrowingRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260618195450');
    }
}
