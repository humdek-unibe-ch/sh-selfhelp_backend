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
 * Round-trip for `Version20261028125153`, which registers the form upload zip
 * routes. Release-tier (`#[Group('migration')]`).
 */
#[Group('migration')]
final class Version20261028125153RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFormFileZipRoutesMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20261028125153');
    }
}
