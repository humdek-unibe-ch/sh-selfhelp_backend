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
 * Round-trip for the form/interactive capability pass: the new number-input,
 * color-input, tabs, switch, shared_max_length, mobile keyboard and
 * progress-root.shared_radius links (plus the select.alt unlink) must reverse
 * cleanly and leave the ORM-mapped schema in sync.
 */
#[Group('migration')]
final class Version20260622132034RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFormInteractiveCapabilityPassRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260622132034');
    }
}
