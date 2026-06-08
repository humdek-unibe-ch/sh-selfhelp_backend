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
 * Per-migration round-trip for the registration-lifecycle CMS labels seed
 * (`Version20260604111011`: new label fields + style links + en-GB/de-CH
 * section translations for register/login/validate).
 *
 * The migration seeds CMS fields/translations, so a broken down()/up() would
 * leave orphaned field rows or duplicate translations. Release-tier
 * (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260604111011RoundTripTest extends MigrationRoundTripTestCase
{
    public function testRegistrationLabelSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260604111011');
    }
}
