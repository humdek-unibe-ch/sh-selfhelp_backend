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
 * Wrap the three CMS error-surface sections (no-access, no-access-guest,
 * missing) in a container, mirroring the structure used by the login page:
 *
 *   page → *-sys-wrapper (container)
 *              └─ *-sys (noAccess / noAccess / missing style)
 *
 * Before this migration each page had one direct top-level section. After it
 * the style section becomes a child of a container wrapper, matching how
 * login-sys-wrapper → login-sys-form is wired.
 *
 * Idempotent: wrappers are inserted with a NOT EXISTS guard and the
 * rel_pages_sections / rel_sections_hierarchy links use INSERT IGNORE.
 */
final class Version20260608090032 extends AbstractMigration
{
    /** @var array<string, array{wrapper: string, child: string}> */
    private const PAGES = [
        'no-access'       => ['wrapper' => 'no-access-sys-wrapper',      'child' => 'no-access-sys'],
        'no-access-guest' => ['wrapper' => 'no-access-guest-sys-wrapper', 'child' => 'no-access-guest-sys'],
        'missing'         => ['wrapper' => 'missing-sys-wrapper',         'child' => 'missing-sys'],
    ];

    public function getDescription(): string
    {
        return 'Wrap no-access, no-access-guest and missing CMS sections in a container, matching the login page structure.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::PAGES as $pageKeyword => $names) {
            $this->wrapSection($pageKeyword, $names['wrapper'], $names['child']);
        }
    }

    public function down(Schema $schema): void
    {
        // Deleting the wrapper cascades its rel_pages_sections link and the
        // rel_sections_hierarchy row via FK ON DELETE CASCADE. The child style
        // section loses its page link; restore it directly on the page.
        foreach (self::PAGES as $pageKeyword => $names) {
            $wrapper = $names['wrapper'];
            $child   = $names['child'];

            // Re-attach the child section directly to the page.
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `rel_pages_sections` (`id_pages`, `id_sections`, `position`)
                SELECT p.id, s.id, 10
                FROM `pages` p, `sections` s
                WHERE p.`keyword` = :pageKeyword AND s.`name` = :child
            SQL, ['pageKeyword' => $pageKeyword, 'child' => $child]);

            // Remove the wrapper (cascades its page link and hierarchy link).
            $this->addSql(
                "DELETE FROM `sections` WHERE `name` = :wrapper",
                ['wrapper' => $wrapper]
            );
        }
    }

    // ------------------------------------------------------------------

    private function wrapSection(string $pageKeyword, string $wrapperName, string $childName): void
    {
        // 1. Detach the child section from the page (it will live under the
        //    wrapper instead).
        $this->addSql(<<<SQL
            DELETE rps FROM `rel_pages_sections` rps
            JOIN `sections` s ON s.id = rps.id_sections
            JOIN `pages`    p ON p.id = rps.id_pages
            WHERE p.`keyword` = :pageKeyword AND s.`name` = :child
        SQL, ['pageKeyword' => $pageKeyword, 'child' => $childName]);

        // 2. Create the container wrapper section (guarded).
        $this->addSql(<<<SQL
            INSERT INTO `sections` (`id_styles`, `name`)
            SELECT s.id, :wrapper
            FROM `styles` s
            WHERE s.`name` = 'container'
              AND NOT EXISTS (SELECT 1 FROM `sections` x WHERE x.`name` = :wrapper)
        SQL, ['wrapper' => $wrapperName]);

        // 3. Link the wrapper to the page.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_pages_sections` (`id_pages`, `id_sections`, `position`)
            SELECT p.id, sec.id, 10
            FROM `pages` p, `sections` sec
            WHERE p.`keyword` = :pageKeyword AND sec.`name` = :wrapper
        SQL, ['pageKeyword' => $pageKeyword, 'wrapper' => $wrapperName]);

        // 4. Link the style section as a child of the wrapper.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_sections_hierarchy` (`id_parent_section`, `id_child_section`, `position`)
            SELECT parent.id, child.id, 10
            FROM `sections` parent, `sections` child
            WHERE parent.`name` = :wrapper AND child.`name` = :child
        SQL, ['wrapper' => $wrapperName, 'child' => $childName]);
    }
}
