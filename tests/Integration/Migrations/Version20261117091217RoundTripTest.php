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
 * Round-trip for Version20261117091217: form fields linked onto entry-form-list.
 */
#[Group('migration')]
final class Version20261117091217RoundTripTest extends MigrationRoundTripTestCase
{
    public function testListFormFieldsMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261117091217');
    }
}
