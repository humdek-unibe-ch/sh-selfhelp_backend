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
 * Final navigation/search catalog and data migration.
 *
 * The preceding migration creates the final ORM schema. This migration seeds
 * that schema directly, migrates the legacy page-owned menu/icon data once,
 * and then removes the superseded page fields and columns. It deliberately
 * contains none of the abandoned exclusion, virtual-child-source, config JSON,
 * or translation-table churn from the feature branch's development history.
 */
final class Version20260710093045 extends AbstractMigration
{
    private const VERSION = 'v1';

    private const PERM_READ = 'admin.navigation.read';
    private const PERM_UPDATE = 'admin.navigation.update';
    private const PERM_EXPORT = 'admin.navigation.export';
    private const PERM_IMPORT = 'admin.navigation.import';

    private const HERO_PREFIX = 'hero-home-mig';

    private const LOCALES = ['en-GB', 'de-CH'];

    private const PAGE_ICON_FIELDS = ['icon', 'mobile_icon'];

    private const FIELD_REF_TABLES = [
        'sections_fields_translation',
        'rel_fields_styles',
        'rel_fields_pages',
        'pages_fields_translation',
        'rel_fields_page_types',
    ];

    public function getDescription(): string
    {
        return 'Seed final navigation/search data, migrate legacy page navigation/icons, remove legacy fields, and seed the final API routes.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->seedNavigationLookups();
        $this->seedSystemMenus();
        $this->seedNavigationSettings();

        $this->migrateLegacyMenuPositions();
        $this->seedDefaultNavigationMenuItems();
        $this->copyLegacyPageIconsToMenuItems();
        $this->removeLegacyPageIconFields();

        $this->backfillSearchIndex();
        if ($this->isUntouchedDefaultHome()) {
            $this->removeBaselineHomeSections();
            $this->seedHeroHomeSections();
            $this->backfillSearchIndex();
        }

        $this->replaceGetUserAclProcedure(withLegacyMenuColumns: false);
        $this->addSql('ALTER TABLE pages DROP nav_position, DROP footer_position');
        $this->removeLegacyNavRenderFields();

        $this->seedSearchVisibilityField();
        $this->seedNavigationPermissionsAndRoutes();
    }

    public function down(Schema $schema): void
    {
        $this->removeNavigationRoutesAndPermissions();
        $this->removeSearchVisibilityField();

        // Restore the legacy destinations before clearing final menu data so a
        // down/up round-trip can preserve the best available page positions and
        // web icon value. Per-item mobile icons have no merge-base destination.
        $this->restoreLegacyPageIconField();
        $this->copyMenuIconsBackToPages();
        $this->addSql('ALTER TABLE pages ADD nav_position INT DEFAULT NULL, ADD footer_position INT DEFAULT NULL');
        $this->copyMenuPositionsBackToPages();
        $this->replaceGetUserAclProcedure(withLegacyMenuColumns: true);

        $this->addSql('DELETE FROM page_search_index');
        $this->addSql('DELETE FROM navigation_menu_item_translations');
        $this->addSql('DELETE FROM navigation_menu_items');
        $this->addSql('DELETE FROM navigation_menus');
        $this->addSql('DELETE FROM navigation_settings');
        $this->addSql('DELETE FROM user_navigation_state');
        $this->removeNavigationLookups();

        // Guarded hero content is intentionally retained. Deleting it cannot
        // reconstruct the displaced baseline tree and would make down/up lose
        // the system home page. The up guard recognizes the prefix and remains
        // idempotent.
    }

