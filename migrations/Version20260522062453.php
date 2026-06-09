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
 * Plugin layer baseline (phase 1 of the plugin ecosystem rollout).
 *
 * Creates the four plugin-management tables and adds nullable `id_plugins`
 * columns on every core table that may be owned/contributed by a plugin
 * (styles, api_routes, fields, permissions, lookups, data_tables).
 *
 * Why nullable: core rows are owned by SelfHelp itself (no `id_plugins`).
 * Plugin-contributed rows carry the owning plugin's id. On uninstall, the
 * plugin manager deletes only rows whose `id_plugins` matches the plugin,
 * leaving core rows untouched.
 *
 * Foreign-key behavior: `ON DELETE SET NULL` so a fully purged plugin
 * record does not silently delete CMS content. The plugin manager's
 * `PluginPurger` is the only code path that actually deletes rows that
 * carry `id_plugins`.
 *
 * This migration is *additive* and reversible. The four new tables can
 * be dropped, and the `id_plugins` columns can be removed without
 * affecting existing functionality.
 *
 * @see docs/plugins/architecture.md
 */
final class Version20260522062453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plugin layer: plugins, plugin_operations, plugin_sources, plugin_feature_flags + id_plugins FKs on styles/api_routes/fields/permissions/lookups/data_tables.';
    }

    public function up(Schema $schema): void
    {
        // ============================================================
        // 1) plugins - installed plugin records
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `plugins` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `plugin_id` VARCHAR(100) NOT NULL COMMENT 'Plugin manifest id, e.g. sh2-shp-survey-js',
              `name` VARCHAR(255) NOT NULL,
              `description` LONGTEXT DEFAULT NULL,
              `version` VARCHAR(50) NOT NULL,
              `plugin_api_version` VARCHAR(20) NOT NULL,
              `trust_level` VARCHAR(20) NOT NULL DEFAULT 'untrusted' COMMENT 'official | reviewed | untrusted',
              `enabled` TINYINT(1) NOT NULL DEFAULT 0,
              `install_mode` VARCHAR(20) NOT NULL DEFAULT 'managed' COMMENT 'development | managed | trusted',
              `backend_package` VARCHAR(255) DEFAULT NULL,
              `backend_bundle_class` VARCHAR(255) DEFAULT NULL,
              `frontend_package` VARCHAR(255) DEFAULT NULL,
              `frontend_package_version` VARCHAR(50) DEFAULT NULL,
              `mobile_package` VARCHAR(255) DEFAULT NULL,
              `mobile_package_version` VARCHAR(50) DEFAULT NULL,
              `manifest_json` JSON NOT NULL COMMENT 'Cached full plugin.json',
              `capabilities_json` JSON NOT NULL COMMENT 'Granted capabilities at install time',
              `checksum_sha256` VARCHAR(128) DEFAULT NULL,
              `signature_ed25519` VARCHAR(512) DEFAULT NULL,
              `installed_at` DATETIME NOT NULL,
              `updated_at` DATETIME NOT NULL,
              `enabled_at` DATETIME DEFAULT NULL,
              `disabled_at` DATETIME DEFAULT NULL,
              `notes` LONGTEXT DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_plugins_plugin_id` (`plugin_id`),
              KEY `idx_plugins_enabled` (`enabled`),
              KEY `idx_plugins_trust_level` (`trust_level`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ============================================================
        // 2) plugin_sources - registry and private source configuration
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `plugin_sources` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(100) NOT NULL COMMENT 'Friendly source name',
              `kind` VARCHAR(20) NOT NULL COMMENT 'public-registry | private-registry | git | local',
              `url` VARCHAR(1000) NOT NULL,
              `auth_header_name` VARCHAR(100) DEFAULT NULL COMMENT 'e.g. Authorization or X-Token',
              `auth_secret_env_var` VARCHAR(100) DEFAULT NULL COMMENT 'Env var name holding the secret (never the secret itself)',
              `channel` VARCHAR(20) NOT NULL DEFAULT 'stable' COMMENT 'stable | beta | nightly',
              `trust_level` VARCHAR(20) NOT NULL DEFAULT 'untrusted' COMMENT 'official | reviewed | untrusted',
              `enabled` TINYINT(1) NOT NULL DEFAULT 1,
              `last_synced_at` DATETIME DEFAULT NULL,
              `created_at` DATETIME NOT NULL,
              `updated_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_plugin_sources_name` (`name`),
              KEY `idx_plugin_sources_enabled` (`enabled`),
              KEY `idx_plugin_sources_kind` (`kind`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ============================================================
        // 3) plugin_operations - staged install/update/uninstall log
        //
        // `id_plugins` is nullable because the row is created BEFORE the
        // plugin's `plugins` row exists (first install). `plugin_id` is
        // denormalized into the row so we can still find the operation
        // by plugin id while the FK is null.
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `plugin_operations` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `id_plugins` INT DEFAULT NULL,
              `plugin_id` VARCHAR(100) NOT NULL,
              `type` VARCHAR(30) NOT NULL COMMENT 'install | update | disable | enable | uninstall | purge | rollback | repair',
              `status` VARCHAR(20) NOT NULL DEFAULT 'requested' COMMENT 'requested | running | succeeded | failed | cancelled | rolled_back',
              `requested_version` VARCHAR(50) DEFAULT NULL,
              `from_version` VARCHAR(50) DEFAULT NULL,
              `to_version` VARCHAR(50) DEFAULT NULL,
              `id_requested_by_users` INT DEFAULT NULL,
              `install_mode` VARCHAR(20) NOT NULL DEFAULT 'managed',
              `snapshots_json` JSON DEFAULT NULL COMMENT 'Pre/post snapshots of plugin-owned rows',
              `rollback_plan_json` JSON DEFAULT NULL COMMENT 'Planned rollback actions',
              `logs_json` JSON DEFAULT NULL COMMENT 'Array of log entries',
              `error_summary` LONGTEXT DEFAULT NULL,
              `started_at` DATETIME DEFAULT NULL,
              `finished_at` DATETIME DEFAULT NULL,
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_plugin_operations_id_plugins` (`id_plugins`),
              KEY `idx_plugin_operations_plugin_id` (`plugin_id`),
              KEY `idx_plugin_operations_status` (`status`),
              KEY `idx_plugin_operations_created_at` (`created_at`),
              CONSTRAINT `fk_plugin_operations_id_plugins` FOREIGN KEY (`id_plugins`) REFERENCES `plugins` (`id`) ON DELETE SET NULL,
              CONSTRAINT `fk_plugin_operations_id_requested_by_users` FOREIGN KEY (`id_requested_by_users`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ============================================================
        // 4) plugin_feature_flags - composite-keyed flag toggles
        //
        // Scope semantics:
        //   - scope='global', scope_value=NULL - server-wide flag.
        //   - scope='role',   scope_value=<role id>   - per-role flag.
        //   - scope='user',   scope_value=<user id>   - per-user flag.
        //   - scope='group',  scope_value=<group id>  - per-group flag.
        //
        // Composite PK + unique key make conflicting rows impossible. We
        // intentionally use a string `scope_value` so the same flag can
        // target different scope kinds without separate tables.
        // ============================================================
        $this->addSql(<<<SQL
            CREATE TABLE `plugin_feature_flags` (
              `id_plugins` INT NOT NULL,
              `flag_key` VARCHAR(100) NOT NULL,
              `scope` VARCHAR(20) NOT NULL DEFAULT 'global' COMMENT 'global | role | user | group',
              `scope_value` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Empty string for global scope',
              `enabled` TINYINT(1) NOT NULL DEFAULT 0,
              `updated_at` DATETIME NOT NULL,
              `id_updated_by_users` INT DEFAULT NULL,
              PRIMARY KEY (`id_plugins`, `flag_key`, `scope`, `scope_value`),
              KEY `idx_plugin_feature_flags_flag_key` (`flag_key`),
              CONSTRAINT `fk_plugin_feature_flags_id_plugins` FOREIGN KEY (`id_plugins`) REFERENCES `plugins` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_plugin_feature_flags_id_updated_by_users` FOREIGN KEY (`id_updated_by_users`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ============================================================
        // 5) Add id_plugins ownership column to core tables.
        //
        // ON DELETE SET NULL is intentional: dropping a plugin row must
        // NOT silently delete CMS content. The plugin manager's
        // PluginPurger is the only code path that actually deletes rows
        // whose `id_plugins` matches the plugin being purged.
        // ============================================================
        $this->addSql('ALTER TABLE `styles` ADD COLUMN `id_plugins` INT DEFAULT NULL AFTER `id_style_groups`');
        $this->addSql('ALTER TABLE `styles` ADD KEY `idx_styles_id_plugins` (`id_plugins`)');
        $this->addSql('ALTER TABLE `styles` ADD CONSTRAINT `fk_styles_id_plugins` FOREIGN KEY (`id_plugins`) REFERENCES `plugins` (`id`) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE `api_routes` ADD COLUMN `id_plugins` INT DEFAULT NULL AFTER `params`');
        $this->addSql('ALTER TABLE `api_routes` ADD KEY `idx_api_routes_id_plugins` (`id_plugins`)');
        $this->addSql('ALTER TABLE `api_routes` ADD CONSTRAINT `fk_api_routes_id_plugins` FOREIGN KEY (`id_plugins`) REFERENCES `plugins` (`id`) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE `fields` ADD COLUMN `id_plugins` INT DEFAULT NULL AFTER `config`');
        $this->addSql('ALTER TABLE `fields` ADD KEY `idx_fields_id_plugins` (`id_plugins`)');
        $this->addSql('ALTER TABLE `fields` ADD CONSTRAINT `fk_fields_id_plugins` FOREIGN KEY (`id_plugins`) REFERENCES `plugins` (`id`) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE `permissions` ADD COLUMN `id_plugins` INT DEFAULT NULL AFTER `description`');
        $this->addSql('ALTER TABLE `permissions` ADD KEY `idx_permissions_id_plugins` (`id_plugins`)');
        $this->addSql('ALTER TABLE `permissions` ADD CONSTRAINT `fk_permissions_id_plugins` FOREIGN KEY (`id_plugins`) REFERENCES `plugins` (`id`) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE `lookups` ADD COLUMN `id_plugins` INT DEFAULT NULL AFTER `lookup_description`');
        $this->addSql('ALTER TABLE `lookups` ADD KEY `idx_lookups_id_plugins` (`id_plugins`)');
        $this->addSql('ALTER TABLE `lookups` ADD CONSTRAINT `fk_lookups_id_plugins` FOREIGN KEY (`id_plugins`) REFERENCES `plugins` (`id`) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE `data_tables` ADD COLUMN `id_plugins` INT DEFAULT NULL AFTER `display_name`');
        $this->addSql('ALTER TABLE `data_tables` ADD KEY `idx_data_tables_id_plugins` (`id_plugins`)');
        $this->addSql('ALTER TABLE `data_tables` ADD CONSTRAINT `fk_data_tables_id_plugins` FOREIGN KEY (`id_plugins`) REFERENCES `plugins` (`id`) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `data_tables` DROP FOREIGN KEY `fk_data_tables_id_plugins`');
        $this->addSql('ALTER TABLE `data_tables` DROP KEY `idx_data_tables_id_plugins`');
        $this->addSql('ALTER TABLE `data_tables` DROP COLUMN `id_plugins`');

        $this->addSql('ALTER TABLE `lookups` DROP FOREIGN KEY `fk_lookups_id_plugins`');
        $this->addSql('ALTER TABLE `lookups` DROP KEY `idx_lookups_id_plugins`');
        $this->addSql('ALTER TABLE `lookups` DROP COLUMN `id_plugins`');

        $this->addSql('ALTER TABLE `permissions` DROP FOREIGN KEY `fk_permissions_id_plugins`');
        $this->addSql('ALTER TABLE `permissions` DROP KEY `idx_permissions_id_plugins`');
        $this->addSql('ALTER TABLE `permissions` DROP COLUMN `id_plugins`');

        $this->addSql('ALTER TABLE `fields` DROP FOREIGN KEY `fk_fields_id_plugins`');
        $this->addSql('ALTER TABLE `fields` DROP KEY `idx_fields_id_plugins`');
        $this->addSql('ALTER TABLE `fields` DROP COLUMN `id_plugins`');

        $this->addSql('ALTER TABLE `api_routes` DROP FOREIGN KEY `fk_api_routes_id_plugins`');
        $this->addSql('ALTER TABLE `api_routes` DROP KEY `idx_api_routes_id_plugins`');
        $this->addSql('ALTER TABLE `api_routes` DROP COLUMN `id_plugins`');

        $this->addSql('ALTER TABLE `styles` DROP FOREIGN KEY `fk_styles_id_plugins`');
        $this->addSql('ALTER TABLE `styles` DROP KEY `idx_styles_id_plugins`');
        $this->addSql('ALTER TABLE `styles` DROP COLUMN `id_plugins`');

        $this->addSql('DROP TABLE IF EXISTS `plugin_feature_flags`');
        $this->addSql('DROP TABLE IF EXISTS `plugin_operations`');
        $this->addSql('DROP TABLE IF EXISTS `plugin_sources`');
        $this->addSql('DROP TABLE IF EXISTS `plugins`');
    }
}
