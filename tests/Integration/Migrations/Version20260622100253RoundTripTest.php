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
 * Round-trip for switching the shared `text` content field from the `textarea`
 * editor to `markdown-inline` (so authors can apply inline bold/italic/underline
 * on the text + highlight styles). The type flip must reverse cleanly back to
 * `textarea`.
 */
#[Group('migration')]
final class Version20260622100253RoundTripTest extends MigrationRoundTripTestCase
{
    public function testTextFieldMarkdownInlineRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260622100253');
    }
}
