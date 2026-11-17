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
 * Per-migration round-trip for the row template of the data view
 * (`Version20261113210755`): the `data-view` style may take child sections as
 * the layout of one record (layout `custom`) and gets its `list_row_css`
 * property. The migration changes no table shape; the round trip proves
 * `down()` takes the additions out again (it refuses only while sections sit
 * inside a data view, which a fresh database does not have) and that the
 * re-applied `up()` leaves the schema in step with the ORM mapping.
 *
 * Release-tier (`#[Group('migration')]`): slow + needs CREATE DATABASE.
 */
#[Group('migration')]
final class Version20261113210755RoundTripTest extends MigrationRoundTripTestCase
{
    public function testDataViewRowTemplateMigrationRoundTrips(): void
    {
        $this->assertMigrationRoundTrips('DoctrineMigrations\Version20261113210755');
    }
}
