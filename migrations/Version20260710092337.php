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
 * Final ORM schema for the branch's routing, navigation, analytics, and CMS-app
 * features. Catalog data, routes, permissions, and legacy-data conversion are
 * intentionally grouped in the following domain migrations.
 */
final class Version20260710092337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the final DB-routing, navigation, analytics, and CMS-app schema without intermediate WIP structures.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cms_apps (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id_form_section INT DEFAULT NULL, id_cms_list_page INT DEFAULT NULL, id_cms_detail_page INT DEFAULT NULL, id_public_list_page INT DEFAULT NULL, id_public_detail_page INT DEFAULT NULL, INDEX idx_cms_apps_id_form_section (id_form_section), INDEX idx_cms_apps_id_cms_list_page (id_cms_list_page), INDEX idx_cms_apps_id_cms_detail_page (id_cms_detail_page), INDEX idx_cms_apps_id_public_list_page (id_public_list_page), INDEX idx_cms_apps_id_public_detail_page (id_public_detail_page), UNIQUE INDEX uq_cms_apps_slug (slug), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE navigation_menus (id INT AUTO_INCREMENT NOT NULL, max_depth INT DEFAULT NULL, item_limit INT DEFAULT NULL, show_breadcrumbs TINYINT DEFAULT 1 NOT NULL, show_pager TINYINT DEFAULT 1 NOT NULL, is_system TINYINT DEFAULT 1 NOT NULL, id_navigation_menu_key INT NOT NULL, id_platform INT NOT NULL, id_surface INT NOT NULL, id_preset INT DEFAULT NULL, id_children_nav INT DEFAULT NULL, INDEX idx_navigation_menus_id_platform (id_platform), INDEX idx_navigation_menus_id_surface (id_surface), INDEX idx_navigation_menus_id_preset (id_preset), INDEX idx_navigation_menus_id_children_nav (id_children_nav), UNIQUE INDEX uq_navigation_menus_id_navigation_menu_key (id_navigation_menu_key), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE navigation_menu_items (id INT AUTO_INCREMENT NOT NULL, external_url VARCHAR(500) DEFAULT NULL, icon VARCHAR(100) DEFAULT NULL, mobile_icon VARCHAR(100) DEFAULT NULL, label VARCHAR(255) DEFAULT NULL, position INT NOT NULL, layer VARCHAR(16) DEFAULT NULL, show_pager TINYINT DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, id_navigation_menus INT NOT NULL, id_parent_item INT DEFAULT NULL, id_item_type INT NOT NULL, id_pages INT DEFAULT NULL, id_children_nav INT DEFAULT NULL, INDEX idx_navigation_menu_items_id_item_type (id_item_type), INDEX idx_navigation_menu_items_id_children_nav (id_children_nav), INDEX idx_navigation_menu_items_id_navigation_menus (id_navigation_menus), INDEX idx_navigation_menu_items_id_parent_item (id_parent_item), INDEX idx_navigation_menu_items_id_pages (id_pages), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE navigation_menu_item_translations (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) DEFAULT NULL, description VARCHAR(500) DEFAULT NULL, aria_label VARCHAR(255) DEFAULT NULL, id_navigation_menu_items INT NOT NULL, id_languages INT NOT NULL, INDEX idx_navigation_menu_item_translations_id_navigation_menu_items (id_navigation_menu_items), INDEX idx_navigation_menu_item_translations_id_languages (id_languages), UNIQUE INDEX uq_navigation_menu_item_translations_item_lang (id_navigation_menu_items, id_languages), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE navigation_settings (id INT NOT NULL, web_header_search_min_chars INT DEFAULT 2 NOT NULL, web_header_search_result_limit INT DEFAULT 8 NOT NULL, logo_asset_path VARCHAR(500) DEFAULT NULL, logo_alt VARCHAR(255) DEFAULT NULL, logo_size VARCHAR(8) DEFAULT \'md\' NOT NULL, logo_variant VARCHAR(16) DEFAULT \'logo-and-name\' NOT NULL, id_web_header_search_mode INT NOT NULL, id_search_default_visibility INT NOT NULL, id_search_field_policy INT NOT NULL, id_web_guest_start_page INT DEFAULT NULL, id_web_user_start_page INT DEFAULT NULL, id_web_user_start_mode INT NOT NULL, id_mobile_guest_start_page INT DEFAULT NULL, id_mobile_user_start_page INT DEFAULT NULL, id_mobile_user_start_mode INT NOT NULL, id_mobile_start_page_source INT NOT NULL, id_route_sync_old_route_policy INT NOT NULL, id_logo_link_page INT DEFAULT NULL, INDEX idx_navigation_settings_id_web_header_search_mode (id_web_header_search_mode), INDEX idx_navigation_settings_id_search_default_visibility (id_search_default_visibility), INDEX idx_navigation_settings_id_search_field_policy (id_search_field_policy), INDEX idx_navigation_settings_id_web_guest_start_page (id_web_guest_start_page), INDEX idx_navigation_settings_id_web_user_start_page (id_web_user_start_page), INDEX idx_navigation_settings_id_web_user_start_mode (id_web_user_start_mode), INDEX idx_navigation_settings_id_mobile_guest_start_page (id_mobile_guest_start_page), INDEX idx_navigation_settings_id_mobile_user_start_page (id_mobile_user_start_page), INDEX idx_navigation_settings_id_mobile_user_start_mode (id_mobile_user_start_mode), INDEX idx_navigation_settings_id_mobile_start_page_source (id_mobile_start_page_source), INDEX idx_navigation_settings_id_route_sync_old_route_policy (id_route_sync_old_route_policy), INDEX idx_navigation_settings_id_logo_link_page (id_logo_link_page), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE page_routes (id INT AUTO_INCREMENT NOT NULL, path_pattern VARCHAR(255) NOT NULL, requirements JSON DEFAULT NULL, is_canonical TINYINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, priority INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, id_pages INT NOT NULL, INDEX idx_page_routes_id_pages (id_pages), INDEX idx_page_routes_path_pattern (path_pattern), INDEX idx_page_routes_is_active (is_active), UNIQUE INDEX uq_page_routes_id_pages_path_pattern (id_pages, path_pattern), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE page_search_index (id INT AUTO_INCREMENT NOT NULL, title_text LONGTEXT DEFAULT NULL, description_text LONGTEXT DEFAULT NULL, body_text LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, id_pages INT NOT NULL, id_languages INT NOT NULL, INDEX idx_page_search_index_id_pages (id_pages), INDEX idx_page_search_index_id_languages (id_languages), UNIQUE INDEX uq_page_search_index_page_lang (id_pages, id_languages), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE page_view_referrers (id INT AUTO_INCREMENT NOT NULL, view_date DATE NOT NULL, referrer_host VARCHAR(190) NOT NULL, views INT DEFAULT 1 NOT NULL, INDEX idx_page_view_referrers_view_date (view_date), UNIQUE INDEX uq_page_view_referrers_day_host (view_date, referrer_host), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE page_views (id INT AUTO_INCREMENT NOT NULL, view_date DATE NOT NULL, platform VARCHAR(16) NOT NULL, visitor_hash VARCHAR(32) NOT NULL, views INT DEFAULT 1 NOT NULL, id_pages INT NOT NULL, INDEX idx_page_views_view_date (view_date), INDEX idx_page_views_id_pages (id_pages), UNIQUE INDEX uq_page_views_day_page_platform_visitor (view_date, id_pages, platform, visitor_hash), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->addSql('CREATE TABLE user_navigation_state (id INT AUTO_INCREMENT NOT NULL, url_snapshot VARCHAR(255) DEFAULT NULL, keyword_snapshot VARCHAR(100) DEFAULT NULL, updated_at DATETIME NOT NULL, id_users INT NOT NULL, id_platform INT NOT NULL, id_pages INT NOT NULL, INDEX idx_user_navigation_state_id_users (id_users), INDEX idx_user_navigation_state_id_platform (id_platform), INDEX idx_user_navigation_state_id_pages (id_pages), UNIQUE INDEX uq_user_navigation_state_user_platform (id_users, id_platform), PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT fk_cms_apps_id_form_section FOREIGN KEY (id_form_section) REFERENCES sections (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT fk_cms_apps_id_cms_list_page FOREIGN KEY (id_cms_list_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT fk_cms_apps_id_cms_detail_page FOREIGN KEY (id_cms_detail_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT fk_cms_apps_id_public_list_page FOREIGN KEY (id_public_list_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT fk_cms_apps_id_public_detail_page FOREIGN KEY (id_public_detail_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT fk_navigation_menu_items_id_navigation_menus FOREIGN KEY (id_navigation_menus) REFERENCES navigation_menus (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT fk_navigation_menu_items_id_parent_item FOREIGN KEY (id_parent_item) REFERENCES navigation_menu_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT fk_navigation_menu_items_id_item_type FOREIGN KEY (id_item_type) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT fk_navigation_menu_items_id_pages FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_items ADD CONSTRAINT fk_navigation_menu_items_id_children_nav FOREIGN KEY (id_children_nav) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT fk_navigation_menu_item_translations_id_navigation_menu_items FOREIGN KEY (id_navigation_menu_items) REFERENCES navigation_menu_items (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menu_item_translations ADD CONSTRAINT fk_navigation_menu_item_translations_id_languages FOREIGN KEY (id_languages) REFERENCES languages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT fk_navigation_menus_id_navigation_menu_key FOREIGN KEY (id_navigation_menu_key) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT fk_navigation_menus_id_platform FOREIGN KEY (id_platform) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT fk_navigation_menus_id_surface FOREIGN KEY (id_surface) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT fk_navigation_menus_id_preset FOREIGN KEY (id_preset) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_menus ADD CONSTRAINT fk_navigation_menus_id_children_nav FOREIGN KEY (id_children_nav) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_web_header_search_mode FOREIGN KEY (id_web_header_search_mode) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_search_default_visibility FOREIGN KEY (id_search_default_visibility) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_search_field_policy FOREIGN KEY (id_search_field_policy) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_web_guest_start_page FOREIGN KEY (id_web_guest_start_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_web_user_start_page FOREIGN KEY (id_web_user_start_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_web_user_start_mode FOREIGN KEY (id_web_user_start_mode) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_mobile_guest_start_page FOREIGN KEY (id_mobile_guest_start_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_mobile_user_start_page FOREIGN KEY (id_mobile_user_start_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_mobile_user_start_mode FOREIGN KEY (id_mobile_user_start_mode) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_mobile_start_page_source FOREIGN KEY (id_mobile_start_page_source) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_route_sync_old_route_policy FOREIGN KEY (id_route_sync_old_route_policy) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE navigation_settings ADD CONSTRAINT fk_navigation_settings_id_logo_link_page FOREIGN KEY (id_logo_link_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE page_routes ADD CONSTRAINT fk_page_routes_id_pages FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE page_search_index ADD CONSTRAINT fk_page_search_index_id_pages FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE page_search_index ADD CONSTRAINT fk_page_search_index_id_languages FOREIGN KEY (id_languages) REFERENCES languages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE page_views ADD CONSTRAINT fk_page_views_id_pages FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_navigation_state ADD CONSTRAINT fk_user_navigation_state_id_users FOREIGN KEY (id_users) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_navigation_state ADD CONSTRAINT fk_user_navigation_state_id_platform FOREIGN KEY (id_platform) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE user_navigation_state ADD CONSTRAINT fk_user_navigation_state_id_pages FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');

        // Keep the legacy menu-position columns until the navigation migration
        // has copied their data into the final menu tables.
        $this->addSql('ALTER TABLE pages ADD cms_app_role VARCHAR(32) DEFAULT NULL, ADD id_page_surface INT DEFAULT NULL, ADD id_cms_app INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pages ADD CONSTRAINT fk_pages_id_page_surface FOREIGN KEY (id_page_surface) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pages ADD CONSTRAINT fk_pages_id_cms_app FOREIGN KEY (id_cms_app) REFERENCES cms_apps (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_pages_id_page_surface ON pages (id_page_surface)');
        $this->addSql('CREATE INDEX idx_pages_id_cms_app ON pages (id_cms_app)');
    }

    public function down(Schema $schema): void
    {
        // Break the pages <-> cms_apps cycle before dropping either side.
        $this->addSql('ALTER TABLE pages DROP FOREIGN KEY fk_pages_id_page_surface');
        $this->addSql('ALTER TABLE pages DROP FOREIGN KEY fk_pages_id_cms_app');
        $this->addSql('DROP INDEX idx_pages_id_page_surface ON pages');
        $this->addSql('DROP INDEX idx_pages_id_cms_app ON pages');
        $this->addSql('ALTER TABLE pages DROP cms_app_role, DROP id_page_surface, DROP id_cms_app');

        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY fk_cms_apps_id_form_section');
        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY fk_cms_apps_id_cms_list_page');
        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY fk_cms_apps_id_cms_detail_page');
        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY fk_cms_apps_id_public_list_page');
        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY fk_cms_apps_id_public_detail_page');
        $this->addSql('ALTER TABLE navigation_menu_item_translations DROP FOREIGN KEY fk_navigation_menu_item_translations_id_navigation_menu_items');
        $this->addSql('ALTER TABLE navigation_menu_item_translations DROP FOREIGN KEY fk_navigation_menu_item_translations_id_languages');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY fk_navigation_menu_items_id_navigation_menus');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY fk_navigation_menu_items_id_parent_item');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY fk_navigation_menu_items_id_item_type');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY fk_navigation_menu_items_id_pages');
        $this->addSql('ALTER TABLE navigation_menu_items DROP FOREIGN KEY fk_navigation_menu_items_id_children_nav');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY fk_navigation_menus_id_navigation_menu_key');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY fk_navigation_menus_id_platform');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY fk_navigation_menus_id_surface');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY fk_navigation_menus_id_preset');
        $this->addSql('ALTER TABLE navigation_menus DROP FOREIGN KEY fk_navigation_menus_id_children_nav');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_web_header_search_mode');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_search_default_visibility');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_search_field_policy');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_web_guest_start_page');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_web_user_start_page');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_web_user_start_mode');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_mobile_guest_start_page');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_mobile_user_start_page');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_mobile_user_start_mode');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_mobile_start_page_source');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_route_sync_old_route_policy');
        $this->addSql('ALTER TABLE navigation_settings DROP FOREIGN KEY fk_navigation_settings_id_logo_link_page');
        $this->addSql('ALTER TABLE page_routes DROP FOREIGN KEY fk_page_routes_id_pages');
        $this->addSql('ALTER TABLE page_search_index DROP FOREIGN KEY fk_page_search_index_id_pages');
        $this->addSql('ALTER TABLE page_search_index DROP FOREIGN KEY fk_page_search_index_id_languages');
        $this->addSql('ALTER TABLE page_views DROP FOREIGN KEY fk_page_views_id_pages');
        $this->addSql('ALTER TABLE user_navigation_state DROP FOREIGN KEY fk_user_navigation_state_id_users');
        $this->addSql('ALTER TABLE user_navigation_state DROP FOREIGN KEY fk_user_navigation_state_id_platform');
        $this->addSql('ALTER TABLE user_navigation_state DROP FOREIGN KEY fk_user_navigation_state_id_pages');

        $this->addSql('DROP TABLE navigation_menu_item_translations');
        $this->addSql('DROP TABLE navigation_menu_items');
        $this->addSql('DROP TABLE navigation_menus');
        $this->addSql('DROP TABLE navigation_settings');
        $this->addSql('DROP TABLE page_routes');
        $this->addSql('DROP TABLE page_search_index');
        $this->addSql('DROP TABLE page_view_referrers');
        $this->addSql('DROP TABLE page_views');
        $this->addSql('DROP TABLE user_navigation_state');
        $this->addSql('DROP TABLE cms_apps');
    }
}
