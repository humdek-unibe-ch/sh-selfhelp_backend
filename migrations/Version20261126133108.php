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
 * Contract C14: the stored values of plugin settings.
 *
 * `plugin_settings` holds one row per setting a plugin declares in its manifest `settings[]`. The row is keyed by
 * the plugin's manifest id (`plugin_id`, text), **not** by a foreign key to `plugins`: an uninstall deletes the
 * `plugins` row but keeps what the administrator entered (encrypted secrets included), a reinstall finds it
 * again, and only the purge deletes the rows (`PluginSettingsPurgeListener` on `PluginPurgedEvent`). Decision of
 * the user on 2026-11-26, replacing the `id_plugins` cascade of the first draft of C14.
 *
 * A secret is stored as `enc:v1:<base64>` (libsodium secretbox); `is_secret` says which rows hold ciphertext.
 *
 * `down()` drops the table, and with it every stored setting and secret: a migration rollback is a destructive
 * operation by nature, as for every table a migration creates.
 */
final class Version20261126133108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plugin settings (C14): plugin_settings keyed by the plugin manifest id; secrets stored encrypted.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE `plugin_settings` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `plugin_id` VARCHAR(100) NOT NULL,
              `setting_key` VARCHAR(64) NOT NULL,
              `value` LONGTEXT DEFAULT NULL,
              `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
              `updated_at` DATETIME NOT NULL,
              `id_users_updated` INT DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_plugin_settings_plugin_key` (`plugin_id`, `setting_key`),
              KEY `idx_plugin_settings_id_users_updated` (`id_users_updated`),
              CONSTRAINT `fk_plugin_settings_id_users_updated` FOREIGN KEY (`id_users_updated`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `plugin_settings`');
    }
}