    private function seedNavigationLookups(): void
    {
        $rows = [
            ['navigationMenuKeys', 'web_header', 'Web header', 'Public website header menu'],
            ['navigationMenuKeys', 'web_footer', 'Web footer', 'Public website footer menu'],
            ['navigationMenuKeys', 'mobile_drawer', 'Mobile drawer', 'Mobile drawer navigation menu'],
            ['navigationMenuKeys', 'mobile_bottom_tabs', 'Mobile bottom tabs', 'Mobile bottom tab bar menu'],
            ['navigationPlatforms', 'web', 'Web', 'Web platform'],
            ['navigationPlatforms', 'mobile', 'Mobile', 'Mobile platform'],
            ['navigationSurfaces', 'header', 'Header', 'Header surface'],
            ['navigationSurfaces', 'footer', 'Footer', 'Footer surface'],
            ['navigationSurfaces', 'drawer', 'Drawer', 'Drawer surface'],
            ['navigationSurfaces', 'bottom_tabs', 'Bottom tabs', 'Bottom tab bar surface'],
            ['navigationMenuPresets', 'simple', 'Simple', 'Flat header links'],
            ['navigationMenuPresets', 'dropdown', 'Dropdown', 'Nested dropdown header'],
            ['navigationMenuPresets', 'mega-menu', 'Mega menu', 'Rich mega menu header'],
            ['navigationMenuPresets', 'tabs', 'Tabs', 'Header tabs'],
            ['navigationMenuPresets', 'double-dropdown', 'Double dropdown', 'Utility row plus dropdown'],
            ['navigationMenuPresets', 'double-mega-menu', 'Double mega menu', 'Utility row plus mega menu'],
            ['navigationMenuPresets', 'columns', 'Columns (grouped)', 'Footer columns built from group headings'],
            ['navigationMenuPresets', 'inline', 'Inline links', 'Flat centered footer link row'],
            ['navigationMenuItemTypes', 'page', 'Page', 'Link to a CMS page'],
            ['navigationMenuItemTypes', 'external_url', 'External URL', 'External hyperlink'],
            ['navigationMenuItemTypes', 'group', 'Group', 'Non-clickable menu group'],
            ['navigationSearchModes', 'off', 'Off', 'Search disabled'],
            ['navigationSearchModes', 'menu_pages', 'Menu pages', 'Search menu pages only'],
            ['navigationSearchModes', 'searchable_pages', 'Searchable pages', 'Search page metadata'],
            ['navigationSearchModes', 'content_index', 'Content index', 'Search published page content'],
            ['navigationSearchVisibility', 'all_accessible_pages', 'All accessible pages', 'Default search visibility'],
            ['navigationSearchVisibilityOverrides', 'inherit', 'Inherit', 'Inherit global search visibility'],
            ['navigationSearchVisibilityOverrides', 'visible', 'Visible', 'Force visible in search'],
            ['navigationSearchVisibilityOverrides', 'hidden', 'Hidden', 'Hide from search'],
            ['navigationSearchFieldPolicies', 'all_display_text', 'All display text', 'Index all display text fields'],
            ['navigationSearchFieldPolicies', 'page_metadata_only', 'Page metadata only', 'Index title and description only'],
            ['navigationStartModes', 'fixed_page', 'Fixed page', 'Always use configured landing page'],
            ['navigationStartModes', 'last_visited_then_fixed_page', 'Last visited then fixed', 'Resume last valid page'],
            ['navigationMobileStartSources', 'same_as_web', 'Same as web', 'Mobile uses web start pages'],
            ['navigationMobileStartSources', 'custom_mobile_pages', 'Custom mobile pages', 'Separate mobile start pages'],
            ['navigationRouteSyncPolicies', 'ask', 'Ask', 'Ask when old route conflicts'],
            ['navigationRouteSyncPolicies', 'keep_alias', 'Keep alias', 'Keep old route as alias'],
            ['navigationRouteSyncPolicies', 'remove_old_route', 'Remove old route', 'Remove old route on sync'],
            ['navigationChildrenNavModes', 'sidebar', 'Left sidebar', 'Branch pages render a left sidebar with the parent and its children plus a prev/next pager'],
            ['navigationChildrenNavModes', 'pills', 'Top pill strip', 'Branch pages render a compact horizontal pill strip above the content'],
            ['navigationChildrenNavModes', 'none', 'Hidden', 'Branch pages render only their own content without generated child navigation'],
        ];

        foreach ($rows as [$type, $code, $value, $description]) {
            $this->addSql(
                'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
                [$type, $code, $value, $description],
            );
        }
    }

    private function seedSystemMenus(): void
    {
        $menus = [
            ['web_header', 'web', 'header', 'dropdown', 2, 5],
            ['web_footer', 'web', 'footer', 'columns', 2, null],
            ['mobile_drawer', 'mobile', 'drawer', null, 2, null],
            ['mobile_bottom_tabs', 'mobile', 'bottom_tabs', null, 2, 5],
        ];

        foreach ($menus as [$key, $platform, $surface, $preset, $maxDepth, $itemLimit]) {
            $presetSql = $preset === null
                ? 'NULL'
                : "(SELECT id FROM lookups WHERE type_code = 'navigationMenuPresets' AND lookup_code = "
                    . $this->connection->quote($preset) . ' LIMIT 1)';
            $itemLimitSql = $itemLimit === null ? 'NULL' : (string) $itemLimit;

            $this->addSql(<<<SQL
                INSERT IGNORE INTO navigation_menus (
                    id_navigation_menu_key,
                    id_platform,
                    id_surface,
                    id_preset,
                    max_depth,
                    item_limit,
                    id_children_nav,
                    show_breadcrumbs,
                    show_pager,
                    is_system
                )
                SELECT mk.id, pf.id, sf.id, {$presetSql}, {$maxDepth}, {$itemLimitSql}, NULL, 1, 1, 1
                FROM lookups mk
                JOIN lookups pf ON pf.type_code = 'navigationPlatforms' AND pf.lookup_code = '{$platform}'
                JOIN lookups sf ON sf.type_code = 'navigationSurfaces' AND sf.lookup_code = '{$surface}'
                WHERE mk.type_code = 'navigationMenuKeys' AND mk.lookup_code = '{$key}'
                SQL);
        }
    }

