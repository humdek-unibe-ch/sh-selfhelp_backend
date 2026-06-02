<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes a duplicate `resetPassword-resetPassword` section that causes the
 * reset-password system page to render a duplicated UI block.
 *
 * Down migration is intentionally a no-op: this section is a duplicate that
 * should never have existed; restoring it would re-introduce the UI bug it
 * fixes.
 */
final class Version20260522091651 extends AbstractMigration
{
    private const SECTION_NAME = 'resetPassword-resetPassword';

    public function getDescription(): string
    {
        return 'Remove duplicate resetPassword-resetPassword section that caused a UI rendering bug on the reset-password system page.';
    }

    public function up(Schema $schema): void
    {
        $name = self::SECTION_NAME;
        $this->addSql("DELETE FROM sections WHERE `name` = '{$name}'");
    }

    public function down(Schema $schema): void
    {
        // Intentionally empty — see class docblock.
    }
}
