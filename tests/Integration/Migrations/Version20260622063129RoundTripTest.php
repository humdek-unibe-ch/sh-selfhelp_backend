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
 * Round-trip for the layout cross-platform field pass: width/height/cols/grid-col/
 * divider/space promotions to shared_*, paper.title + simple-grid responsive cols,
 * and the web_px/web_py/web_breakpoints removals must all reverse cleanly.
 */
#[Group('migration')]
final class Version20260622063129RoundTripTest extends MigrationRoundTripTestCase
{
    public function testLayoutCrossPlatformPassRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260622063129');
    }
}
