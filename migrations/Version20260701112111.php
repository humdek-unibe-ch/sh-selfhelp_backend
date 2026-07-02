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
 * Searchable content projection table for ACL-filtered public content search,
 * plus guarded replacement of the baseline `home-sys*` landing with the polished
 * hero-home example on fresh installs.
 */
final class Version20260701112111 extends AbstractMigration
{
    private const LOCALES = ['en-GB', 'de-CH'];

    private const HERO_PREFIX = 'hero-home-mig';

    public function getDescription(): string
    {
        return 'Add page_search_index table, backfill search projection, seed default menu items, and hero-home on untouched home.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE page_search_index (id INT AUTO_INCREMENT NOT NULL, title_text LONGTEXT DEFAULT NULL, description_text LONGTEXT DEFAULT NULL, body_text LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, id_pages INT NOT NULL, id_languages INT NOT NULL, INDEX IDX_A5A08A6CEF1A445 (id_pages), INDEX idx_page_search_index_id_languages (id_languages), UNIQUE INDEX uq_page_search_index_page_lang (id_pages, id_languages), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('ALTER TABLE page_search_index ADD CONSTRAINT FK_A5A08A6CEF1A445 FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE page_search_index ADD CONSTRAINT FK_A5A08A620E4EF5E FOREIGN KEY (id_languages) REFERENCES languages (id) ON DELETE CASCADE');

        $this->backfillSearchIndex();
        $this->seedDefaultNavigationMenuItems();

        if (!$this->isUntouchedDefaultHome()) {
            return;
        }

        $this->removeBaselineHomeSections();
        $this->seedHeroHomeSections();
        $this->backfillSearchIndex();
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM `sections` WHERE `name` LIKE '" . self::HERO_PREFIX . "-%'");

        $this->addSql('ALTER TABLE page_search_index DROP FOREIGN KEY FK_A5A08A6CEF1A445');
        $this->addSql('ALTER TABLE page_search_index DROP FOREIGN KEY FK_A5A08A620E4EF5E');
        $this->addSql('DROP TABLE page_search_index');
    }

    private function isUntouchedDefaultHome(): bool
    {
        $pageId = $this->connection->fetchOne("SELECT id FROM pages WHERE keyword = 'home' LIMIT 1");
        if ($pageId === false) {
            return false;
        }

        $heroCount = (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :pageId
                  AND s.name LIKE :heroPrefix
                SQL,
            ['pageId' => $pageId, 'heroPrefix' => self::HERO_PREFIX . '-%'],
        );
        if ($heroCount > 0) {
            return false;
        }

        $baselineCount = (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :pageId
                  AND s.name LIKE 'home-sys-%'
                SQL,
            ['pageId' => $pageId],
        );
        if ($baselineCount === 0) {
            return false;
        }

        $customCount = (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :pageId
                  AND s.name NOT LIKE 'home-sys-%'
                SQL,
            ['pageId' => $pageId],
        );

        return $customCount === 0;
    }

    private function removeBaselineHomeSections(): void
    {
        $this->addSql("DELETE FROM `sections` WHERE `name` LIKE 'home-sys-%'");
    }