    private function seedNavigationSettings(): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO navigation_settings (
                id,
                id_web_header_search_mode,
                web_header_search_min_chars,
                web_header_search_result_limit,
                id_search_default_visibility,
                id_search_field_policy,
                id_web_guest_start_page,
                id_web_user_start_page,
                id_web_user_start_mode,
                id_mobile_guest_start_page,
                id_mobile_user_start_page,
                id_mobile_user_start_mode,
                id_mobile_start_page_source,
                id_route_sync_old_route_policy,
                logo_asset_path,
                logo_alt,
                id_logo_link_page,
                logo_size,
                logo_variant
            )
            SELECT
                1,
                (SELECT id FROM lookups WHERE type_code = 'navigationSearchModes' AND lookup_code = 'content_index' LIMIT 1),
                2,
                8,
                (SELECT id FROM lookups WHERE type_code = 'navigationSearchVisibility' AND lookup_code = 'all_accessible_pages' LIMIT 1),
                (SELECT id FROM lookups WHERE type_code = 'navigationSearchFieldPolicies' AND lookup_code = 'all_display_text' LIMIT 1),
                (SELECT id FROM pages WHERE keyword = 'home' LIMIT 1),
                (SELECT id FROM pages WHERE keyword = 'home' LIMIT 1),
                (SELECT id FROM lookups WHERE type_code = 'navigationStartModes' AND lookup_code = 'fixed_page' LIMIT 1),
                (SELECT id FROM pages WHERE keyword = 'home' LIMIT 1),
                (SELECT id FROM pages WHERE keyword = 'home' LIMIT 1),
                (SELECT id FROM lookups WHERE type_code = 'navigationStartModes' AND lookup_code = 'fixed_page' LIMIT 1),
                (SELECT id FROM lookups WHERE type_code = 'navigationMobileStartSources' AND lookup_code = 'same_as_web' LIMIT 1),
                (SELECT id FROM lookups WHERE type_code = 'navigationRouteSyncPolicies' AND lookup_code = 'ask' LIMIT 1),
                NULL,
                NULL,
                NULL,
                'md',
                'logo-and-name'
            SQL);
    }

    private function migrateLegacyMenuPositions(): void
    {
        $this->insertLegacyPositionItems('web_header', 'nav_position', true, null);
        $this->insertLegacyPositionItems('web_footer', 'footer_position', false, null);
        $this->insertLegacyPositionItems('mobile_drawer', 'nav_position', true, null);
        $this->insertLegacyPositionItems('mobile_bottom_tabs', 'nav_position', true, 5);
    }

    private function insertLegacyPositionItems(
        string $menuKey,
        string $positionColumn,
        bool $rootPagesOnly,
        ?int $limit,
    ): void {
        $rootCondition = $rootPagesOnly ? ' AND p.id_parent_page IS NULL' : '';
        $limitSql = $limit === null ? '' : " ORDER BY p.{$positionColumn} ASC, p.id ASC LIMIT {$limit}";

        $this->addSql(<<<SQL
            INSERT INTO navigation_menu_items (
                id_navigation_menus,
                id_parent_item,
                id_item_type,
                id_pages,
                external_url,
                icon,
                mobile_icon,
                label,
                position,
                layer,
                id_children_nav,
                show_pager,
                is_active
            )
            SELECT
                nm.id,
                NULL,
                (SELECT id FROM lookups WHERE type_code = 'navigationMenuItemTypes' AND lookup_code = 'page' LIMIT 1),
                p.id,
                NULL,
                NULL,
                NULL,
                NULL,
                COALESCE(p.{$positionColumn}, 0) * 10,
                NULL,
                NULL,
                NULL,
                1
            FROM pages p
            JOIN navigation_menus nm ON nm.id_navigation_menu_key = (
                SELECT id FROM lookups
                WHERE type_code = 'navigationMenuKeys' AND lookup_code = '{$menuKey}'
                LIMIT 1
            )
            WHERE p.{$positionColumn} IS NOT NULL
              AND p.is_headless = 0{$rootCondition}
              AND NOT EXISTS (
                  SELECT 1
                  FROM navigation_menu_items existing
                  WHERE existing.id_navigation_menus = nm.id
                    AND existing.id_pages = p.id
              ){$limitSql}
            SQL);
    }

    private function seedDefaultNavigationMenuItems(): void
    {
        $this->seedPageMenuItemIfMissing('home', 'web_header', 10);
        $this->seedPageMenuItemIfMissing('home', 'mobile_drawer', 10);
        $this->seedPageMenuItemIfMissing('home', 'mobile_bottom_tabs', 10);
        $this->seedPageMenuItemIfMissing('privacy', 'web_footer', 400);
    }

    private function seedPageMenuItemIfMissing(string $pageKeyword, string $menuKey, int $position): void
    {
        $this->addSql(<<<SQL
            INSERT INTO navigation_menu_items (
                id_navigation_menus,
                id_parent_item,
                id_item_type,
                id_pages,
                external_url,
                icon,
                mobile_icon,
                label,
                position,
                layer,
                id_children_nav,
                show_pager,
                is_active
            )
            SELECT
                nm.id,
                NULL,
                (SELECT id FROM lookups WHERE type_code = 'navigationMenuItemTypes' AND lookup_code = 'page' LIMIT 1),
                p.id,
                NULL,
                NULL,
                NULL,
                NULL,
                {$position},
                NULL,
                NULL,
                NULL,
                1
            FROM pages p
            INNER JOIN navigation_menus nm ON nm.id_navigation_menu_key = (
                SELECT id FROM lookups
                WHERE type_code = 'navigationMenuKeys' AND lookup_code = '{$menuKey}'
                LIMIT 1
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

    private function copyLegacyPageIconsToMenuItems(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE navigation_menu_items nmi
            SET nmi.icon = COALESCE(
                    NULLIF(nmi.icon, ''),
                    (
                        SELECT NULLIF(pft.content, '')
                        FROM pages_fields_translation pft
                        INNER JOIN fields f ON f.id = pft.id_fields AND f.name = 'icon'
                        WHERE pft.id_pages = nmi.id_pages
                        ORDER BY (pft.id_languages = 1) DESC, pft.id_languages ASC
                        LIMIT 1
                    )
                ),
                nmi.mobile_icon = COALESCE(
                    NULLIF(nmi.mobile_icon, ''),
                    (
                        SELECT NULLIF(pft.content, '')
                        FROM pages_fields_translation pft
                        INNER JOIN fields f ON f.id = pft.id_fields AND f.name = 'mobile_icon'
                        WHERE pft.id_pages = nmi.id_pages
                        ORDER BY (pft.id_languages = 1) DESC, pft.id_languages ASC
                        LIMIT 1
                    )
                )
            WHERE nmi.id_pages IS NOT NULL
            SQL);
    }

    private function removeLegacyPageIconFields(): void
    {
        foreach (self::PAGE_ICON_FIELDS as $field) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `{$table}` WHERE id_fields = (SELECT id FROM `fields` WHERE `name` = ?)",
                    [$field],
                );
            }
            $this->addSql('DELETE FROM fields WHERE name = ?', [$field]);
        }

        $this->addSql("DELETE FROM field_types WHERE name = 'select-icon-mobile'");
    }

    private function restoreLegacyPageIconField(): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO fields (name, id_field_types, display, config)
            SELECT 'icon', ft.id, 0, NULL
            FROM field_types ft
            WHERE ft.name = 'text'
            LIMIT 1
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE fields f
            INNER JOIN field_types ft ON ft.name = 'text'
            SET f.id_field_types = ft.id,
                f.display = 0,
                f.config = NULL
            WHERE f.name = 'icon'
            SQL);

        foreach (['core', 'experiment', 'intern'] as $pageType) {
            $this->addSql(
                <<<'SQL'
                INSERT IGNORE INTO rel_fields_page_types (
                    id_page_types,
                    id_fields,
                    title,
                    help,
                    default_value
                )
                SELECT pt.id,
                       f.id,
                       'Page icon',
                       'The icon which will be used for menus. For mobile icons use prefix `mobile-`',
                       ''
                FROM page_types pt
                INNER JOIN fields f ON f.name = 'icon'
                WHERE pt.name = ?
                SQL,
                [$pageType],
            );
        }
    }

    private function copyMenuIconsBackToPages(): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO pages_fields_translation (
                id_pages,
                id_fields,
                id_languages,
                content
            )
            SELECT p.id,
                   f.id,
                   1,
                   (
                       SELECT nmi.icon
                       FROM navigation_menu_items nmi
                       INNER JOIN navigation_menus nm ON nm.id = nmi.id_navigation_menus
                       INNER JOIN lookups mk ON mk.id = nm.id_navigation_menu_key
                       WHERE nmi.id_pages = p.id
                         AND nmi.icon IS NOT NULL
                         AND nmi.icon <> ''
                       ORDER BY FIELD(mk.lookup_code, 'web_header', 'web_footer', 'mobile_drawer', 'mobile_bottom_tabs'),
                                nmi.position,
                                nmi.id
                       LIMIT 1
                   )
            FROM pages p
            INNER JOIN fields f ON f.name = 'icon'
            WHERE EXISTS (
                SELECT 1
                FROM navigation_menu_items nmi
                WHERE nmi.id_pages = p.id
                  AND nmi.icon IS NOT NULL
                  AND nmi.icon <> ''
            )
            SQL);
    }

    private function copyMenuPositionsBackToPages(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pages p
            SET p.nav_position = (
                    SELECT FLOOR(nmi.position / 10)
                    FROM navigation_menu_items nmi
                    INNER JOIN navigation_menus nm ON nm.id = nmi.id_navigation_menus
                    INNER JOIN lookups mk ON mk.id = nm.id_navigation_menu_key
                    WHERE nmi.id_pages = p.id
                      AND nmi.id_parent_item IS NULL
                      AND mk.type_code = 'navigationMenuKeys'
                      AND mk.lookup_code IN ('web_header', 'mobile_drawer', 'mobile_bottom_tabs')
                    ORDER BY FIELD(mk.lookup_code, 'web_header', 'mobile_drawer', 'mobile_bottom_tabs'),
                             nmi.position,
                             nmi.id
                    LIMIT 1
                ),
                p.footer_position = (
                    SELECT FLOOR(nmi.position / 10)
                    FROM navigation_menu_items nmi
                    INNER JOIN navigation_menus nm ON nm.id = nmi.id_navigation_menus
                    INNER JOIN lookups mk ON mk.id = nm.id_navigation_menu_key
                    WHERE nmi.id_pages = p.id
                      AND nmi.id_parent_item IS NULL
                      AND mk.type_code = 'navigationMenuKeys'
                      AND mk.lookup_code = 'web_footer'
                    ORDER BY nmi.position, nmi.id
                    LIMIT 1
                )
            SQL);
    }

    private function backfillSearchIndex(): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO page_search_index (
                title_text,
                description_text,
                body_text,
                updated_at,
                id_pages,
                id_languages
            )
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

    private function isUntouchedDefaultHome(): bool
    {
        $pageId = $this->connection->fetchOne("SELECT id FROM pages WHERE keyword = 'home' LIMIT 1");
        if (!is_int($pageId) && !is_string($pageId)) {
            return false;
        }

        $heroCountRaw = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :pageId
                  AND s.name LIKE :heroPrefix
                SQL,
            ['pageId' => $pageId, 'heroPrefix' => self::HERO_PREFIX . '-%'],
        );
        $heroCount = is_int($heroCountRaw) || is_string($heroCountRaw) ? (int) $heroCountRaw : 0;
        if ($heroCount > 0) {
            return false;
        }

        $baselineCountRaw = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :pageId
                  AND s.name LIKE 'home-sys-%'
                SQL,
            ['pageId' => $pageId],
        );
        $baselineCount = is_int($baselineCountRaw) || is_string($baselineCountRaw) ? (int) $baselineCountRaw : 0;
        if ($baselineCount === 0) {
            return false;
        }

        $customCountRaw = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sections s
                INNER JOIN rel_pages_sections ps ON ps.id_sections = s.id
                WHERE ps.id_pages = :pageId
                  AND s.name NOT LIKE 'home-sys-%'
                SQL,
            ['pageId' => $pageId],
        );
        $customCount = is_int($customCountRaw) || is_string($customCountRaw) ? (int) $customCountRaw : 0;

        return $customCount === 0;
    }

    private function removeBaselineHomeSections(): void
    {
        $this->addSql("DELETE FROM sections WHERE name LIKE 'home-sys-%'");
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
            $this->addSql(<<<SQL
                INSERT IGNORE INTO pages_fields_translation (
                    id_pages,
                    id_fields,
                    id_languages,
                    content
                )
                SELECT p.id, f.id, l.id, '{$this->escape($description)}'
                FROM pages p
                INNER JOIN fields f ON f.name = 'description'
                INNER JOIN languages l ON l.locale = '{$this->escape($locale)}'
                WHERE p.keyword = '{$this->escape($keyword)}'
                SQL);
        }
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private function insertSections(string $keyword, string $prefix, array $sections): void
    {
        $rootPosition = 10;
        /** @var array<string, int> $childPositions */
        $childPositions = [];

        foreach ($sections as $entry) {
            $key = $entry['key'] ?? null;
            $style = $entry['style'] ?? null;
            if (!is_string($key) || !is_string($style)) {
                continue;
            }
            $sectionName = $prefix . '-' . $key;
            $styleName = $style;
            $parent = $entry['parent'] ?? null;
            $parentKey = is_string($parent) ? $parent : null;

            $this->addSql(<<<SQL
                INSERT INTO sections (id_styles, name)
                SELECT s.id, '{$this->escape($sectionName)}'
                FROM styles s
                WHERE s.name = '{$this->escape($styleName)}'
                SQL);

            if ($parentKey === null) {
                $this->addSql(<<<SQL
                    INSERT INTO rel_pages_sections (id_pages, id_sections, position)
                    SELECT p.id, sec.id, {$rootPosition}
                    FROM pages p, sections sec
                    WHERE p.keyword = '{$this->escape($keyword)}'
                      AND sec.name = '{$this->escape($sectionName)}'
                    SQL);
                $rootPosition += 10;
            } else {
                $parentName = $prefix . '-' . $parentKey;
                $childPositions[$parentName] = ($childPositions[$parentName] ?? 0) + 10;
                $position = $childPositions[$parentName];
                $this->addSql(<<<SQL
                    INSERT INTO rel_sections_hierarchy (id_parent_section, id_child_section, position)
                    SELECT parent_sec.id, child_sec.id, {$position}
                    FROM sections parent_sec, sections child_sec
                    WHERE parent_sec.name = '{$this->escape($parentName)}'
                      AND child_sec.name = '{$this->escape($sectionName)}'
                    SQL);
            }

            $fields = $entry['fields'] ?? [];
            if (is_array($fields)) {
                foreach ($fields as $fieldName => $value) {
                    if (!is_string($fieldName) || !is_scalar($value)) {
                        continue;
                    }
                    $this->addSql(<<<SQL
                        INSERT INTO sections_fields_translation (
                            id_sections,
                            id_fields,
                            id_languages,
                            content,
                            meta
                        )
                        SELECT sec.id, f.id, 1, '{$this->escape((string) $value)}', NULL
                        FROM sections sec
                        INNER JOIN fields f ON f.name = '{$this->escape($fieldName)}'
                        WHERE sec.name = '{$this->escape($sectionName)}'
                        SQL);
                }
            }

            $translations = $entry['translations'] ?? [];
            if (!is_array($translations)) {
                continue;
            }
            foreach ($translations as $fieldName => $byLocale) {
                if (!is_string($fieldName) || !is_array($byLocale)) {
                    continue;
                }
                foreach ($byLocale as $locale => $value) {
                    if (!is_string($locale)
                        || !in_array($locale, self::LOCALES, true)
                        || !is_scalar($value)) {
                        continue;
                    }
                    $this->addSql(<<<SQL
                        INSERT INTO sections_fields_translation (
                            id_sections,
                            id_fields,
                            id_languages,
                            content,
                            meta
                        )
                        SELECT sec.id, f.id, l.id, '{$this->escape((string) $value)}', NULL
                        FROM sections sec
                        INNER JOIN fields f ON f.name = '{$this->escape($fieldName)}'
                        INNER JOIN languages l ON l.locale = '{$this->escape($locale)}'
                        WHERE sec.name = '{$this->escape($sectionName)}'
                        SQL);
                }
            }
        }
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    private function replaceGetUserAclProcedure(bool $withLegacyMenuColumns): void
    {
        $this->addSql('DROP PROCEDURE IF EXISTS get_user_acl');

        if ($withLegacyMenuColumns) {
            $this->addSql(<<<'SQL'
                CREATE PROCEDURE get_user_acl(
                    IN param_user_id INT,
                    IN param_page_id INT
                )
                BEGIN
                    SELECT
                        param_user_id AS id_users,
                        id_pages,
                        MAX(acl_select) AS acl_select,
                        MAX(acl_insert) AS acl_insert,
                        MAX(acl_update) AS acl_update,
                        MAX(acl_delete) AS acl_delete,
                        keyword,
                        url,
                        id_parent_page,
                        is_headless,
                        nav_position,
                        footer_position,
                        id_page_types,
                        id_page_access_types,
                        is_system
                    FROM (
                        SELECT
                            ug.id_users,
                            acl.id_pages,
                            acl.acl_select,
                            acl.acl_insert,
                            acl.acl_update,
                            acl.acl_delete,
                            p.keyword,
                            p.url,
                            p.id_parent_page,
                            p.is_headless,
                            p.nav_position,
                            p.footer_position,
                            p.id_page_types,
                            p.id_page_access_types,
                            p.is_system
                        FROM rel_groups_users ug
                        JOIN users u             ON ug.id_users   = u.id
                        JOIN page_acl_groups acl ON acl.id_groups = ug.id_groups
                        JOIN pages p             ON p.id          = acl.id_pages
                        WHERE ug.id_users = param_user_id
                          AND (param_page_id = -1 OR acl.id_pages = param_page_id)

                        UNION ALL

                        SELECT
                            param_user_id AS id_users,
                            p.id          AS id_pages,
                            1             AS acl_select,
                            0             AS acl_insert,
                            0             AS acl_update,
                            0             AS acl_delete,
                            p.keyword,
                            p.url,
                            p.id_parent_page,
                            p.is_headless,
                            p.nav_position,
                            p.footer_position,
                            p.id_page_types,
                            p.id_page_access_types,
                            p.is_system
                        FROM pages p
                        WHERE p.is_open_access = 1
                          AND (param_page_id = -1 OR p.id = param_page_id)
                    ) AS combined_acl
                    GROUP BY
                        id_pages,
                        keyword,
                        url,
                        id_parent_page,
                        is_headless,
                        nav_position,
                        footer_position,
                        id_page_types,
                        is_system,
                        id_page_access_types;
                END
                SQL);

            return;
        }

        $this->addSql(<<<'SQL'
            CREATE PROCEDURE get_user_acl(
                IN param_user_id INT,
                IN param_page_id INT
            )
            BEGIN
                SELECT
                    param_user_id AS id_users,
                    id_pages,
                    MAX(acl_select) AS acl_select,
                    MAX(acl_insert) AS acl_insert,
                    MAX(acl_update) AS acl_update,
                    MAX(acl_delete) AS acl_delete,
                    keyword,
                    url,
                    id_parent_page,
                    is_headless,
                    id_page_types,
                    id_page_access_types,
                    is_system
                FROM (
                    SELECT
                        ug.id_users,
                        acl.id_pages,
                        acl.acl_select,
                        acl.acl_insert,
                        acl.acl_update,
                        acl.acl_delete,
                        p.keyword,
                        p.url,
                        p.id_parent_page,
                        p.is_headless,
                        p.id_page_types,
                        p.id_page_access_types,
                        p.is_system
                    FROM rel_groups_users ug
                    JOIN users u             ON ug.id_users   = u.id
                    JOIN page_acl_groups acl ON acl.id_groups = ug.id_groups
                    JOIN pages p             ON p.id          = acl.id_pages
                    WHERE ug.id_users = param_user_id
                      AND (param_page_id = -1 OR acl.id_pages = param_page_id)

                    UNION ALL

                    SELECT
                        param_user_id AS id_users,
                        p.id          AS id_pages,
                        1             AS acl_select,
                        0             AS acl_insert,
                        0             AS acl_update,
                        0             AS acl_delete,
                        p.keyword,
                        p.url,
                        p.id_parent_page,
                        p.is_headless,
                        p.id_page_types,
                        p.id_page_access_types,
                        p.is_system
                    FROM pages p
                    WHERE p.is_open_access = 1
                      AND (param_page_id = -1 OR p.id = param_page_id)
                ) AS combined_acl
                GROUP BY
                    id_pages,
                    keyword,
                    url,
                    id_parent_page,
                    is_headless,
                    id_page_types,
                    is_system,
                    id_page_access_types;
            END
            SQL);
    }

    private function removeLegacyNavRenderFields(): void
    {
        foreach (['web_nav_render', 'mobile_nav_render'] as $field) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    "DELETE FROM `{$table}` WHERE id_fields = (SELECT id FROM fields WHERE name = ?)",
                    [$field],
                );
            }
            $this->addSql('DELETE FROM fields WHERE name = ?', [$field]);
        }

        $this->addSql("DELETE FROM field_types WHERE name IN ('select-nav-render-web', 'select-nav-render-mobile')");
    }

    private function seedSearchVisibilityField(): void
    {
        $config = json_encode([
            'options' => [
                ['value' => 'inherit', 'text' => 'Use global setting'],
                ['value' => 'visible', 'text' => 'Show in search'],
                ['value' => 'hidden', 'text' => 'Hide from search'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->addSql(
            <<<'SQL'
            INSERT INTO fields (name, id_field_types, display, config)
            SELECT 'search_visibility', ft.id, 0, ?
            FROM field_types ft
            WHERE ft.name = 'select'
            LIMIT 1
            ON DUPLICATE KEY UPDATE
                id_field_types = VALUES(id_field_types),
                config = VALUES(config),
                display = VALUES(display)
            SQL,
            [$config],
        );

        foreach (['core', 'experiment'] as $pageType) {
            $this->addSql(
                <<<'SQL'
                INSERT IGNORE INTO rel_fields_page_types (
                    id_page_types,
                    id_fields,
                    title,
                    help,
                    default_value
                )
                SELECT pt.id,
                       f.id,
                       'Search visibility',
                       'Controls whether this page can appear in website/app search results. Access permissions still apply.',
                       'inherit'
                FROM page_types pt
                INNER JOIN fields f ON f.name = 'search_visibility'
                WHERE pt.name = ?
                SQL,
                [$pageType],
            );
        }
    }

    private function removeSearchVisibilityField(): void
    {
        foreach (self::FIELD_REF_TABLES as $table) {
            $this->addSql(
                "DELETE FROM `{$table}` WHERE id_fields = (SELECT id FROM fields WHERE name = 'search_visibility')",
            );
        }
        $this->addSql("DELETE FROM fields WHERE name = 'search_visibility'");
    }

    private function seedNavigationPermissionsAndRoutes(): void
    {
        $permissions = [
            [self::PERM_READ, 'Read navigation menus and settings'],
            [self::PERM_UPDATE, 'Update navigation menus, items, and settings'],
            [self::PERM_EXPORT, 'Export navigation menus and settings'],
            [self::PERM_IMPORT, 'Import navigation menus and settings'],
        ];
        foreach ($permissions as [$name, $description]) {
            $this->addSql(
                'INSERT IGNORE INTO permissions (name, description) VALUES (?, ?)',
                [$name, $description],
            );
        }

        $this->addSql(
            'INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles) '
            . 'SELECT p.id, r.id FROM permissions p JOIN roles r ON r.name = ? '
            . 'WHERE p.name IN (?, ?, ?, ?)',
            ['admin', self::PERM_READ, self::PERM_UPDATE, self::PERM_EXPORT, self::PERM_IMPORT],
        );

        foreach ($this->navigationRoutes() as [$name, $path, $controller, $methods, $permission]) {
            $this->addSql(
                'DELETE rarp FROM rel_api_routes_permissions rarp '
                . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$name, self::VERSION],
            );
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$name, self::VERSION],
            );
            $this->addSql(
                'INSERT INTO api_routes '
                . '(route_name, version, path, controller, methods, requirements, params, id_plugins) '
                . 'VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [$name, self::VERSION, $path, $controller, $methods],
            );

            if ($permission === null) {
                continue;
            }
            $this->addSql(
                'INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id '
                . 'FROM api_routes ar '
                . 'INNER JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$permission, $name, self::VERSION],
            );
        }
    }

    private function removeNavigationRoutesAndPermissions(): void
    {
        foreach ($this->navigationRoutes() as [$name]) {
            $this->addSql(
                'DELETE rarp FROM rel_api_routes_permissions rarp '
                . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$name, self::VERSION],
            );
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$name, self::VERSION],
            );
        }

        $this->addSql(
            'DELETE rpr FROM rel_permissions_roles rpr '
            . 'INNER JOIN permissions p ON p.id = rpr.id_permissions '
            . 'WHERE p.name IN (?, ?, ?, ?)',
            [self::PERM_READ, self::PERM_UPDATE, self::PERM_EXPORT, self::PERM_IMPORT],
        );
        $this->addSql(
            'DELETE FROM permissions WHERE name IN (?, ?, ?, ?)',
            [self::PERM_READ, self::PERM_UPDATE, self::PERM_EXPORT, self::PERM_IMPORT],
        );
    }

    /**
     * @return list<array{string, string, string, string, string|null}>
     */
    private function navigationRoutes(): array
    {
        return [
            ['navigation_get', '/navigation', 'App\\Controller\\Api\\V1\\Frontend\\NavigationController::getNavigation', 'GET', null],
            ['admin_navigation_get', '/admin/navigation', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::getOverview', 'GET', self::PERM_READ],
            ['admin_navigation_menu_preview', '/admin/navigation/menus/{menu_key}/preview', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::previewMenu', 'GET', self::PERM_READ],
            ['admin_navigation_menu_update', '/admin/navigation/menus/{menu_key}', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::updateMenu', 'PUT', self::PERM_UPDATE],
            ['admin_navigation_menu_item_create', '/admin/navigation/menus/{menu_key}/items', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::createMenuItem', 'POST', self::PERM_UPDATE],
            ['admin_navigation_menu_item_update', '/admin/navigation/items/{item_id}', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::updateMenuItem', 'PUT', self::PERM_UPDATE],
            ['admin_navigation_menu_item_delete', '/admin/navigation/items/{item_id}', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::deleteMenuItem', 'DELETE', self::PERM_UPDATE],
            ['admin_navigation_menu_reorder', '/admin/navigation/menus/{menu_key}/reorder', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::reorderMenuItems', 'PUT', self::PERM_UPDATE],
            ['admin_navigation_settings_update', '/admin/navigation/settings', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::updateSettings', 'PUT', self::PERM_UPDATE],
            ['admin_navigation_export', '/admin/navigation/export', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::exportNavigation', 'POST', self::PERM_EXPORT],
            ['admin_navigation_import_validate', '/admin/navigation/import/validate', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::validateImportNavigation', 'POST', self::PERM_IMPORT],
            ['admin_navigation_import', '/admin/navigation/import', 'App\\Controller\\Api\\V1\\Admin\\AdminNavigationController::importNavigation', 'POST', self::PERM_IMPORT],
            ['search_get', '/search', 'App\\Controller\\Api\\V1\\Frontend\\SearchController::search', 'GET', null],
            ['search_pages_get', '/search/pages', 'App\\Controller\\Api\\V1\\Frontend\\SearchController::searchPages', 'GET', null],
            ['navigation_last_visited_put', '/navigation/last-visited', 'App\\Controller\\Api\\V1\\Frontend\\NavigationController::recordLastVisited', 'PUT', null],
        ];
    }

    private function removeNavigationLookups(): void
    {
        $this->addSql(<<<'SQL'
            DELETE FROM lookups
            WHERE type_code IN (
                'navigationMenuKeys',
                'navigationPlatforms',
                'navigationSurfaces',
                'navigationMenuPresets',
                'navigationMenuItemTypes',
                'navigationSearchModes',
                'navigationSearchVisibility',
                'navigationSearchVisibilityOverrides',
                'navigationSearchFieldPolicies',
                'navigationStartModes',
                'navigationMobileStartSources',
                'navigationRouteSyncPolicies',
                'navigationChildrenNavModes'
            )
            SQL);
    }
}
