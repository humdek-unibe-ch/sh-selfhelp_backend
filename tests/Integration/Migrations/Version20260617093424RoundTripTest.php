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
 * Per-migration round-trip for `Version20260617093424` (drop the obsolete
 * `admin_sections_force_delete_v1` API route whose controller method no longer
 * exists, superseded by section detach/destroy).
 *
 * Proves the route removal applies, down() restores the legacy route + its
 * permission link, and the ORM mapping stays in sync. Release-tier
 * (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260617093424RoundTripTest extends MigrationRoundTripTestCase
{
    public function testForceDeleteRouteRemovalRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260617093424');
    }
}
