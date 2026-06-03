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
 * Per-migration round-trip for the plugin runtime migration
 * (`Version20260523141331`: plugin runtime ESM columns + Messenger transport
 * table + the unified install/update API routes).
 *
 * It touches both schema (new columns/table) and seeded routes, so the
 * revert/re-apply exercises the trickiest combination in the plugin chain.
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260523141331RoundTripTest extends MigrationRoundTripTestCase
{
    public function testPluginRuntimeAndMessengerMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260523141331');
    }
}
