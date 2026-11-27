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
 * Per-migration round-trip for the resource usage admin route
 * (`Version20261126123949`, contract C7).
 */
#[Group('migration')]
final class Version20261126123949RoundTripTest extends MigrationRoundTripTestCase
{
    public function testResourceUsageRouteMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261126123949');
    }
}
