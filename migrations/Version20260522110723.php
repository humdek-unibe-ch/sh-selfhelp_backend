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
 * 1. Adds a boolean `is_system` column to `plugin_sources` so the
 *    backend can distinguish host-managed sources (seeded here and in
 *    future migrations) from operator-added ones. System sources are
 *    read-only via the admin API except for the `enabled` toggle.
 * 2. Seeds the default `humdek-public` plugin registry pointing at
 *    https://humdek-unibe-ch.github.io/sh2-plugin-registry/. Every
 *    fresh SelfHelp install ships with this row enabled so admins can
 *    browse the official plugin catalogue out of the box.
 *
 * The DDL change is additive and safe to roll back.
 */
final class Version20260522110723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'plugin_sources: add is_system column and seed default humdek-public registry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE `plugin_sources`
                ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0
                COMMENT 'Host-managed source; read-only via admin API.'
                AFTER `enabled`
        SQL);

        // Seed the canonical public registry. INSERT IGNORE so the row
        // is created on a fresh install but skipped if the operator
        // happens to already have a source with this name (defensive
        // — `name` is UNIQUE).
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `plugin_sources`
                (`name`, `kind`, `url`, `channel`, `trust_level`, `enabled`, `is_system`, `created_at`, `updated_at`)
            VALUES (
                'humdek-public',
                'public-registry',
                'https://humdek-unibe-ch.github.io/sh2-plugin-registry/',
                'stable',
                'official',
                1,
                1,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM `plugin_sources` WHERE `name` = 'humdek-public' AND `is_system` = 1");
        $this->addSql('ALTER TABLE `plugin_sources` DROP COLUMN `is_system`');
    }
}
