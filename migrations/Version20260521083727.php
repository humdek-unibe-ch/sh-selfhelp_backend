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
 * Removes a duplicate `twoFactorAuth-twoFactorAuth` section that was created
 * by an earlier iteration of the 2FA seed flow and caused the two-factor
 * system page to render a duplicated UI block.
 *
 * Targets the section by its functional name (the seed never produces more
 * than one section with that exact name; uniqueness is enforced by the seed
 * data, not by a DB constraint) so the migration is portable across
 * environments where the integer primary key may differ.
 *
 * Down migration is intentionally a no-op: this section is a duplicate that
 * should never have existed; restoring it would re-introduce the UI bug it
 * fixes. If a rollback is ever needed, re-running the original baseline seed
 * will recreate the canonical (non-duplicate) section row.
 */
final class Version20260521083727 extends AbstractMigration
{
    private const SECTION_NAME = 'twoFactorAuth-twoFactorAuth';

    public function getDescription(): string
    {
        return 'Remove duplicate twoFactorAuth-twoFactorAuth section that caused a UI rendering bug on the 2FA system page.';
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
