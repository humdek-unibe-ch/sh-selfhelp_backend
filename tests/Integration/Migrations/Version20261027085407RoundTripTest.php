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
 * Per-migration round-trip for the `alert_rate_limited` CMS field
 * (`Version20261027085407`: field + login/two-factor-auth/reset-password style links +
 * en-GB/de-CH seeds on the system sections).
 *
 * A broken down()/up() would leave an orphaned field or fail to re-seed it.
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261027085407RoundTripTest extends MigrationRoundTripTestCase
{
    public function testRateLimitedAlertFieldMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20261027085407');
    }
}
