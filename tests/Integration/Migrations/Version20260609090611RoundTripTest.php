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
 * Per-migration round-trip for the section-DELETE split
 * (`Version20260609090611`): repoints `admin_sections_delete` to the detach
 * action and adds the page-independent `admin_sections_destroy` route.
 *
 * Removing vs. deleting a section is a core CMS edit workflow (and the
 * distinction matters for shared refContainers), so the migration's
 * down()/up() reversibility is certified here. Release-tier
 * (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260609090611RoundTripTest extends MigrationRoundTripTestCase
{
    public function testSectionDeleteSplitMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260609090611');
    }
}
