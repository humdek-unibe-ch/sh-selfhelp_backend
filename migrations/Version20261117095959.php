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
 * Plugin owner on `field_types` and `style_groups` (rule 7.8, F112).
 *
 * The plugin installer now writes a plugin's styles, fields, field
 * types, style groups, permissions and lookups from its manifest
 * ({@see \App\Plugin\Lifecycle\PluginCatalogueSynchronizer}). Styles,
 * fields, permissions and lookups already carry `id_plugins`; the two
 * remaining catalogue tables get the same nullable column so purge can
 * remove a plugin's rows and the installer can refuse a name another
 * owner holds. Existing rows stay core-owned (NULL).
 */
final class Version20261117095959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable id_plugins (ON DELETE SET NULL) to field_types and style_groups for manifest-declared plugin catalogue rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_types ADD id_plugins INT DEFAULT NULL');
        $this->addSql('ALTER TABLE field_types ADD CONSTRAINT FK_72971FCB32185F01 FOREIGN KEY (id_plugins) REFERENCES plugins (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_field_types_id_plugins ON field_types (id_plugins)');
        $this->addSql('ALTER TABLE style_groups ADD id_plugins INT DEFAULT NULL');
        $this->addSql('ALTER TABLE style_groups ADD CONSTRAINT FK_DAC8C7AA32185F01 FOREIGN KEY (id_plugins) REFERENCES plugins (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_style_groups_id_plugins ON style_groups (id_plugins)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_types DROP FOREIGN KEY FK_72971FCB32185F01');
        $this->addSql('DROP INDEX idx_field_types_id_plugins ON field_types');
        $this->addSql('ALTER TABLE field_types DROP id_plugins');
        $this->addSql('ALTER TABLE style_groups DROP FOREIGN KEY FK_DAC8C7AA32185F01');
        $this->addSql('DROP INDEX idx_style_groups_id_plugins ON style_groups');
        $this->addSql('ALTER TABLE style_groups DROP id_plugins');
    }
}
