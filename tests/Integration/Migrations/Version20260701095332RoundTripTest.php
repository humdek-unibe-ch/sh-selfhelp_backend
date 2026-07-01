<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Support\MigrationRoundTripTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('migration')]
final class Version20260701095332RoundTripTest extends MigrationRoundTripTestCase
{
    protected function migrationClass(): string
    {
        return 'DoctrineMigrations\\Version20260701095332';
    }
}
