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
 * Seed the public `maintenance` CMS page.
 *
 * Unlike the other system pages (login, missing, no_access, …) there is no
 * `maintenance` row in `new_create_db.sql`, so this migration creates the
 * page itself AND its content. It mirrors the section-seeding approach
 * introduced by `Version20260501000600`.
 *
 * The page is the styled "we're down for maintenance" screen the frontend
 * renders to visitors while {@see \App\Service\System\MaintenanceModeService}
 * reports the instance as in maintenance. The operator's live note is shown
 * through the `{{system.maintenance_message}}` interpolation variable
 * (resolved by {@see \App\Service\Core\VariableResolverService}), so editing
 * the maintenance message from the admin panel updates this page without a
 * content change. A hardcoded fallback page on the frontend covers the case
 * where this CMS page is missing or unreachable.
 *
 * `is_open_access = 1` keeps the page readable by anonymous visitors (no
 * login during an outage), and the maintenance 503 gate exempts the
 * `by-keyword/maintenance` fetch so the page renders even while every other
 * `/cms-api` route is returning 503.
 */
final class Version20260615150000 extends AbstractMigration
{
    /** Locales seeded out of the box; more can be added from the admin UI. */
    private const LOCALES = ['en-GB', 'de-CH'];

    private const KEYWORD = 'maintenance';
    private const PREFIX = 'maintenance-sys';

    public function getDescription(): string
    {
        return 'Seed the public maintenance page (page row + styled sections with {{system.maintenance_message}}).';
    }

    public function up(Schema $schema): void
    {
        // 1. Create the page row (idempotent on the unique keyword). The page
        //    type + access type are copied from an existing core system page
        //    (`missing`) so the FK values stay valid regardless of id drift,
        //    and we don't depend on the (renamed) column ids being constant.
        //    The page is open-access + system, like the other status pages.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `pages`
                (`keyword`, `url`, `id_parent_page`, `is_headless`, `nav_position`, `footer_position`, `id_page_types`, `id_page_access_types`, `is_open_access`, `is_system`)
            SELECT 'maintenance', '/maintenance', NULL, 0, NULL, NULL, src.`id_page_types`, src.`id_page_access_types`, 1, 1
            FROM `pages` src
            WHERE src.`keyword` = 'missing'
            LIMIT 1
        SQL);

        // 2. Page-level title + description translations (SEO + tab title).
        $this->insertPageFieldTranslations('title', [
            'en-GB' => 'Under maintenance',
            'de-CH' => 'Wartungsarbeiten',
        ]);
        $this->insertPageFieldTranslations('description', [
            'en-GB' => 'The platform is temporarily offline for maintenance.',
            'de-CH' => 'Die Plattform ist vorübergehend wegen Wartungsarbeiten offline.',
        ]);

        // 3. ACL rows (consistent with the other system pages; anonymous
        //    visitors additionally bypass ACL via is_open_access = 1).
        $this->insertAclRows();

