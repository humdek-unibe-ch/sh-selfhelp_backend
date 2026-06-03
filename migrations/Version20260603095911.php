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
 * Rebuild the /register page section tree into a single clean structure.
 *
 * Two earlier migrations both seeded register sections:
 *   - Version20260522100014 created `register-sys-wrapper` (container) +
 *     `register-sys-form` (register style);
 *   - Version20260601095658 created `register-sys-container` (container) +
 *     another `register-sys-form` (register style).
 *
 * The result was a page with two top-level containers and two sections named
 * `register-sys-form`, with the second container linked to BOTH form sections
 * (the hierarchy INSERT...SELECT matched the duplicate name twice). The public
 * /register page therefore rendered duplicate registration forms.
 *
 * This migration removes every `register-sys-%` section plus its page links,
 * hierarchy links and field translations, then recreates exactly one container
 * wrapping one `register` style section. The `register` page row and its ACL
 * (seeded by Version20260522100014) are intentionally left untouched.
 */
final class Version20260603095911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rebuild the /register page section tree: drop duplicate register-sys sections, recreate one container + one register section.';
    }

    public function up(Schema $schema): void
    {
        $this->cleanupRegisterSections();
        $this->createCleanRegisterSections();
    }

    public function down(Schema $schema): void
    {
        // Remove the clean section tree created by up(). The historical
        // duplicates are deliberately not restored — they were a defect.
        $this->cleanupRegisterSections();
    }

    private function cleanupRegisterSections(): void
    {
        // Hierarchy links where a register-sys section is the parent or child.
        $this->addSql(<<<SQL
            DELETE rsh FROM `rel_sections_hierarchy` rsh
            JOIN `sections` s ON (s.id = rsh.id_parent_section OR s.id = rsh.id_child_section)
            WHERE s.`name` LIKE 'register-sys-%'
        SQL);

        // Page links.
        $this->addSql(<<<SQL
            DELETE rps FROM `rel_pages_sections` rps
            JOIN `sections` s ON s.id = rps.id_sections
            WHERE s.`name` LIKE 'register-sys-%'
        SQL);

        // Field translations attached to those sections.
        $this->addSql(<<<SQL
            DELETE sft FROM `sections_fields_translation` sft
            JOIN `sections` s ON s.id = sft.id_sections
            WHERE s.`name` LIKE 'register-sys-%'
        SQL);

        // Finally the sections themselves.
        $this->addSql("DELETE FROM `sections` WHERE `name` LIKE 'register-sys-%'");
    }

    private function createCleanRegisterSections(): void
    {
        // Container wrapper section, linked directly to the register page.
        $this->addSql(<<<SQL
            INSERT INTO `sections` (`id_styles`, `name`, `css`)
            SELECT s.id, 'register-sys-container', ''
            FROM `styles` s WHERE s.`name` = 'container'
        SQL);
        $this->addSql(<<<SQL
            INSERT INTO `rel_pages_sections` (`id_pages`, `id_sections`, `position`)
            SELECT p.id, sec.id, 10
            FROM `pages` p, `sections` sec
            WHERE p.`keyword` = 'register' AND sec.`name` = 'register-sys-container'
        SQL);

        // Register style section, child of the container.
        $this->addSql(<<<SQL
            INSERT INTO `sections` (`id_styles`, `name`, `css`)
            SELECT s.id, 'register-sys-form', ''
            FROM `styles` s WHERE s.`name` = 'register'
        SQL);
        $this->addSql(<<<SQL
            INSERT INTO `rel_sections_hierarchy` (`id_parent_section`, `id_child_section`, `position`)
            SELECT parent.id, child.id, 10
            FROM `sections` parent, `sections` child
            WHERE parent.`name` = 'register-sys-container' AND child.`name` = 'register-sys-form'
        SQL);
    }
}
