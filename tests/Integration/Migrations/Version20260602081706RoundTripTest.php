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
 * Per-migration round-trip for the frontend form-route registration
 * (`Version20260602081706`: authoritatively (re-)registers the
 * `/cms-api/v1/forms/*` rows in api_routes so the legacy api_routes seed can be
 * retired).
 *
 * Form submission is a core workflow (golden FormActionJobChain), and AGENTS.md
 * names this migration as the authoritative form-route source, so its
 * down()/up() reversibility is explicitly certified here. Release-tier
 * (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260602081706RoundTripTest extends MigrationRoundTripTestCase
{
    public function testFormRoutesRegistrationMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260602081706');
    }
}
