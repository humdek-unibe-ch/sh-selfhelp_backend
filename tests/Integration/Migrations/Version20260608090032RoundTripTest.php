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
 * Per-migration round-trip for the container-wrapper addition
 * (`Version20260608090032`: wraps the no-access, no-access-guest and missing
 * CMS sections in a container, mirroring the login page structure).
 */
#[Group('migration')]
final class Version20260608090032RoundTripTest extends MigrationRoundTripTestCase
{
    public function testErrorPageContainerWrapperMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260608090032');
    }
}
