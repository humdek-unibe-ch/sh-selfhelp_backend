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
 * Round-trip for the typography/media/interactive style-field pass: converting
 * `list_item_content` to markdown-inline, splitting blockquote onto a dedicated
 * `blockquote_content` field, and adding the figure/link/action-icon/image/
 * spoiler/video/audio fields. up() then down() must restore the catalog.
 */
#[Group('migration')]
final class Version20260622110041RoundTripTest extends MigrationRoundTripTestCase
{
    public function testStyleFieldPassRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260622110041');
    }
}
