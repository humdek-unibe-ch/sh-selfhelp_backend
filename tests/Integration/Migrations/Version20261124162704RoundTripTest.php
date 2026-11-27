<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Support\MigrationRoundTripTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('migration')]
final class Version20261124162704RoundTripTest extends MigrationRoundTripTestCase
{
    public function testDataRowsUsersIndexRoundTrip(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20261124162704');
    }
}
