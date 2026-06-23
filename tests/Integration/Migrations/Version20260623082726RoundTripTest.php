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
 * Round-trip for the anonymous-access hardening migration: opening
 * sh-global-css/sh-global-values and removing the duplicate pages_get_one
 * route must reverse cleanly and leave the ORM-mapped schema in sync.
 */
#[Group('migration')]
final class Version20260623082726RoundTripTest extends MigrationRoundTripTestCase
{
    public function testAnonymousAccessHardeningRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260623082726');
    }
}
