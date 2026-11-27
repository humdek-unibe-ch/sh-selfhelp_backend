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
final class Version20261120083532RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPermissionCatalogueRouteForRoleEditorsRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20261120083532');
    }
}
