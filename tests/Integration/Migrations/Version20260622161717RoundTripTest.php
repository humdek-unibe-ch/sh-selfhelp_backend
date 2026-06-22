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
 * Round-trip for the form/notification/show-user-input authoring upgrade: the new
 * title/description/alert-title/confirm links, the show-user-input title +
 * empty_text fields, and the notification shared_icon + shared_with_close_button
 * promotion (rename + relink) must reverse cleanly and leave the ORM-mapped schema
 * in sync.
 */
#[Group('migration')]
final class Version20260622161717RoundTripTest extends MigrationRoundTripTestCase
{
    public function testStyleAuthoringUpgradeRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260622161717');
    }
}
