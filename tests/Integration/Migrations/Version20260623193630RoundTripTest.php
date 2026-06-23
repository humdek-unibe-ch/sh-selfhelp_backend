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
 * Per-migration round-trip for the `admin.mobile_preview.view` permission seed
 * (`Version20260623193630`): the full-screen CMS Live Preview entitlement and
 * its admin-role grant.
 *
 * `down()` removes the role link and the permission, so reverting and
 * re-applying must leave the schema in sync with the ORM mapping. Mirrors
 * {@see Version20260623121051RoundTripTest}.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260623193630RoundTripTest extends MigrationRoundTripTestCase
{
    public function testMobilePreviewViewPermissionMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260623193630');
    }
}
