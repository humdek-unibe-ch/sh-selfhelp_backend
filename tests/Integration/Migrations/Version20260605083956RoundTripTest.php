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
 * Per-migration round-trip for the profile communication-preferences CMS
 * labels seed (`Version20260605083956`: new `profile_communication_*` /
 * `profile_receive_*` fields + `profile` style links + en-GB/de-CH
 * `profile-sys-profile` section translations).
 *
 * The migration seeds CMS fields/translations, so a broken down()/up() would
 * leave orphaned field rows or duplicate translations. Release-tier
 * (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260605083956RoundTripTest extends MigrationRoundTripTestCase
{
    public function testProfileCommunicationPreferencesSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260605083956');
    }
}