    private function seedHeroHomeSections(): void
    {
        $this->insertPageDescriptionTranslations('home', [
            'en-GB' => 'SelfHelp — build accessible digital health experiences with structured CMS content.',
            'de-CH' => 'SelfHelp — entwickeln Sie zugängliche digitale Gesundheitserlebnisse mit strukturierten CMS-Inhalten.',
        ]);

        $this->insertSections('home', self::HERO_PREFIX, [
            [
                'key' => 'shell',
                'style' => 'container',
                'fields' => ['css' => 'hero-home-shell'],
            ],
            [
                'key' => 'title',
                'style' => 'title',
                'parent' => 'shell',
                'fields' => ['mantine_title_order' => '1'],
                'translations' => [
                    'content' => [
                        'en-GB' => 'Welcome to SelfHelp',
                        'de-CH' => 'Willkommen bei SelfHelp',
                    ],
                ],
            ],
            [
                'key' => 'lead',
                'style' => 'text',
                'parent' => 'shell',
                'translations' => [
                    'text' => [
                        'en-GB' => 'Build accessible digital health experiences with a flexible CMS, structured content, and platform-aware navigation.',
                        'de-CH' => 'Entwickeln Sie zugängliche digitale Gesundheitserlebnisse mit einem flexiblen CMS, strukturierten Inhalten und plattformbewusster Navigation.',
                    ],
                ],
            ],
            [
                'key' => 'actions',
                'style' => 'group',
                'parent' => 'shell',
            ],
            [
                'key' => 'cta-primary',
                'style' => 'button',
                'parent' => 'actions',
                'fields' => [
                    'url' => '/about',
                    'variant' => 'filled',
                    'is_link' => '1',
                ],
                'translations' => [
                    'label' => [
                        'en-GB' => 'Get started',
                        'de-CH' => 'Loslegen',
                    ],
                ],
            ],
            [
                'key' => 'cta-secondary',
                'style' => 'button',
                'parent' => 'actions',
                'fields' => [
                    'url' => '/contact',
                    'variant' => 'outline',
                    'is_link' => '1',
                ],
                'translations' => [
                    'label' => [
                        'en-GB' => 'Learn more',
                        'de-CH' => 'Mehr erfahren',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, string> $descriptions
     */
    private function insertPageDescriptionTranslations(string $keyword, array $descriptions): void
    {
        foreach ($descriptions as $locale => $description) {
            $escaped = $this->escape($description);
            $this->addSql(<<<SQL
                INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
                SELECT p.id, f.id, l.id, '{$escaped}'
                FROM `pages` p
                JOIN `fields` f ON f.`name` = 'description'
                JOIN `languages` l ON l.`locale` = '{$locale}'
                WHERE p.`keyword` = '{$keyword}'
            SQL);
        }
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private function insertSections(string $keyword, string $prefix, array $sections): void
    {
        $rootPos = 10;
        $childPos = [];

        foreach ($sections as $entry) {
            $sectionName = $prefix . '-' . $entry['key'];
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
                    WHERE p.`keyword` = '{$keyword}' AND sec.`name` = '{$sectionName}'
                SQL);
                $rootPos += 10;
            } else {
                $parentName = $prefix . '-' . $parentKey;
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

    private function escape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    /**
     * Populate title/description projections so content search works immediately
     * after migrate. Run `app:navigation:rebuild-search-index` for a full body rebuild.
     */
    private function backfillSearchIndex(): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO page_search_index (title_text, description_text, body_text, updated_at, id_pages, id_languages)
            SELECT
                (
                    SELECT pft.content
                    FROM pages_fields_translation pft
                    INNER JOIN fields f ON f.id = pft.id_fields
                    WHERE pft.id_pages = p.id
                      AND pft.id_languages = l.id
                      AND f.display = 1
                      AND f.name IN ('title', 'name')
                    ORDER BY FIELD(f.name, 'title', 'name')
                    LIMIT 1
                ),
                (
                    SELECT pft.content
                    FROM pages_fields_translation pft
                    INNER JOIN fields f ON f.id = pft.id_fields
                    WHERE pft.id_pages = p.id
                      AND pft.id_languages = l.id
                      AND f.display = 1
                      AND f.name IN ('description', 'meta_description')
                    LIMIT 1
                ),
                NULL,
                UTC_TIMESTAMP(),
                p.id,
                l.id
            FROM pages p
            CROSS JOIN languages l
            SQL);
    }

    /**
     * Ensure core system pages appear in menus on greenfield installs where no
     * legacy nav_position/footer_position rows existed to migrate.
     */
    private function seedDefaultNavigationMenuItems(): void
    {
        $this->seedPageMenuItemIfMissing('home', 'web_header', 'manual', 10, null);
        $this->seedPageMenuItemIfMissing('home', 'mobile_drawer', 'manual', 10, null);
        $this->seedPageMenuItemIfMissing('home', 'mobile_bottom_tabs', 'manual', 10, null);
        $this->seedPageMenuItemIfMissing('privacy', 'web_footer', 'manual', 400, null);
    }

    private function seedPageMenuItemIfMissing(
        string $pageKeyword,
        string $menuKey,
        string $childSource,
        int $position,
        ?int $autoIncludeDepth,
    ): void {
        $depthSql = $autoIncludeDepth === null ? 'NULL' : (string) $autoIncludeDepth;
        $this->addSql(<<<SQL
            INSERT INTO navigation_menu_items (
                id_navigation_menus, id_parent_item, id_item_type, id_pages, external_url,
                icon_override, position, id_child_source, auto_include_depth, is_active
            )
            SELECT
                nm.id,
                NULL,
                (SELECT id FROM lookups WHERE type_code = 'navigationMenuItemTypes' AND lookup_code = 'page' LIMIT 1),
                p.id,
                NULL,
                NULL,
                {$position},
                (SELECT id FROM lookups WHERE type_code = 'navigationChildSources' AND lookup_code = '{$childSource}' LIMIT 1),
                {$depthSql},
                1
            FROM pages p
            INNER JOIN navigation_menus nm ON nm.id_navigation_menu_key = (
                SELECT id FROM lookups WHERE type_code = 'navigationMenuKeys' AND lookup_code = '{$menuKey}' LIMIT 1
            )
            WHERE p.keyword = '{$pageKeyword}'
              AND NOT EXISTS (
                SELECT 1
                FROM navigation_menu_items existing
                WHERE existing.id_navigation_menus = nm.id
                  AND existing.id_pages = p.id
              )
            SQL);
    }
}
