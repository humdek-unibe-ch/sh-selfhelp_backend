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
 * Proves the whole migration chain applies cleanly from scratch, that the head
 * migration is reversible, and that the resulting schema matches the ORM
 * mapping (`doctrine:schema:validate` clean) — the canonical migration safety
 * net (plan §"Migration testing").
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class MigrationChainRoundTripTest extends MigrationRoundTripTestCase
{
    public function testFullChainMigratesAndHeadMigrationRoundTrips(): void
    {
        $this->assertChainRoundTrips();
    }
}