        // 4. Styled section tree carrying the interpolated operator message.
        $this->insertSections([
            [
                'key' => 'wrapper',
                'style' => 'container',
                'fields' => [
                    'mantine_size' => 'sm',
                    'mantine_py' => 'xl',
                ],
            ],
            [
                'key' => 'paper',
                'style' => 'paper',
                'parent' => 'wrapper',
                'fields' => [
                    'mantine_paper_shadow' => 'sm',
                    'mantine_radius' => 'md',
                    'mantine_border' => '1',
                    'mantine_px' => 'xl',
                    'mantine_py' => 'xl',
                ],
            ],
            [
                'key' => 'icon',
                'style' => 'theme-icon',
                'parent' => 'paper',
                'fields' => [
                    'mantine_color' => 'blue',
                    'mantine_variant' => 'light',
                    'mantine_size' => '64px',
                    'mantine_radius' => 'xl',
                    'mantine_left_icon' => 'IconTool',
                ],
            ],
            [
                'key' => 'title',
                'style' => 'title',
                'parent' => 'paper',
                'fields' => ['mantine_title_order' => '1'],
                'translations' => [
                    'content' => [
                        'en-GB' => 'We will be right back',
                        'de-CH' => 'Wir sind gleich zurück',
                    ],
                ],
            ],
            [
                'key' => 'lead',
                'style' => 'text',
                'parent' => 'paper',
                'translations' => [
                    'text' => [
                        'en-GB' => 'Our platform is temporarily offline while we carry out scheduled maintenance. Thank you for your patience — please try again in a little while.',
                        'de-CH' => 'Unsere Plattform ist vorübergehend offline, während wir geplante Wartungsarbeiten durchführen. Vielen Dank für Ihre Geduld — bitte versuchen Sie es in Kürze erneut.',
                    ],
                ],
            ],
            [
                // The operator's live note. The {{system.maintenance_message}}
                // token is replaced at render time with the message set from
                // the admin panel (or a friendly default when none is set).
                'key' => 'message',
                'style' => 'alert',
                'parent' => 'paper',
                'fields' => [
                    'mantine_color' => 'blue',
                    'mantine_variant' => 'light',
                    'mantine_radius' => 'md',
                    'mantine_left_icon' => 'IconInfoCircle',
                ],
                'translations' => [
                    'mantine_alert_title' => [
                        'en-GB' => 'Status',
                        'de-CH' => 'Status',
                    ],
                    'value' => [
                        'en-GB' => '{{system.maintenance_message}}',
                        'de-CH' => '{{system.maintenance_message}}',
                    ],
                ],
            ],
        ]);
    }

    public function down(Schema $schema): void
    {
        // Sections do NOT cascade off pages — delete by our unique name prefix.
        $this->addSql("DELETE FROM `sections` WHERE `name` LIKE '" . self::PREFIX . "-%'");

        // We created the page row here, so remove it on rollback. Page-level
        // translations + ACL rows cascade off the page id.
        $this->addSql("DELETE FROM `pages` WHERE `keyword` = '" . self::KEYWORD . "'");
    }

    /**
     * Insert a page-level field translation (title / description) per locale.
     *
     * @param array<string, string> $byLocale locale => content
     */
    private function insertPageFieldTranslations(string $fieldName, array $byLocale): void
    {
        foreach ($byLocale as $locale => $content) {
            $escaped = $this->escape($content);
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
                SELECT p.id, f.id, l.id, '{$escaped}'
                FROM `pages` p
                JOIN `fields` f ON f.`name` = '{$fieldName}'
                JOIN `languages` l ON l.`locale` = '{$locale}'
                WHERE p.`keyword` = 'maintenance'
            SQL);
        }
    }

    /**
     * admin: select + update; therapist + subject: read-only. Mirrors the
     * ACL pattern used by the other seeded system pages.
     */
    private function insertAclRows(): void
    {
        $rules = [
            ['admin',     1, 0, 1, 0],
            ['therapist', 1, 0, 0, 0],
            ['subject',   1, 0, 0, 0],
        ];

        foreach ($rules as [$group, $sel, $ins, $upd, $del]) {
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `page_acl_groups`
                    (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
                SELECT g.id, p.id, {$sel}, {$ins}, {$upd}, {$del}
                FROM `groups` g, `pages` p
                WHERE g.`name` = '{$group}' AND p.`keyword` = 'maintenance'
            SQL);
        }
    }

    /**
     * Walk the section descriptors and emit the SQL to insert each section,
     * wire it into the page (top-level) or its parent (nested), and persist
     * its property + translation field values.
     *
     * @param list<array<string, mixed>> $sections
     */
    private function insertSections(array $sections): void
    {
        $rootPos = 10;
        $childPos = [];

        foreach ($sections as $entry) {
            $sectionName = self::PREFIX . '-' . $entry['key'];
            $styleName = $entry['style'];
            $parentKey = $entry['parent'] ?? null;

            $this->addSql(<<<SQL
                INSERT INTO `sections` (`id_styles`, `name`)
                SELECT s.id, '{$sectionName}'
                FROM `styles` s
                WHERE s.`name` = '{$styleName}'
            SQL);

            if ($parentKey === null) {
                $this->addSql(<<<SQL
                    INSERT INTO `rel_pages_sections` (`id_pages`, `id_sections`, `position`)
                    SELECT p.id, sec.id, {$rootPos}
                    FROM `pages` p, `sections` sec
                    WHERE p.`keyword` = 'maintenance' AND sec.`name` = '{$sectionName}'
                SQL);
                $rootPos += 10;
            } else {
                $parentName = self::PREFIX . '-' . $parentKey;
                $childPos[$parentName] = ($childPos[$parentName] ?? 0) + 10;
                $pos = $childPos[$parentName];
                $this->addSql(<<<SQL
                    INSERT INTO `rel_sections_hierarchy` (`id_parent_section`, `id_child_section`, `position`)
                    SELECT parent_sec.id, child_sec.id, {$pos}
                    FROM `sections` parent_sec, `sections` child_sec
                    WHERE parent_sec.`name` = '{$parentName}'
                      AND child_sec.`name` = '{$sectionName}'
                SQL);
            }

            foreach ($entry['fields'] ?? [] as $fieldName => $value) {
                $escaped = $this->escape((string) $value);
                $this->addSql(<<<SQL
                    INSERT INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
                    SELECT sec.id, f.id, 1, '{$escaped}', NULL
                    FROM `sections` sec
                    JOIN `fields` f ON f.`name` = '{$fieldName}'
                    WHERE sec.`name` = '{$sectionName}'
                SQL);
            }

            foreach ($entry['translations'] ?? [] as $fieldName => $byLocale) {
                foreach ($byLocale as $locale => $value) {
                    if (!in_array($locale, self::LOCALES, true)) {
                        continue;
                    }
                    $escaped = $this->escape($value);
                    $this->addSql(<<<SQL
                        INSERT INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `content`, `meta`)
                        SELECT sec.id, f.id, l.id, '{$escaped}', NULL
                        FROM `sections` sec
                        JOIN `fields` f ON f.`name` = '{$fieldName}'
                        JOIN `languages` l ON l.`locale` = '{$locale}'
                        WHERE sec.`name` = '{$sectionName}'
                    SQL);
                }
            }
        }
    }

    /**
     * Escape a literal for direct interpolation into a single-quoted SQL
     * string. Only handles the characters our seeded content can produce
     * (single quote, backslash); we never accept user input here.
     */
    private function escape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
