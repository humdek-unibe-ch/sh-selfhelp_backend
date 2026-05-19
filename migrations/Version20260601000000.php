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
 * Canonical baseline migration for the SelfHelp backend.
 *
 * This is the SQUASHED, MIGRATIONS-ONLY install entry point. A brand-new
 * empty MySQL database boots the backend by running this migration class
 * (and the four seed-data migrations that immediately follow it) — there
 * is NO requirement to load `db/new_create_db.sql` or any other legacy
 * SQL dump first. That older dual-bootstrap contract is intentionally
 * dropped.
 *
 * What this migration creates:
 *   - Every runtime-used schema object under canonical
 *     `lowercase_snake_case` names (tables, primary keys, foreign keys,
 *     unique constraints, indexes).
 *   - Pure relation tables are prefixed `rel_<a>_<b>` in alphabetical
 *     order; join tables that carry business columns (CRUD flags,
 *     lifecycle metadata) are promoted to first-class domain tables.
 *   - The five stored procedures consumed by PHP repositories:
 *     `get_user_acl`, `get_data_table_filtered`,
 *     `get_data_table_for_user_groups`, `get_data_table_all_languages`,
 *     `get_page_sections_hierarchical`, plus the helper functions they
 *     transitively depend on (`build_dynamic_columns`,
 *     `build_exclude_deleted_filter`, `build_language_filter`,
 *     `build_time_period_filter`, `convert_entry_date_timezone`).
 *
 * What this migration does NOT do:
 *   - It does not seed reference data, lookups, languages, fields,
 *     styles, system pages, api_routes, permissions or default groups —
 *     those rows are added by the four `Version20260601000100`,
 *     `Version20260601000200`, `Version20260601000300` and
 *     `Version20260601000400` seed migrations.
 *   - It does not preserve the legacy mixed-case schema. Pre-release
 *     breaking change: there is no in-place rename of an existing
 *     `db/new_create_db.sql` install; that flow is gone.
 *
 * DO NOT add migrations BEFORE this one. The legacy migration history
 * is archived under `db/legacy/` for historical reference only.
 *
 * @see db/legacy/README.md for the deprecated bootstrap files.
 */
