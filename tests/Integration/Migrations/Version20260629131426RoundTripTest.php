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
 * Round-trip for the mail-body backfill that converts legacy full-HTML-document
 * bodies to WYSIWYG fragments. It is a data-only, idempotent backfill (down() is
 * an intentional no-op), so the chain must migrate up, revert, re-apply, and keep
 * the ORM schema in sync.
 */
#[Group('migration')]
final class Version20260629131426RoundTripTest extends MigrationRoundTripTestCase
{
    public function testMailBodyFragmentBackfillRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260629131426');
    }
}
