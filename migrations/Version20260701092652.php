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
 * Navigation menu builder: tables, lookup seeds, system menus, settings singleton,
 * data migration from pages.nav_position/footer_position, and public GET /navigation route.
 */
final class Version20260701092652 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    private const VERSION = 'v1';
    private const ROUTE_NAVIGATION_GET = 'navigation_get';

    public function getDescription(): string
    {
        return 'Navigation menu builder schema, lookup seeds, system menus, data migration from nav_position/footer_position, GET /navigation route.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE navigation_menu_item_exclusions (id INT AUTO_INCREMENT NOT NULL, id_navigation_menu_items INT NOT NULL, id_pages INT NOT NULL, INDEX IDX_8C8A2DD36A8744E7 (id_navigation_menu_items), INDEX IDX_8C8A2DD3CEF1A445 (id_pages), UNIQUE INDEX uq_navigation_menu_item_exclusions_item_page (id_navigation_menu_items, id_pages), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('CREATE TABLE navigation_menu_item_translations (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) DEFAULT NULL, description VARCHAR(500) DEFAULT NULL, aria_label VARCHAR(255) DEFAULT NULL, id_navigation_menu_items INT NOT NULL, id_languages INT NOT NULL, INDEX IDX_E701902C6A8744E7 (id_navigation_menu_items), INDEX IDX_E701902C20E4EF5E (id_languages), UNIQUE INDEX uq_navigation_menu_item_translations_item_lang (id_navigation_menu_items, id_languages), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('CREATE TABLE navigation_menu_items (id INT AUTO_INCREMENT NOT NULL, external_url VARCHAR(500) DEFAULT NULL, icon_override VARCHAR(100) DEFAULT NULL, position INT NOT NULL, auto_include_depth INT DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, id_navigation_menus INT NOT NULL, id_parent_item INT DEFAULT NULL, id_item_type INT NOT NULL, id_pages INT DEFAULT NULL, id_child_source INT NOT NULL, INDEX IDX_BB30D3E7C4DBAFF5 (id_item_type), INDEX IDX_BB30D3E7151ECE5D (id_child_source), INDEX idx_navigation_menu_items_id_navigation_menus (id_navigation_menus), INDEX idx_navigation_menu_items_id_parent_item (id_parent_item), INDEX idx_navigation_menu_items_id_pages (id_pages), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('CREATE TABLE navigation_menus (id INT AUTO_INCREMENT NOT NULL, max_depth INT DEFAULT NULL, item_limit INT DEFAULT NULL, is_system TINYINT DEFAULT 1 NOT NULL, config JSON DEFAULT NULL, id_navigation_menu_key INT NOT NULL, id_platform INT NOT NULL, id_surface INT NOT NULL, id_preset INT DEFAULT NULL, INDEX IDX_CC49980A69893C5E (id_platform), INDEX IDX_CC49980A62DC371E (id_surface), INDEX IDX_CC49980AA6851DF (id_preset), UNIQUE INDEX uq_navigation_menus_id_navigation_menu_key (id_navigation_menu_key), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('CREATE TABLE navigation_settings (id INT NOT NULL, web_header_search_min_chars INT DEFAULT 2 NOT NULL, web_header_search_result_limit INT DEFAULT 8 NOT NULL, id_web_header_search_mode INT NOT NULL, id_search_default_visibility INT NOT NULL, id_search_field_policy INT NOT NULL, id_web_guest_start_page INT DEFAULT NULL, id_web_user_start_page INT DEFAULT NULL, id_web_user_start_mode INT NOT NULL, id_mobile_guest_start_page INT DEFAULT NULL, id_mobile_user_start_page INT DEFAULT NULL, id_mobile_user_start_mode INT NOT NULL, id_mobile_start_page_source INT NOT NULL, id_route_sync_old_route_policy INT NOT NULL, INDEX IDX_2C0F024D23C60493 (id_web_header_search_mode), INDEX IDX_2C0F024D8DB25976 (id_search_default_visibility), INDEX IDX_2C0F024D8B1FC201 (id_search_field_policy), INDEX IDX_2C0F024DEC3F2CAD (id_web_guest_start_page), INDEX IDX_2C0F024D174EA010 (id_web_user_start_page), INDEX IDX_2C0F024D948E519B (id_web_user_start_mode), INDEX IDX_2C0F024DB09D1FB2 (id_mobile_guest_start_page), INDEX IDX_2C0F024D617BBAB (id_mobile_user_start_page), INDEX IDX_2C0F024D85D74A20 (id_mobile_user_start_mode), INDEX IDX_2C0F024D86EE5D6B (id_mobile_start_page_source), INDEX IDX_2C0F024D65234D8B (id_route_sync_old_route_policy), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('CREATE TABLE user_navigation_state (id INT AUTO_INCREMENT NOT NULL, url_snapshot VARCHAR(255) DEFAULT NULL, keyword_snapshot VARCHAR(100) DEFAULT NULL, updated_at DATETIME NOT NULL, id_users INT NOT NULL, id_platform INT NOT NULL, id_pages INT NOT NULL, INDEX IDX_60BA00E3FA06E4D9 (id_users), INDEX IDX_60BA00E369893C5E (id_platform), INDEX IDX_60BA00E3CEF1A445 (id_pages), UNIQUE INDEX uq_user_navigation_state_user_platform (id_users, id_platform), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('ALTER TABLE navigation_menu_item_exclusions ADD CONSTRAINT FK_8C8A2DD36A8744E7 FOREIGN KEY (id_navigation_menu_items) REFERENCES navigation_menu_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_item_exclusions ADD CONSTRAINT FK_8C8A2DD3CEF1A445 FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT FK_E701902C6A8744E7 FOREIGN KEY (id_navigation_menu_items) REFERENCES navigation_menu_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT FK_E701902C20E4EF5E FOREIGN KEY (id_languages) REFERENCES languages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E72FC3CDDB FOREIGN KEY (id_navigation_menus) REFERENCES navigation_menus (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E73AEADE5D FOREIGN KEY (id_parent_item) REFERENCES navigation_menu_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E7C4DBAFF5 FOREIGN KEY (id_item_type) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E7CEF1A445 FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT FK_BB30D3E7151ECE5D FOREIGN KEY (id_child_source) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT FK_CC49980A9D915BD8 FOREIGN KEY (id_navigation_menu_key) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT FK_CC49980A69893C5E FOREIGN KEY (id_platform) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT FK_CC49980A62DC371E FOREIGN KEY (id_surface) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT FK_CC49980AA6851DF FOREIGN KEY (id_preset) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D23C60493 FOREIGN KEY (id_web_header_search_mode) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D8DB25976 FOREIGN KEY (id_search_default_visibility) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D8B1FC201 FOREIGN KEY (id_search_field_policy) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024DEC3F2CAD FOREIGN KEY (id_web_guest_start_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D174EA010 FOREIGN KEY (id_web_user_start_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D948E519B FOREIGN KEY (id_web_user_start_mode) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024DB09D1FB2 FOREIGN KEY (id_mobile_guest_start_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D617BBAB FOREIGN KEY (id_mobile_user_start_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D85D74A20 FOREIGN KEY (id_mobile_user_start_mode) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D86EE5D6B FOREIGN KEY (id_mobile_start_page_source) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT FK_2C0F024D65234D8B FOREIGN KEY (id_route_sync_old_route_policy) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE user_navigation_state ADD CONSTRAINT FK_60BA00E3FA06E4D9 FOREIGN KEY (id_users) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_navigation_state ADD CONSTRAINT FK_60BA00E369893C5E FOREIGN KEY (id_platform) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE user_navigation_state ADD CONSTRAINT FK_60BA00E3CEF1A445 FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');

        $this->seedNavigationLookups();
        $this->seedSystemMenus();
        $this->seedNavigationSettings();
        $this->migrateNavPositionToMenuItems();
        $this->seedNavigationApiRoute();
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
            ['navigationMenuItemTypes', 'page', 'Page', 'Link to a CMS page'],
            ['navigationMenuItemTypes', 'external_url', 'External URL', 'External hyperlink'],
            ['navigationMenuItemTypes', 'group', 'Group', 'Non-clickable menu group'],
            ['navigationChildSources', 'manual', 'Manual', 'Only explicit child menu items'],
            ['navigationChildSources', 'page_children', 'Page children', 'Auto-include page tree children'],
            ['navigationChildSources', 'manual_plus_suggestions', 'Manual plus suggestions', 'Manual children with builder suggestions'],
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
        ];

        foreach ($rows as [$type, $code, $value, $desc]) {
            $this->addSql(
                'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES (?, ?, ?, ?)',
                [$type, $code, $value, $desc]
            );
        }
    }

    private function seedSystemMenus(): void
    {
        $menus = [
            ['web_header', 'web', 'header', 'dropdown', null, 5],
            ['web_footer', 'web', 'footer', null, null, null],
            ['mobile_drawer', 'mobile', 'drawer', null, null, null],
            ['mobile_bottom_tabs', 'mobile', 'bottom_tabs', null, 5, 5],
        ];

        foreach ($menus as [$key, $platform, $surface, $preset, $maxDepth, $itemLimit]) {
            $presetSql = $preset === null ? 'NULL' : "(SELECT id FROM lookups WHERE type_code = 'navigationMenuPresets' AND lookup_code = " . $this->connection->quote($preset) . " LIMIT 1)";
            $maxDepthSql = $maxDepth === null ? 'NULL' : (string) (int) $maxDepth;
            $itemLimitSql = $itemLimit === null ? 'NULL' : (string) (int) $itemLimit;

            $this->addSql(<<<SQL
                INSERT IGNORE INTO navigation_menus (
                    id_navigation_menu_key, id_platform, id_surface, id_preset, max_depth, item_limit, is_system, config
                )
                SELECT mk.id, pf.id, sf.id, {$presetSql}, {$maxDepthSql}, {$itemLimitSql}, 1, NULL
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
                id_route_sync_old_route_policy
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
                (SELECT id FROM lookups WHERE type_code = 'navigationRouteSyncPolicies' AND lookup_code = 'ask' LIMIT 1)
            SQL);
    }

    private function migrateNavPositionToMenuItems(): void
    {
        // Web header: root pages with nav_position
        $this->addSql(<<<'SQL'
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
                COALESCE(p.nav_position, 0) * 10,
                (SELECT id FROM lookups WHERE type_code = 'navigationChildSources' AND lookup_code = 'page_children' LIMIT 1),
                1,
                1
            FROM pages p
            JOIN navigation_menus nm ON nm.id_navigation_menu_key = (SELECT id FROM lookups WHERE type_code = 'navigationMenuKeys' AND lookup_code = 'web_header' LIMIT 1)
            WHERE p.nav_position IS NOT NULL
              AND p.is_headless = 0
              AND p.id_parent_page IS NULL
            SQL);

        // Web footer
        $this->addSql(<<<'SQL'
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
                COALESCE(p.footer_position, 0) * 10,
                (SELECT id FROM lookups WHERE type_code = 'navigationChildSources' AND lookup_code = 'manual' LIMIT 1),
                NULL,
                1
            FROM pages p
            JOIN navigation_menus nm ON nm.id_navigation_menu_key = (SELECT id FROM lookups WHERE type_code = 'navigationMenuKeys' AND lookup_code = 'web_footer' LIMIT 1)
            WHERE p.footer_position IS NOT NULL
              AND p.is_headless = 0
            SQL);

        // Mobile drawer: root nav pages
        $this->addSql(<<<'SQL'
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
                COALESCE(p.nav_position, 0) * 10,
                (SELECT id FROM lookups WHERE type_code = 'navigationChildSources' AND lookup_code = 'page_children' LIMIT 1),
                1,
                1
            FROM pages p
            JOIN navigation_menus nm ON nm.id_navigation_menu_key = (SELECT id FROM lookups WHERE type_code = 'navigationMenuKeys' AND lookup_code = 'mobile_drawer' LIMIT 1)
            WHERE p.nav_position IS NOT NULL
              AND p.is_headless = 0
              AND p.id_parent_page IS NULL
            SQL);

        // Mobile bottom tabs: first 5 root nav pages
        $this->addSql(<<<'SQL'
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
                COALESCE(p.nav_position, 0) * 10,
                (SELECT id FROM lookups WHERE type_code = 'navigationChildSources' AND lookup_code = 'manual' LIMIT 1),
                NULL,
                1
            FROM pages p
            JOIN navigation_menus nm ON nm.id_navigation_menu_key = (SELECT id FROM lookups WHERE type_code = 'navigationMenuKeys' AND lookup_code = 'mobile_bottom_tabs' LIMIT 1)
            WHERE p.nav_position IS NOT NULL
              AND p.is_headless = 0
              AND p.id_parent_page IS NULL
            ORDER BY p.nav_position ASC, p.id ASC
            LIMIT 5
            SQL);
    }

    private function seedNavigationApiRoute(): void
    {
        $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [self::ROUTE_NAVIGATION_GET, self::VERSION]);
        $this->addSql(
            'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
            [
                self::ROUTE_NAVIGATION_GET,
                self::VERSION,
                '/navigation',
                'App\\Controller\\Api\\V1\\Frontend\\NavigationController::getNavigation',
                'GET',
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [self::ROUTE_NAVIGATION_GET, self::VERSION]);
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE navigation_menu_item_exclusions DROP FOREIGN KEY FK_8C8A2DD36A8744E7');
        $this->addSql('ALTER TABLE navigation_menu_item_exclusions DROP FOREIGN KEY FK_8C8A2DD3CEF1A445');
        $this->addSql('ALTER TABLE navigation_menu_item_translations DROP FOREIGN KEY FK_E701902C6A8744E7');
        $this->addSql('ALTER TABLE navigation_menu_item_translations DROP FOREIGN KEY FK_E701902C20E4EF5E');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY FK_BB30D3E72FC3CDDB');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY FK_BB30D3E73AEADE5D');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY FK_BB30D3E7C4DBAFF5');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY FK_BB30D3E7CEF1A445');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY FK_BB30D3E7151ECE5D');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY FK_CC49980A9D915BD8');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY FK_CC49980A69893C5E');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY FK_CC49980A62DC371E');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY FK_CC49980AA6851DF');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024D23C60493');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024D8DB25976');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024D8B1FC201');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024DEC3F2CAD');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024D174EA010');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024D948E519B');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024DB09D1FB2');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024D617BBAB');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024D85D74A20');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024D86EE5D6B');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY FK_2C0F024D65234D8B');
        $this->addSql('ALTER TABLE user_navigation_state DROP FOREIGN KEY FK_60BA00E3FA06E4D9');
        $this->addSql('ALTER TABLE user_navigation_state DROP FOREIGN KEY FK_60BA00E369893C5E');
        $this->addSql('ALTER TABLE user_navigation_state DROP FOREIGN KEY FK_60BA00E3CEF1A445');
        $this->addSql('DROP TABLE navigation_menu_item_exclusions');
        $this->addSql('DROP TABLE navigation_menu_item_translations');
        $this->addSql('DROP TABLE navigation_menu_items');
        $this->addSql('DROP TABLE navigation_menus');
        $this->addSql('DROP TABLE navigation_settings');
        $this->addSql('DROP TABLE user_navigation_state');
    }
}
