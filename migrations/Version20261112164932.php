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
 * Impressum: the SelfHelp 1 "Components & versions" area is removed on every
 * instance and replaced by the generated "about the platform" block.
 *
 * Deleted by section name, with every descendant, and with no content check
 * (operator edits of the old area are removed too):
 *  - `impressum-sys-h2-stack` (heading)
 *  - `impressum-sys-stack-text` (intro)
 *  - `impressum-sys-stack-card` (the SH1 component card and everything in it)
 *  - `impressum-sys-stack-note` (the "composer show" note)
 *
 * One `version` section `impressum-sys-about` is then added with all eight
 * headings in en-GB and de-CH, at the former position of `h2-stack`, or at
 * the end of the page when none of the four existed. A page that already has
 * a `version` section (anywhere in its tree) gets none. A missing page is
 * skipped.
 *
 * down() removes `impressum-sys-about` only. The SH1 text is not restored: it
 * listed versions that were never true for SelfHelp 2.
 */
final class Version20261112164932 extends AbstractMigration
{
    private const PAGE = 'impressum';
    private const STYLE = 'version';
    private const SECTION = 'impressum-sys-about';
    private const ANCHOR = 'impressum-sys-h2-stack';

    /** Top-level SH1 sections removed with every descendant. */
    private const REMOVE = [
        'impressum-sys-h2-stack',
        'impressum-sys-stack-text',
        'impressum-sys-stack-card',
        'impressum-sys-stack-note',
    ];

    /** Headings of the new section: field => [en-GB, de-CH]. */
    private const HEADINGS = [
        'title' => ['About this platform', 'Über diese Plattform'],
        'label_frameworks' => ['Frameworks', 'Frameworks'],
        'label_plugins' => ['Plugins', 'Plugins'],
        'label_libraries' => ['Libraries', 'Bibliotheken'],
        'label_web' => ['Website', 'Website'],
        'label_app' => ['Mobile app', 'Mobile App'],
        'label_filter' => ['Filter', 'Filtern'],
        'label_no_match' => ['Nothing matches the filter.', 'Nichts entspricht dem Filter.'],
    ];

    public function getDescription(): string
    {
        return 'Impressum: remove the SelfHelp 1 Components & versions area on every instance and place one version section there.';
    }

    public function up(Schema $schema): void
    {
        $pageId = $this->fetchInt('SELECT id FROM `pages` WHERE `keyword` = ?', [self::PAGE]);
        if ($pageId === null) {
            return;
        }

        $position = $this->fetchInt(
            'SELECT rps.`position` FROM `rel_pages_sections` rps JOIN `sections` s ON s.id = rps.id_sections '
            . 'WHERE rps.id_pages = ? AND s.`name` = ?',
            [$pageId, self::ANCHOR]
        );

        foreach (self::REMOVE as $name) {
            $ids = $this->sectionTreeIds($pageId, $name);
            if ($ids === []) {
                continue;
            }
            // Page links, hierarchy rows and texts go with the sections (ON DELETE CASCADE).
            $this->addSql('DELETE FROM `sections` WHERE `id` IN (' . implode(', ', $ids) . ')');
        }

        if ($this->pageHasVersionSection($pageId)) {
            return;
        }

        $position ??= ($this->fetchInt('SELECT MAX(`position`) FROM `rel_pages_sections` WHERE id_pages = ?', [$pageId]) ?? 0) + 10;

        // `sections.name` is not unique, so the name is only used when no
        // section of that name exists yet.
        $this->addSql(
            'INSERT INTO `sections` (`id_styles`, `name`) SELECT s.id, ? FROM `styles` s '
            . 'WHERE s.`name` = ? AND NOT EXISTS (SELECT 1 FROM `sections` x WHERE x.`name` = ?)',
            [self::SECTION, self::STYLE, self::SECTION]
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_pages_sections` (`id_pages`, `id_sections`, `position`) '
            . 'SELECT ?, sec.id, ? FROM `sections` sec WHERE sec.`name` = ?',
            [$pageId, $position, self::SECTION]
        );
        foreach (self::HEADINGS as $field => $texts) {
            foreach (['en-GB' => $texts[0], 'de-CH' => $texts[1]] as $locale => $text) {
                $this->addSql(
                    'INSERT IGNORE INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`) '
                    . 'SELECT sec.id, f.id, l.id, ?, NULL FROM `sections` sec '
                    . 'JOIN `fields` f ON f.`name` = ? JOIN `languages` l ON l.`locale` = ? WHERE sec.`name` = ?',
                    [$text, $field, $locale, self::SECTION]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // The page link and the heading texts go with the section (ON DELETE CASCADE).
        // The SH1 area is not restored.
        $this->addSql('DELETE FROM `sections` WHERE `name` = ?', [self::SECTION]);
    }

    /**
     * Ids of the named top-level page section and every descendant, or [] when
     * that name is not linked to the page.
     *
     * @return list<int>
     */
    private function sectionTreeIds(int $pageId, string $sectionName): array
    {
        $rootId = $this->fetchInt(
            'SELECT s.id FROM `sections` s JOIN `rel_pages_sections` rps ON rps.id_sections = s.id AND rps.id_pages = ? WHERE s.`name` = ?',
            [$pageId, $sectionName]
        );
        if ($rootId === null) {
            return [];
        }

        $rows = $this->connection->fetchFirstColumn(
            'WITH RECURSIVE tree AS ( '
            . 'SELECT s.id FROM `sections` s WHERE s.id = ? '
            . 'UNION ALL '
            . 'SELECT c.id FROM tree t '
            . 'JOIN `rel_sections_hierarchy` h ON h.id_parent_section = t.id '
            . 'JOIN `sections` c ON c.id = h.id_child_section) '
            . 'SELECT id FROM tree',
            [$rootId]
        );

        return array_map(static fn ($id): int => (int) $id, $rows);
    }

    /**
     * Whether the page has a `version` section anywhere in its tree.
     */
    private function pageHasVersionSection(int $pageId): bool
    {
        $count = $this->fetchInt(
            'WITH RECURSIVE tree AS ( '
            . 'SELECT rps.id_sections AS id FROM `rel_pages_sections` rps WHERE rps.id_pages = ? '
            . 'UNION ALL '
            . 'SELECT h.id_child_section FROM tree t JOIN `rel_sections_hierarchy` h ON h.id_parent_section = t.id) '
            . 'SELECT COUNT(*) FROM tree t JOIN `sections` s ON s.id = t.id JOIN `styles` st ON st.id = s.id_styles AND st.`name` = ?',
            [$pageId, self::STYLE]
        );

        return ($count ?? 0) > 0;
    }

    /**
     * @param list<int|string> $params
     */
    private function fetchInt(string $sql, array $params): ?int
    {
        $value = $this->connection->fetchOne($sql, $params);

        return is_numeric($value) ? (int) $value : null;
    }
}
