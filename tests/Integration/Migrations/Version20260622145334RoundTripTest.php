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
 * Round-trip for the mobile-only capability pass: the new mobile_* fields
 * (select presentation, button feedback, slider show-value, field variant)
 * and their style links must apply and reverse cleanly, leaving the
 * ORM-mapped schema in sync.
 */
#[Group('migration')]
final class Version20260622145334RoundTripTest extends MigrationRoundTripTestCase
{
    public function testMobileCapabilityPassRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260622145334');
    }
}
