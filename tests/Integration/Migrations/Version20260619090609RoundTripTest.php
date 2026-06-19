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
 * Per-migration round-trip for `Version20260619090609` (style field cleanup
 * slice 1: remove use_web_style/is_log/security-question/email-leftover fields,
 * unlink alert.value, drop the alert close-button twin, rename alert title +
 * datepicker typo).
 *
 * Data-only migration: schema is unchanged, so the round-trip proves up()/down()
 * apply without error and leave the ORM-mapped schema in sync. Release-tier
 * (`#[Group('migration')]`): needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20260619090609RoundTripTest extends MigrationRoundTripTestCase
{
    public function testStyleFieldCleanupSlice1RoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\\Version20260619090609');
    }
}
