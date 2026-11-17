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
 * Round-trip for Version20261117153539: form table labels backfilled from the section name.
 */
#[Group('migration')]
final class Version20261117153539RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFormTableLabelBackfillRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261117153539');
    }
}
