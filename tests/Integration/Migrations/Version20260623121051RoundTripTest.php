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
 * Per-migration round-trip for the CMS mobile-preview session route seed
 * (`Version20260623121051`): the admin mint route + its
 * `admin.mobile_preview.create` permission and the public exchange route.
 *
 * `down()` removes both routes, the permission link, and the permission, so
 * reverting and re-applying must leave the schema in sync with the ORM mapping.
 * Mirrors {@see Version20260602091045RoundTripTest}.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260623121051RoundTripTest extends MigrationRoundTripTestCase
{
    public function testMobilePreviewRouteSeedMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260623121051');
    }
}