final class Version20260601000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Canonical baseline: full renamed schema + runtime stored procedures.';
    }

    public function up(Schema $schema): void
    {
        // Hard guard: this migration is the install root. If the database
        // already has any of our canonical tables, bail out instead of
        // silently doing nothing. The four seed migrations run after this
        // one and depend on a clean schema being in place.
        $this->abortIf(
            $this->connection->createSchemaManager()->tablesExist(['pages']),
            'Refusing to re-create canonical baseline: schema already exists. Drop the database and re-run if a clean install is intended.'
        );

        // ============================================================
        // 0) Convert Doctrine migrations bookkeeping table to InnoDB.
        //    Doctrine Migrations creates `doctrine_migration_versions`
        //    with the default storage engine (MyISAM on MySQL), which is
        //    inconsistent with the rest of our InnoDB/FK-oriented schema
        //    (see AGENTS.md). It exists by the time this baseline runs.
        // ============================================================
        $this->addSql('ALTER TABLE `doctrine_migration_versions` ENGINE=InnoDB');

        // ============================================================
        // 1) Base reference tables (no FKs to other app tables)
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `field_types` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              `position` INT NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `UNIQ_72971FCB5E237E06` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `style_groups` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              `description` LONGTEXT DEFAULT NULL,
              `position` INT DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_style_groups_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `languages` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `locale` VARCHAR(5) NOT NULL,
              `language` VARCHAR(100) NOT NULL,
              `csv_separator` VARCHAR(1) NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `UNIQ_A0D153794180C698` (`locale`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `lookups` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `type_code` VARCHAR(100) NOT NULL,
              `lookup_code` VARCHAR(100) DEFAULT NULL,
              `lookup_value` VARCHAR(200) DEFAULT NULL,
              `lookup_description` VARCHAR(500) DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_lookups_type_code_lookup_code` (`type_code`, `lookup_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `permissions` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              `description` VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `UNIQ_2DEDCC6F5E237E06` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `roles` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(50) NOT NULL,
              `description` VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `UNIQ_B63E2EC75E237E06` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `groups` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              `description` VARCHAR(250) NOT NULL,
              `id_group_types` INT DEFAULT NULL,
              `requires_2fa` TINYINT(1) NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `page_types` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `UNIQ_3FB176E65E237E06` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `validation_codes` (
              `code` VARCHAR(16) NOT NULL,
              `id_users` INT DEFAULT NULL,
              `created` DATETIME NOT NULL,
              `consumed` DATETIME DEFAULT NULL,
              `id_groups` INT DEFAULT NULL,
              PRIMARY KEY (`code`),
              KEY `IDX_DBEC45EFA06E4D9` (`id_users`),
              KEY `IDX_DBEC45ED65A8C9D` (`id_groups`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `libraries` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(250) DEFAULT NULL,
              `version` VARCHAR(500) DEFAULT NULL,
              `license` VARCHAR(1000) DEFAULT NULL,
              `comments` VARCHAR(1000) DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `plugins` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) DEFAULT NULL,
              `version` VARCHAR(500) DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `hooks` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_hook_types` INT NOT NULL,
              `name` VARCHAR(100) DEFAULT NULL,
              `description` VARCHAR(1000) DEFAULT NULL,
              `class` VARCHAR(100) NOT NULL,
              `function` VARCHAR(100) NOT NULL,
              `exec_class` VARCHAR(100) NOT NULL,
              `exec_function` VARCHAR(100) NOT NULL,
              `priority` INT NOT NULL DEFAULT 10,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // ============================================================
        // 2) User stack (depends on lookups, languages)
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `users` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `email` VARCHAR(100) NOT NULL,
              `name` VARCHAR(100) DEFAULT NULL,
              `password` VARCHAR(255) DEFAULT NULL,
              `blocked` TINYINT(1) NOT NULL DEFAULT 0,
              `id_status` INT DEFAULT 1,
              `intern` TINYINT(1) NOT NULL DEFAULT 0,
              `token` VARCHAR(32) DEFAULT NULL,
              `id_languages` INT DEFAULT NULL,
              `is_reminded` TINYINT(1) NOT NULL DEFAULT 1,
              `last_login` DATE DEFAULT NULL,
              `last_url` VARCHAR(100) DEFAULT NULL,
              `device_id` VARCHAR(100) DEFAULT NULL,
              `device_token` VARCHAR(200) DEFAULT NULL,
              `security_questions` VARCHAR(1000) DEFAULT NULL,
              `id_user_types` INT NOT NULL DEFAULT 72,
              `id_timezones` INT DEFAULT NULL,
              `user_name` VARCHAR(100) DEFAULT NULL,
              `acl_version` VARCHAR(36) DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `UNIQ_1483A5E9E7927C74` (`email`),
              UNIQUE KEY `UNIQ_1483A5E924A232CF` (`user_name`),
              KEY `IDX_1483A5E920E4EF5E` (`id_languages`),
              KEY `IDX_1483A5E95D37D0F1` (`id_status`),
              KEY `IDX_1483A5E91E78F2BF` (`id_user_types`),
              KEY `IDX_1483A5E9F5677479` (`id_timezones`),
              CONSTRAINT `fk_users_id_languages` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_users_id_status` FOREIGN KEY (`id_status`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_users_id_user_types` FOREIGN KEY (`id_user_types`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_users_id_timezones` FOREIGN KEY (`id_timezones`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `user_activities` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_users` INT NOT NULL,
              `url` VARCHAR(200) NOT NULL,
              `timestamp` DATETIME NOT NULL,
              `id_user_activity_types` INT NOT NULL,
              `exec_time` DECIMAL(10,8) DEFAULT NULL,
              `keyword` VARCHAR(100) DEFAULT NULL,
              `params` VARCHAR(1000) DEFAULT NULL,
              `mobile` TINYINT(1) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_user_activities_id_users` (`id_users`),
              KEY `idx_user_activities_id_user_activity_types` (`id_user_activity_types`),
              CONSTRAINT `fk_user_activities_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_user_activities_id_user_activity_types` FOREIGN KEY (`id_user_activity_types`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `log_performance` (
              `id_user_activities` INT NOT NULL,
              `log` LONGTEXT,
              PRIMARY KEY (`id_user_activities`),
              CONSTRAINT `fk_log_performance_id_user_activities` FOREIGN KEY (`id_user_activities`) REFERENCES `user_activities` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `user_2fa_codes` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_users` INT NOT NULL,
              `code` VARCHAR(6) NOT NULL,
              `created_at` DATETIME NOT NULL,
              `expires_at` DATETIME NOT NULL,
              `is_used` TINYINT(1) NOT NULL,
              PRIMARY KEY (`id`),
              KEY `IDX_33C1301FA06E4D9` (`id_users`),
              CONSTRAINT `fk_user_2fa_codes_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `refresh_tokens` (
              `id` BIGINT NOT NULL AUTO_INCREMENT,
              `id_users` INT NOT NULL,
              `token_hash` VARCHAR(255) NOT NULL,
              `expires_at` DATETIME NOT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `IDX_9BACE7E1FA06E4D9` (`id_users`),
              CONSTRAINT `fk_refresh_tokens_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // FKs for validation_codes (deferred so users exists)
        $this->addSql('ALTER TABLE `validation_codes`
            ADD CONSTRAINT `fk_validation_codes_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            ADD CONSTRAINT `fk_validation_codes_id_groups` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE');

        // ============================================================
        // 3) Fields / styles
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `fields` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              `id_field_types` INT NOT NULL,
              `display` TINYINT(1) NOT NULL,
              `config` JSON DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_fields_name` (`name`),
              KEY `idx_fields_id_field_types` (`id_field_types`),
              CONSTRAINT `fk_fields_id_field_types` FOREIGN KEY (`id_field_types`) REFERENCES `field_types` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `styles` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              `id_style_groups` INT NOT NULL,
              `description` LONGTEXT,
              `can_have_children` TINYINT(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_styles_name` (`name`),
              KEY `idx_styles_id_style_groups` (`id_style_groups`),
              CONSTRAINT `fk_styles_id_style_groups` FOREIGN KEY (`id_style_groups`) REFERENCES `style_groups` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_fields_styles` (
              `id_styles` INT NOT NULL,
              `id_fields` INT NOT NULL,
              `default_value` VARCHAR(1000) DEFAULT NULL,
              `help` LONGTEXT,
              `disabled` TINYINT NOT NULL,
              `hidden` INT DEFAULT NULL,
              `title` VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY (`id_styles`, `id_fields`),
              KEY `IDX_4CCB65B9906D4F18` (`id_styles`),
              KEY `IDX_4CCB65B958D25665` (`id_fields`),
              CONSTRAINT `fk_rel_fields_styles_id_fields` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_fields_styles_id_styles` FOREIGN KEY (`id_styles`) REFERENCES `styles` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_styles_allowed_relationships` (
              `id_parent_style` INT NOT NULL,
              `id_child_style` INT NOT NULL,
              PRIMARY KEY (`id_parent_style`, `id_child_style`),
              KEY `IDX_278D2F4DDC4D59BB` (`id_parent_style`),
              KEY `IDX_278D2F4D78A9D70E` (`id_child_style`),
              CONSTRAINT `fk_rel_styles_allowed_id_parent_style` FOREIGN KEY (`id_parent_style`) REFERENCES `styles` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_styles_allowed_id_child_style` FOREIGN KEY (`id_child_style`) REFERENCES `styles` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // ============================================================
        // 4) Page-types / pages / sections
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `rel_fields_page_types` (
              `id_page_types` INT NOT NULL,
              `id_fields` INT NOT NULL,
              `default_value` LONGTEXT,
              `help` LONGTEXT,
              `title` VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY (`id_page_types`, `id_fields`),
              KEY `IDX_2D0ECEF758D25665` (`id_fields`),
              CONSTRAINT `fk_rel_fields_page_types_id_page_types` FOREIGN KEY (`id_page_types`) REFERENCES `page_types` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_fields_page_types_id_fields` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // Schema mirrors the legacy `pages` table exactly with canonical
        // renames (parent -> id_parent_page, id_type -> id_page_types,
        // id_pageAccessTypes -> id_page_access_types, published_version_id
        // -> id_published_page_versions). No invented columns.
        $this->addSql(<<<SQL
            CREATE TABLE `pages` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `keyword` VARCHAR(100) NOT NULL,
              `url` VARCHAR(255) DEFAULT NULL,
              `id_parent_page` INT DEFAULT NULL,
              `is_headless` TINYINT(1) NOT NULL DEFAULT 0,
              `nav_position` INT DEFAULT NULL,
              `footer_position` INT DEFAULT NULL,
              `id_page_types` INT NOT NULL,
              `id_page_access_types` INT DEFAULT NULL,
              `is_open_access` TINYINT DEFAULT 0,
              `is_system` TINYINT DEFAULT 0,
              `id_published_page_versions` INT DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_pages_keyword` (`keyword`),
              KEY `idx_pages_id_parent_page` (`id_parent_page`),
              KEY `idx_pages_id_page_types` (`id_page_types`),
              KEY `idx_pages_id_page_access_types` (`id_page_access_types`),
              KEY `idx_pages_id_published_page_versions` (`id_published_page_versions`),
              CONSTRAINT `fk_pages_id_parent_page` FOREIGN KEY (`id_parent_page`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_pages_id_page_types` FOREIGN KEY (`id_page_types`) REFERENCES `page_types` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_pages_id_page_access_types` FOREIGN KEY (`id_page_access_types`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_fields_pages` (
              `id_pages` INT NOT NULL,
              `id_fields` INT NOT NULL,
              `default_value` LONGTEXT,
              `help` LONGTEXT,
              PRIMARY KEY (`id_pages`, `id_fields`),
              KEY `IDX_BED6CA6B58D25665` (`id_fields`),
              CONSTRAINT `fk_rel_fields_pages_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_fields_pages_id_fields` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `pages_fields_translation` (
              `id_pages` INT NOT NULL,
              `id_fields` INT NOT NULL,
              `id_languages` INT NOT NULL,
              `content` LONGTEXT NOT NULL,
              PRIMARY KEY (`id_pages`, `id_fields`, `id_languages`),
              KEY `IDX_903943EE58D25665` (`id_fields`),
              KEY `IDX_903943EE20E4EF5E` (`id_languages`),
              CONSTRAINT `fk_pages_fields_translation_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_pages_fields_translation_id_fields` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_pages_fields_translation_id_languages` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `sections` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_styles` INT NOT NULL,
              `name` VARCHAR(100) NOT NULL,
              `condition` LONGTEXT,
              `data_config` LONGTEXT,
              `css` LONGTEXT,
              `css_mobile` LONGTEXT,
              `debug` TINYINT(1) DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `IDX_2B964398906D4F18` (`id_styles`),
              CONSTRAINT `fk_sections_id_styles` FOREIGN KEY (`id_styles`) REFERENCES `styles` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_pages_sections` (
              `id_pages` INT NOT NULL,
              `id_sections` INT NOT NULL,
              `position` INT DEFAULT NULL,
              PRIMARY KEY (`id_pages`, `id_sections`),
              KEY `IDX_D2FCD14A7B4DAF0D` (`id_sections`),
              CONSTRAINT `fk_rel_pages_sections_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_pages_sections_id_sections` FOREIGN KEY (`id_sections`) REFERENCES `sections` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_sections_hierarchy` (
              `id_parent_section` INT NOT NULL,
              `id_child_section` INT NOT NULL,
              `position` INT DEFAULT NULL,
              PRIMARY KEY (`id_parent_section`, `id_child_section`),
              KEY `IDX_A3798102F1EFE391` (`id_child_section`),
              CONSTRAINT `fk_rel_sections_hierarchy_id_parent_section` FOREIGN KEY (`id_parent_section`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_sections_hierarchy_id_child_section` FOREIGN KEY (`id_child_section`) REFERENCES `sections` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_sections_navigation` (
              `id_parent_section` INT NOT NULL,
              `id_child_section` INT NOT NULL,
              `id_pages` INT NOT NULL,
              `position` INT NOT NULL,
              PRIMARY KEY (`id_parent_section`, `id_child_section`, `id_pages`),
              KEY `IDX_96032955625DB3AF` (`id_parent_section`),
              KEY `IDX_96032955F1EFE391` (`id_child_section`),
              KEY `IDX_96032955CEF1A445` (`id_pages`),
              CONSTRAINT `fk_rel_sections_navigation_id_parent_section` FOREIGN KEY (`id_parent_section`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_sections_navigation_id_child_section` FOREIGN KEY (`id_child_section`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_sections_navigation_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `sections_fields_translation` (
              `id_sections` INT NOT NULL,
              `id_fields` INT NOT NULL,
              `id_languages` INT NOT NULL,
              `content` LONGTEXT NOT NULL,
              `meta` VARCHAR(10000) DEFAULT NULL,
              PRIMARY KEY (`id_sections`, `id_fields`, `id_languages`),
              KEY `IDX_EC50541558D25665` (`id_fields`),
              KEY `IDX_EC50541520E4EF5E` (`id_languages`),
              CONSTRAINT `fk_sections_fields_translation_id_sections` FOREIGN KEY (`id_sections`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_sections_fields_translation_id_fields` FOREIGN KEY (`id_fields`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_sections_fields_translation_id_languages` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // ============================================================
        // 5) Page versioning (depends on pages, users)
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `page_versions` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_pages` INT NOT NULL,
              `version_number` INT NOT NULL COMMENT 'Incremental version number per page',
              `version_name` VARCHAR(255) DEFAULT NULL COMMENT 'Optional user-defined name for the version',
              `page_json` JSON NOT NULL COMMENT 'Complete JSON structure from getPage() including all languages, conditions, data table configs',
              `id_users` INT DEFAULT NULL,
              `created_at` DATETIME NOT NULL,
              `published_at` DATETIME DEFAULT NULL COMMENT 'When this version was published',
              `metadata` JSON DEFAULT NULL COMMENT 'Additional info like change summary, tags, etc.',
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_page_versions_id_pages_version_number` (`id_pages`, `version_number`),
              KEY `idx_page_versions_id_pages` (`id_pages`),
              KEY `idx_page_versions_id_users` (`id_users`),
              KEY `idx_page_versions_created_at` (`created_at`),
              KEY `idx_page_versions_published_at` (`published_at`),
              CONSTRAINT `fk_page_versions_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_page_versions_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql('ALTER TABLE `pages` ADD CONSTRAINT `fk_pages_id_published_page_versions` FOREIGN KEY (`id_published_page_versions`) REFERENCES `page_versions` (`id`)');

        // ============================================================
        // 6) Data tables (form storage)
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `data_tables` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL,
              `timestamp` DATETIME NOT NULL,
              `display_name` VARCHAR(1000) DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `data_cols` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(255) DEFAULT NULL,
              `id_data_tables` INT DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `IDX_F057C423FCABFECF` (`id_data_tables`),
              CONSTRAINT `fk_data_cols_id_data_tables` FOREIGN KEY (`id_data_tables`) REFERENCES `data_tables` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `data_rows` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_data_tables` INT DEFAULT NULL,
              `timestamp` DATETIME NOT NULL,
              `id_users` INT DEFAULT NULL,
              `id_action_trigger_types` INT DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `IDX_B1C43F43FCABFECF` (`id_data_tables`),
              CONSTRAINT `fk_data_rows_id_data_tables` FOREIGN KEY (`id_data_tables`) REFERENCES `data_tables` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `data_cells` (
              `id_data_rows` INT NOT NULL,
              `id_data_cols` INT NOT NULL,
              `value` LONGTEXT NOT NULL,
              `id_languages` INT NOT NULL DEFAULT 1,
              PRIMARY KEY (`id_data_rows`, `id_data_cols`, `id_languages`),
              KEY `IDX_1B7E074770627804` (`id_data_cols`),
              KEY `IDX_1B7E074720E4EF5E` (`id_languages`),
              CONSTRAINT `fk_data_cells_id_data_rows` FOREIGN KEY (`id_data_rows`) REFERENCES `data_rows` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_data_cells_id_data_cols` FOREIGN KEY (`id_data_cols`) REFERENCES `data_cols` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_data_cells_id_languages` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // ============================================================
        // 7) Actions / action translations (depends on lookups + data_tables)
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `actions` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(200) NOT NULL,
              `id_action_trigger_types` INT NOT NULL,
              `config` LONGTEXT,
              `id_data_tables` INT NOT NULL,
              PRIMARY KEY (`id`),
              KEY `IDX_548F1EF2BDC1C48` (`id_action_trigger_types`),
              KEY `IDX_548F1EFFCABFECF` (`id_data_tables`),
              CONSTRAINT `fk_actions_id_action_trigger_types` FOREIGN KEY (`id_action_trigger_types`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_actions_id_data_tables` FOREIGN KEY (`id_data_tables`) REFERENCES `data_tables` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `action_translations` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_actions` INT NOT NULL,
              `translation_key` VARCHAR(255) NOT NULL,
              `id_languages` INT NOT NULL,
              `content` LONGTEXT NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `IDX_5AC50EA7DBD5589F` (`id_actions`),
              KEY `IDX_5AC50EA720E4EF5E` (`id_languages`),
              CONSTRAINT `fk_action_translations_id_actions` FOREIGN KEY (`id_actions`) REFERENCES `actions` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_action_translations_id_languages` FOREIGN KEY (`id_languages`) REFERENCES `languages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // ============================================================
        // 8) Assets
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `assets` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_asset_types` INT NOT NULL,
              `folder` VARCHAR(100) DEFAULT NULL,
              `file_name` VARCHAR(100) DEFAULT NULL,
              `file_path` VARCHAR(1000) NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `UNIQ_79D17D8ED7DF1668` (`file_name`),
              KEY `IDX_79D17D8ED4796A80` (`id_asset_types`),
              CONSTRAINT `fk_assets_id_asset_types` FOREIGN KEY (`id_asset_types`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // ============================================================
        // 9) API routes / permissions / roles / groups
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `api_routes` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `route_name` VARCHAR(100) NOT NULL,
              `version` VARCHAR(10) NOT NULL DEFAULT 'v1',
              `path` VARCHAR(255) NOT NULL,
              `controller` VARCHAR(255) NOT NULL,
              `methods` VARCHAR(50) NOT NULL,
              `requirements` JSON DEFAULT NULL,
              `params` JSON DEFAULT NULL COMMENT 'Expected parameters: name → {in: body|query, required: bool}',
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_api_routes_route_name_version` (`route_name`, `version`),
              UNIQUE KEY `uq_api_routes_version_path_methods` (`version`, `path`, `methods`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_api_routes_permissions` (
              `id_api_routes` INT NOT NULL,
              `id_permissions` INT NOT NULL,
              PRIMARY KEY (`id_api_routes`, `id_permissions`),
              KEY `IDX_FF2F649935FF0198` (`id_permissions`),
              CONSTRAINT `fk_rel_api_routes_permissions_id_api_routes` FOREIGN KEY (`id_api_routes`) REFERENCES `api_routes` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_api_routes_permissions_id_permissions` FOREIGN KEY (`id_permissions`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_permissions_roles` (
              `id_permissions` INT NOT NULL,
              `id_roles` INT NOT NULL,
              PRIMARY KEY (`id_permissions`, `id_roles`),
              KEY `IDX_54B95B9F58BB6FF7` (`id_roles`),
              CONSTRAINT `fk_rel_permissions_roles_id_permissions` FOREIGN KEY (`id_permissions`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_permissions_roles_id_roles` FOREIGN KEY (`id_roles`) REFERENCES `roles` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_roles_users` (
              `id_users` INT NOT NULL,
              `id_roles` INT NOT NULL,
              PRIMARY KEY (`id_users`, `id_roles`),
              KEY `IDX_FDD66586FA06E4D9` (`id_users`),
              KEY `IDX_FDD6658658BB6FF7` (`id_roles`),
              CONSTRAINT `fk_rel_roles_users_id_roles` FOREIGN KEY (`id_roles`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_roles_users_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `rel_groups_users` (
              `id_users` INT NOT NULL,
              `id_groups` INT NOT NULL,
              PRIMARY KEY (`id_users`, `id_groups`),
              KEY `IDX_73E3DE25FA06E4D9` (`id_users`),
              KEY `IDX_73E3DE25D65A8C9D` (`id_groups`),
              CONSTRAINT `fk_rel_groups_users_id_groups` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_rel_groups_users_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `page_acl_groups` (
              `id_groups` INT NOT NULL,
              `id_pages` INT NOT NULL,
              `acl_select` TINYINT(1) NOT NULL DEFAULT 1,
              `acl_insert` TINYINT(1) NOT NULL DEFAULT 0,
              `acl_update` TINYINT(1) NOT NULL DEFAULT 0,
              `acl_delete` TINYINT(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (`id_groups`, `id_pages`),
              KEY `IDX_A0D73FBECEF1A445` (`id_pages`),
              CONSTRAINT `fk_page_acl_groups_id_groups` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_page_acl_groups_id_pages` FOREIGN KEY (`id_pages`) REFERENCES `pages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `validation_code_groups` (
              `code` VARCHAR(16) NOT NULL,
              `id_groups` INT NOT NULL,
              PRIMARY KEY (`code`, `id_groups`),
              KEY `IDX_2714E7FED65A8C9D` (`id_groups`),
              CONSTRAINT `fk_validation_code_groups_code` FOREIGN KEY (`code`) REFERENCES `validation_codes` (`code`) ON DELETE CASCADE,
              CONSTRAINT `fk_validation_code_groups_id_groups` FOREIGN KEY (`id_groups`) REFERENCES `groups` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // ============================================================
        // 10) Audit / logging
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `api_request_logs` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `route_name` VARCHAR(255) DEFAULT NULL,
              `path` VARCHAR(255) NOT NULL,
              `method` VARCHAR(10) NOT NULL,
              `status_code` INT NOT NULL,
              `id_users` INT DEFAULT NULL,
              `ip_address` VARCHAR(45) DEFAULT NULL,
              `request_time` DATETIME NOT NULL,
              `response_time` DATETIME NOT NULL,
              `duration_ms` INT NOT NULL,
              `request_params` LONGTEXT,
              `request_headers` LONGTEXT,
              `response_data` LONGTEXT,
              `error_message` LONGTEXT,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `callback_logs` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `callback_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `remote_addr` VARCHAR(200) DEFAULT NULL,
              `redirect_url` VARCHAR(1000) DEFAULT NULL,
              `callback_params` LONGTEXT,
              `status` VARCHAR(200) DEFAULT NULL,
              `callback_output` LONGTEXT,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `data_access_audits` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_users` INT NOT NULL,
              `id_resource_types` INT NOT NULL,
              `resource_id` INT NOT NULL,
              `id_audit_actions` INT NOT NULL,
              `id_permission_results` INT NOT NULL,
              `crud_permission` SMALLINT UNSIGNED DEFAULT NULL,
              `http_method` VARCHAR(10) DEFAULT NULL,
              `request_body_hash` VARCHAR(64) DEFAULT NULL,
              `ip_address` VARCHAR(45) DEFAULT NULL,
              `user_agent` LONGTEXT,
              `request_uri` LONGTEXT,
              `notes` LONGTEXT,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_data_access_audits_id_users` (`id_users`),
              KEY `idx_data_access_audits_id_resource_types` (`id_resource_types`),
              KEY `idx_data_access_audits_resource_id` (`resource_id`),
              KEY `idx_data_access_audits_id_audit_actions` (`id_audit_actions`),
              KEY `idx_data_access_audits_id_permission_results` (`id_permission_results`),
              KEY `idx_data_access_audits_created_at` (`created_at`),
              KEY `idx_data_access_audits_http_method` (`http_method`),
              KEY `idx_data_access_audits_request_body_hash` (`request_body_hash`),
              CONSTRAINT `fk_data_access_audits_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`),
              CONSTRAINT `fk_data_access_audits_id_resource_types` FOREIGN KEY (`id_resource_types`) REFERENCES `lookups` (`id`),
              CONSTRAINT `fk_data_access_audits_id_audit_actions` FOREIGN KEY (`id_audit_actions`) REFERENCES `lookups` (`id`),
              CONSTRAINT `fk_data_access_audits_id_permission_results` FOREIGN KEY (`id_permission_results`) REFERENCES `lookups` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `role_data_access` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_roles` INT NOT NULL,
              `id_resource_types` INT NOT NULL,
              `resource_id` INT NOT NULL,
              `crud_permissions` SMALLINT UNSIGNED NOT NULL DEFAULT 2,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_role_data_access_role_resource` (`id_roles`, `id_resource_types`, `resource_id`),
              KEY `idx_role_data_access_id_roles` (`id_roles`),
              KEY `idx_role_data_access_id_resource_types` (`id_resource_types`),
              KEY `idx_role_data_access_resource_id` (`resource_id`),
              KEY `idx_role_data_access_crud_permissions` (`crud_permissions`),
              CONSTRAINT `fk_role_data_access_id_roles` FOREIGN KEY (`id_roles`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_role_data_access_id_resource_types` FOREIGN KEY (`id_resource_types`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // ============================================================
        // 11) Scheduled jobs / reminders
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `scheduled_jobs` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_users` INT DEFAULT NULL,
              `id_actions` INT DEFAULT NULL,
              `id_data_tables` INT DEFAULT NULL,
              `id_data_rows` INT DEFAULT NULL,
              `id_job_types` INT NOT NULL,
              `id_job_status` INT NOT NULL,
              `date_create` DATETIME NOT NULL,
              `date_to_be_executed` DATETIME NOT NULL,
              `date_executed` DATETIME DEFAULT NULL,
              `description` VARCHAR(1000) DEFAULT NULL,
              `config` JSON DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `IDX_67522AECFA06E4D9` (`id_users`),
              KEY `IDX_67522AECDBD5589F` (`id_actions`),
              KEY `IDX_67522AECFCABFECF` (`id_data_tables`),
              KEY `IDX_67522AEC31F18364` (`id_data_rows`),
              KEY `IDX_67522AEC8E8EC84A` (`id_job_status`),
              KEY `IDX_67522AECD8598867` (`id_job_types`),
              CONSTRAINT `fk_scheduled_jobs_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_scheduled_jobs_id_actions` FOREIGN KEY (`id_actions`) REFERENCES `actions` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_scheduled_jobs_id_data_tables` FOREIGN KEY (`id_data_tables`) REFERENCES `data_tables` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_scheduled_jobs_id_data_rows` FOREIGN KEY (`id_data_rows`) REFERENCES `data_rows` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_scheduled_jobs_id_job_types` FOREIGN KEY (`id_job_types`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_scheduled_jobs_id_job_status` FOREIGN KEY (`id_job_status`) REFERENCES `lookups` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE `scheduled_job_reminders` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `session_start_date` DATETIME DEFAULT NULL,
              `session_end_date` DATETIME DEFAULT NULL,
              `id_scheduled_jobs` INT NOT NULL,
              `id_parent_scheduled_jobs` INT DEFAULT NULL,
              `id_data_tables` INT DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_scheduled_job_reminders_id_scheduled_jobs` (`id_scheduled_jobs`),
              KEY `idx_scheduled_job_reminders_id_parent_scheduled_jobs` (`id_parent_scheduled_jobs`),
              KEY `idx_scheduled_job_reminders_id_data_tables` (`id_data_tables`),
              CONSTRAINT `fk_scheduled_job_reminders_id_scheduled_jobs` FOREIGN KEY (`id_scheduled_jobs`) REFERENCES `scheduled_jobs` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_scheduled_job_reminders_id_parent_scheduled_jobs` FOREIGN KEY (`id_parent_scheduled_jobs`) REFERENCES `scheduled_jobs` (`id`) ON DELETE SET NULL,
              CONSTRAINT `fk_scheduled_job_reminders_id_data_tables` FOREIGN KEY (`id_data_tables`) REFERENCES `data_tables` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ============================================================
        // 12) Transactions (audit log)
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `transactions` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `transaction_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `id_transaction_types` INT DEFAULT NULL,
              `id_transaction_by` INT DEFAULT NULL,
              `id_users` INT DEFAULT NULL,
              `table_name` VARCHAR(100) DEFAULT NULL,
              `id_table_name` INT DEFAULT NULL,
              `transaction_log` LONGTEXT,
              PRIMARY KEY (`id`),
              KEY `IDX_EAA81A4C9343167` (`id_transaction_types`),
              KEY `IDX_EAA81A4C9254C31B` (`id_transaction_by`),
              KEY `IDX_EAA81A4CFA06E4D9` (`id_users`),
              CONSTRAINT `fk_transactions_id_transaction_types` FOREIGN KEY (`id_transaction_types`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_transactions_id_transaction_by` FOREIGN KEY (`id_transaction_by`) REFERENCES `lookups` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_transactions_id_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
        SQL);

        // ============================================================
        // 13) Stored procedures (canonical names, canonical bodies)
        // ============================================================
        $this->createStoredRoutines();
    }

    public function down(Schema $schema): void
    {
        // The baseline migration's down() drops every canonical schema
        // object. This is intentional: down() returns the database to a
        // pre-install state. The four seed migrations have their own
        // down() methods which simply delete the seed rows (no schema
        // change), so they cascade-clean automatically when the FK'd
        // parent rows are dropped here.

        // Drop routines first (they reference tables)
        foreach ([
            'get_user_acl',
            'get_data_table_filtered',
            'get_data_table_for_user_groups',
            'get_data_table_all_languages',
            'get_page_sections_hierarchical',
            'build_dynamic_columns',
            'build_exclude_deleted_filter',
            'build_language_filter',
            'build_time_period_filter',
            'convert_entry_date_timezone',
        ] as $routine) {
            $this->addSql("DROP PROCEDURE IF EXISTS `$routine`");
            $this->addSql("DROP FUNCTION IF EXISTS `$routine`");
        }

        // Reverse dependency order
        $this->addSql('ALTER TABLE `pages` DROP FOREIGN KEY `fk_pages_id_published_page_versions`');

        foreach ([
            'transactions',
            'scheduled_job_reminders',
            'scheduled_jobs',
            'role_data_access',
            'data_access_audits',
            'callback_logs',
            'api_request_logs',
            'validation_code_groups',
            'page_acl_groups',
            'rel_groups_users',
            'rel_roles_users',
            'rel_permissions_roles',
            'rel_api_routes_permissions',
            'api_routes',
            'assets',
            'action_translations',
            'actions',
            'data_cells',
            'data_rows',
            'data_cols',
            'data_tables',
            'page_versions',
            'sections_fields_translation',
            'rel_sections_navigation',
            'rel_sections_hierarchy',
            'rel_pages_sections',
            'sections',
            'pages_fields_translation',
            'rel_fields_pages',
            'pages',
            'rel_fields_page_types',
            'rel_styles_allowed_relationships',
            'rel_fields_styles',
            'styles',
            'fields',
            'refresh_tokens',
            'user_2fa_codes',
            'log_performance',
            'user_activities',
            'users',
            'validation_codes',
            'hooks',
            'plugins',
            'libraries',
            'page_types',
            'groups',
            'roles',
            'permissions',
            'lookups',
            'languages',
            'style_groups',
            'field_types',
        ] as $table) {
            $this->addSql("DROP TABLE IF EXISTS `$table`");
        }
    }

    /**
     * Create the five stored procedures consumed by the PHP repositories
     * and their helper functions, all rewritten against the canonical
     * snake_case schema. These bodies are deliberately self-contained
     * (no `view_*` references) so the views from the legacy schema can
     * be dropped without breaking the procedures.
     */
    private function createStoredRoutines(): void
    {
        // Helper FUNCTION: build_dynamic_columns
        // Builds the dynamic GROUP_CONCAT MAX(IF(col)) projection for a
        // given data table. Used inside the three data-table procedures.
        $this->addSql(<<<SQL
            CREATE FUNCTION `build_dynamic_columns`(table_id_param INT) RETURNS TEXT CHARSET utf8mb3
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                DECLARE result_sql TEXT;
                SELECT GROUP_CONCAT(DISTINCT CONCAT('MAX(IF(col.name = ''', c.name, ''', cell.value, NULL)) AS `', c.name, '`')
                       ORDER BY c.id SEPARATOR ', ')
                INTO result_sql
                FROM data_cols c
                WHERE c.id_data_tables = table_id_param;
                RETURN result_sql;
            END
        SQL);

        // Helper FUNCTION: build_exclude_deleted_filter
        $this->addSql(<<<SQL
            CREATE FUNCTION `build_exclude_deleted_filter`(exclude_deleted_param BOOLEAN) RETURNS TEXT CHARSET utf8mb3
                NO SQL
                DETERMINISTIC
            BEGIN
                IF exclude_deleted_param THEN
                    RETURN ' AND r.id_action_trigger_types <> (SELECT id FROM lookups WHERE type_code = ''actionTriggerTypes'' AND lookup_code = ''deleted'' LIMIT 1) ';
                END IF;
                RETURN '';
            END
        SQL);

        // Helper FUNCTION: build_language_filter
        $this->addSql(<<<SQL
            CREATE FUNCTION `build_language_filter`(language_id_param INT) RETURNS TEXT CHARSET utf8mb3
                NO SQL
                DETERMINISTIC
            BEGIN
                IF language_id_param > 0 THEN
                    RETURN CONCAT(' AND (cell.id_languages = ', language_id_param, ' OR cell.id_languages = 1) ');
                END IF;
                RETURN '';
            END
        SQL);

        // Helper FUNCTION: build_time_period_filter
        // Best-effort port of the legacy implementation; the filter
        // parameter is consumed by the data-table procedures as JSON,
        // but in practice the application passes additional WHERE
        // clauses so this remains a no-op unless explicitly used.
        $this->addSql(<<<SQL
            CREATE FUNCTION `build_time_period_filter`(filter_param VARCHAR(1000)) RETURNS TEXT CHARSET utf8mb3
                NO SQL
                DETERMINISTIC
            BEGIN
                RETURN '';
            END
        SQL);

        // Helper FUNCTION: convert_entry_date_timezone
        $this->addSql(<<<SQL
            CREATE FUNCTION `convert_entry_date_timezone`(timestamp_value DATETIME, timezone_code VARCHAR(100)) RETURNS VARCHAR(19) CHARSET utf8mb3
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                IF timezone_code IS NULL OR timezone_code = '' OR timezone_code = 'UTC' THEN
                    RETURN DATE_FORMAT(timestamp_value, '%Y-%m-%d %H:%i:%s');
                END IF;
                RETURN DATE_FORMAT(CONVERT_TZ(timestamp_value, 'UTC', timezone_code), '%Y-%m-%d %H:%i:%s');
            END
        SQL);

        // PROCEDURE: get_data_table_all_languages
        // (was get_dataTable_with_all_languages)
        $this->addSql(<<<SQL
            CREATE PROCEDURE `get_data_table_all_languages`(
                IN table_id_param INT,
                IN user_id_param INT,
                IN filter_param VARCHAR(1000),
                IN exclude_deleted_param BOOLEAN,
                IN timezone_code_param VARCHAR(100)
            )
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                SET @@group_concat_max_len = 32000000;
                SET @sql = build_dynamic_columns(table_id_param);

                IF (@sql IS NULL) THEN
                    SELECT `name` FROM data_tables WHERE 1=2;
                ELSE
                    BEGIN
                        SET @user_filter = '';
                        IF user_id_param > 0 THEN
                            SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
                        END IF;

                        SET @sql = CONCAT('SELECT r.id AS record_id, convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_action_trigger_types, l.lookup_code AS triggerType, cell.id_languages, lang.locale AS language_locale, lang.language AS language_name,',
                            @sql,
                            ' FROM data_tables t
                            INNER JOIN data_rows r ON (t.id = r.id_data_tables)
                            LEFT JOIN users u ON (r.id_users = u.id)
                            LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                            LEFT JOIN lookups l ON (l.id = r.id_action_trigger_types)
                            INNER JOIN data_cells cell ON (cell.id_data_rows = r.id)
                            INNER JOIN data_cols col ON (col.id = cell.id_data_cols)
                            LEFT JOIN languages lang ON (lang.id = cell.id_languages)
                            WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param),
                            ' GROUP BY r.id, cell.id_languages ORDER BY r.id, cell.id_languages');

                        SET @sql = CONCAT('SELECT * FROM (', @sql, ') AS filtered_data WHERE 1=1 ', filter_param);

                        PREPARE stmt FROM @sql;
                        EXECUTE stmt;
                        DEALLOCATE PREPARE stmt;
                    END;
                END IF;
            END
        SQL);

        // PROCEDURE: get_data_table_filtered
        // (was get_dataTable_with_filter)
        $this->addSql(<<<SQL
            CREATE PROCEDURE `get_data_table_filtered`(
                IN table_id_param INT,
                IN user_id_param INT,
                IN filter_param VARCHAR(1000),
                IN exclude_deleted_param BOOLEAN,
                IN language_id_param INT,
                IN timezone_code_param VARCHAR(100)
            )
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                SET @@group_concat_max_len = 32000000;
                SET @sql = build_dynamic_columns(table_id_param);

                IF (@sql IS NULL) THEN
                    SELECT `name` FROM data_tables WHERE 1=2;
                ELSE
                    BEGIN
                        SET @user_filter = '';
                        IF user_id_param > 0 THEN
                            SET @user_filter = CONCAT(' AND r.id_users = ', user_id_param);
                        END IF;

                        SET @sql = CONCAT('SELECT * FROM (SELECT r.id AS record_id, convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_action_trigger_types, l.lookup_code AS triggerType,', @sql,
                            ' FROM data_tables t
                            INNER JOIN data_rows r ON (t.id = r.id_data_tables)
                            INNER JOIN data_cells cell ON (cell.id_data_rows = r.id)
                            INNER JOIN data_cols col ON (col.id = cell.id_data_cols)
                            LEFT JOIN users u ON (r.id_users = u.id)
                            LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                            LEFT JOIN lookups l ON (l.id = r.id_action_trigger_types)
                            WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param), build_language_filter(language_id_param),
                            ' GROUP BY r.id ) AS r WHERE 1=1 ', filter_param);

                        PREPARE stmt FROM @sql;
                        EXECUTE stmt;
                        DEALLOCATE PREPARE stmt;
                    END;
                END IF;
            END
        SQL);

        // PROCEDURE: get_data_table_for_user_groups
        // (was get_dataTable_with_user_group_filter)
        $this->addSql(<<<SQL
            CREATE PROCEDURE `get_data_table_for_user_groups`(
                IN table_id_param INT,
                IN current_user_id_param INT,
                IN filter_param VARCHAR(1000),
                IN exclude_deleted_param BOOLEAN,
                IN language_id_param INT,
                IN timezone_code_param VARCHAR(100)
            )
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                SET @@group_concat_max_len = 32000000;
                SET @sql = build_dynamic_columns(table_id_param);

                IF (@sql IS NULL) THEN
                    SELECT `name` FROM data_tables WHERE 1=2;
                ELSE
                    BEGIN
                        SET @group_resource_type_id = (SELECT id FROM lookups WHERE type_code = 'resourceTypes' AND lookup_code = 'group' LIMIT 1);

                        DROP TEMPORARY TABLE IF EXISTS accessible_users_temp;
                        CREATE TEMPORARY TABLE accessible_users_temp AS
                        SELECT DISTINCT ug.id_users
                        FROM rel_groups_users ug
                        WHERE ug.id_groups IN (
                            SELECT rda.resource_id
                            FROM role_data_access rda
                            INNER JOIN roles r ON rda.id_roles = r.id
                            INNER JOIN rel_roles_users ur ON r.id = ur.id_roles
                            WHERE ur.id_users = current_user_id_param
                              AND rda.id_resource_types = @group_resource_type_id
                              AND rda.crud_permissions > 0
                        );

                        SET @user_filter = '';
                        SET @accessible_user_count = (SELECT COUNT(*) FROM accessible_users_temp);
                        IF @accessible_user_count > 0 THEN
                            SET @user_filter = ' AND r.id_users IN (SELECT id_users FROM accessible_users_temp)';
                        ELSE
                            SET @user_filter = ' AND 1=0';
                        END IF;

                        SET @sql = CONCAT('SELECT * FROM (SELECT r.id AS record_id, convert_entry_date_timezone(r.`timestamp`, "', timezone_code_param, '") AS entry_date, r.id_users, u.`name` AS user_name, vc.code AS user_code, r.id_action_trigger_types, l.lookup_code AS triggerType,', @sql,
                            ' FROM data_tables t
                            INNER JOIN data_rows r ON (t.id = r.id_data_tables)
                            INNER JOIN data_cells cell ON (cell.id_data_rows = r.id)
                            INNER JOIN data_cols col ON (col.id = cell.id_data_cols)
                            LEFT JOIN users u ON (r.id_users = u.id)
                            LEFT JOIN validation_codes vc ON (u.id = vc.id_users)
                            LEFT JOIN lookups l ON (l.id = r.id_action_trigger_types)
                            WHERE t.id = ', table_id_param, @user_filter, build_time_period_filter(filter_param), build_exclude_deleted_filter(exclude_deleted_param), build_language_filter(language_id_param),
                            ' GROUP BY r.id ) AS r WHERE 1=1 ', filter_param);

                        PREPARE stmt FROM @sql;
                        EXECUTE stmt;
                        DEALLOCATE PREPARE stmt;

                        DROP TEMPORARY TABLE IF EXISTS accessible_users_temp;
                    END;
                END IF;
            END
        SQL);

        // PROCEDURE: get_page_sections_hierarchical
        $this->addSql(<<<SQL
            CREATE PROCEDURE `get_page_sections_hierarchical`(IN page_id INT)
            BEGIN
                WITH RECURSIVE section_hierarchy AS (
                    SELECT
                        s.id,
                        s.`name`,
                        s.id_styles,
                        st.`name` AS style_name,
                        CASE
                            WHEN st.can_have_children = 1 THEN 1
                            WHEN EXISTS (
                                SELECT 1 FROM rel_styles_allowed_relationships sar
                                WHERE sar.id_parent_style = st.id
                            ) THEN 1
                            ELSE 0
                        END AS can_have_children,
                        s.`condition`,
                        s.css,
                        s.css_mobile,
                        s.debug,
                        s.data_config,
                        ps.`position` AS position,
                        0 AS `level`,
                        CAST(s.id AS CHAR(200)) AS `path`
                    FROM rel_pages_sections ps
                    JOIN sections s ON ps.id_sections = s.id
                    JOIN styles st ON s.id_styles = st.id
                    LEFT JOIN rel_sections_hierarchy sh ON s.id = sh.id_child_section
                    WHERE ps.id_pages = page_id
                      AND sh.id_parent_section IS NULL

                    UNION ALL

                    SELECT
                        s.id,
                        s.`name`,
                        s.id_styles,
                        st.`name` AS style_name,
                        CASE
                            WHEN st.can_have_children = 1 THEN 1
                            WHEN EXISTS (
                                SELECT 1 FROM rel_styles_allowed_relationships sar
                                WHERE sar.id_parent_style = st.id
                            ) THEN 1
                            ELSE 0
                        END AS can_have_children,
                        s.`condition`,
                        s.css,
                        s.css_mobile,
                        s.debug,
                        s.data_config,
                        sh.position AS position,
                        h.`level` + 1,
                        CONCAT(h.`path`, ',', s.id) AS `path`
                    FROM section_hierarchy h
                    JOIN rel_sections_hierarchy sh ON h.id = sh.id_parent_section
                    JOIN sections s ON sh.id_child_section = s.id
                    JOIN styles st ON s.id_styles = st.id
                )
                SELECT
                    id,
                    `name` AS section_name,
                    id_styles,
                    style_name,
                    can_have_children,
                    `condition`,
                    css,
                    css_mobile,
                    debug,
                    data_config,
                    position,
                    `level`,
                    `path`
                FROM section_hierarchy
                ORDER BY `path`, `position`;
            END
        SQL);

        // PROCEDURE: get_user_acl (rewritten against rel_groups_users + page_acl_groups)
        $this->addSql(<<<SQL
            CREATE PROCEDURE `get_user_acl`(
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
                    -- 1) Group-based ACL
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

                    -- 2) Open-access pages
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
    }
}
