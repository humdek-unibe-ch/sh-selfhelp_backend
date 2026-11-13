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
 * Per-migration round-trip for the Impressum change (`Version20261112164932`):
 * the SH1 Components & versions area is removed and one `version` section is
 * placed there. The round trip covers both shapes: the first `up()` finds the
 * seeded area, the second (after `down()`, which does not restore the SH1 text)
 * finds it gone and appends the section at the end of the page.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261112164932RoundTripTest extends MigrationRoundTripTestCase
{
    public function testImpressumMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261112164932');
    }
}
