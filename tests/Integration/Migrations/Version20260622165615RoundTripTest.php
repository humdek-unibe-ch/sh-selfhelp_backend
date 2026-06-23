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
 * Round-trip for the field-naming unification (Option B): dropping the
 * `shared_*` field-name prefix (47 fields -> unprefixed; height/width/icon kept
 * as reserved-name exceptions) must reverse cleanly and leave the ORM-mapped
 * schema in sync.
 */
#[Group('migration')]
final class Version20260622165615RoundTripTest extends MigrationRoundTripTestCase
{
    public function testSharedPrefixDropRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260622165615');
    }
}
