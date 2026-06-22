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
 * Round-trip for the layout field-label backfill: setting the missing
 * rel_fields_styles.title labels (shared_width/height, paper.title/border,
 * space.orientation, simple-grid gap + responsive cols) must reverse cleanly.
 */
#[Group('migration')]
final class Version20260622080852RoundTripTest extends MigrationRoundTripTestCase
{
    public function testLayoutFieldLabelBackfillRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260622080852');
    }
}
