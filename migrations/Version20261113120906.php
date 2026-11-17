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
 * History of every change to a record of the generic data store.
 *
 *  - `data_rows.version`: the record's version, advanced atomically with the
 *    change it counts, so two saves of the same version can never both win.
 *  - `data_revisions`: one event per persisted change (who, when, how, through
 *    which door), linked to the audit event of the same write.
 *  - `data_revision_changes`: what changed per field and language, or, for a
 *    checkpoint, what the field held afterwards.
 *  - `data_revision_values`: the immutable values, stored once per record.
 *
 * Everything cascades from `data_rows`, so erasing a user's rows erases their
 * history with it; the actor links are SET NULL, so removing a user who edited
 * someone else's record keeps that record's history and only loses the name.
 * No data is converted: a record without history gets a `baseline` revision the
 * first time it is changed.
 */
final class Version20261113120906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add record versions and the revision history tables (data_revisions, data_revision_changes, data_revision_values).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES "
            . "('dataRevisionOperations', 'create', 'Created', 'The record was created'), "
            . "('dataRevisionOperations', 'update', 'Updated', 'Field values of the record were changed'), "
            . "('dataRevisionOperations', 'delete', 'Deleted', 'The record was deleted (soft delete; its history is kept)'), "
            . "('dataRevisionOperations', 'restore', 'Restored', 'An earlier state of the record was applied as a new revision'), "
            . "('dataRevisionOperations', 'baseline', 'Baseline', 'The state a record already had before its history began (no earlier authorship is implied)'), "
            . "('dataRevisionSources', 'form', 'Form', 'Saved through a form of a page'), "
            . "('dataRevisionSources', 'admin', 'Administration', 'Saved through the administration (data management or an editor)'), "
            . "('dataRevisionSources', 'quick_action', 'Quick action', 'Changed by a configured one-click action of a list'), "
            . "('dataRevisionSources', 'job', 'Scheduled job', 'Changed by a scheduled job or action'), "
            . "('dataRevisionSources', 'import', 'Import', 'Written while importing data'), "
            . "('dataRevisionSources', 'system', 'System', 'Written by the platform itself')"
        );

        $this->addSql('CREATE TABLE data_revisions (id BIGINT AUTO_INCREMENT NOT NULL, version INT NOT NULL, created_at DATETIME NOT NULL, restored_from_version INT DEFAULT NULL, is_checkpoint TINYINT DEFAULT 0 NOT NULL, schema_version SMALLINT DEFAULT 1 NOT NULL, id_data_rows INT NOT NULL, id_data_tables INT NOT NULL, id_users INT DEFAULT NULL, id_impersonating_users INT DEFAULT NULL, id_operations INT NOT NULL, id_sources INT NOT NULL, id_transactions INT DEFAULT NULL, INDEX IDX_61E62B331F18364 (id_data_rows), INDEX IDX_61E62B3FCABFECF (id_data_tables), INDEX IDX_61E62B3FA06E4D9 (id_users), INDEX IDX_61E62B3F0E4F513 (id_impersonating_users), INDEX IDX_61E62B38D9ED39F (id_operations), INDEX IDX_61E62B3CC0CC82 (id_sources), INDEX IDX_61E62B3C9696F3E (id_transactions), INDEX idx_data_revisions_table_time (id_data_tables, created_at), UNIQUE INDEX uq_data_revisions_row_version (id_data_rows, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE data_revision_changes (id BIGINT AUTO_INCREMENT NOT NULL, field_key VARCHAR(255) NOT NULL, field_label VARCHAR(255) DEFAULT NULL, is_snapshot TINYINT DEFAULT 0 NOT NULL, id_data_revisions BIGINT NOT NULL, id_data_cols INT DEFAULT NULL, id_languages INT NOT NULL, id_before_values BIGINT DEFAULT NULL, id_after_values BIGINT DEFAULT NULL, INDEX IDX_20E3427770627804 (id_data_cols), INDEX IDX_20E3427720E4EF5E (id_languages), INDEX IDX_20E34277EF29D1FB (id_before_values), INDEX IDX_20E34277A59CCFE (id_after_values), INDEX idx_data_revision_changes_revision (id_data_revisions), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE data_revision_values (id BIGINT AUTO_INCREMENT NOT NULL, content_hash CHAR(64) NOT NULL, byte_length INT NOT NULL, encoding_version SMALLINT DEFAULT 1 NOT NULL, content LONGTEXT NOT NULL, id_data_rows INT NOT NULL, INDEX IDX_F95D06E631F18364 (id_data_rows), INDEX idx_data_revision_values_row_hash (id_data_rows, content_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('ALTER TABLE data_revisions ADD CONSTRAINT FK_61E62B331F18364 FOREIGN KEY (id_data_rows) REFERENCES data_rows (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE data_revisions ADD CONSTRAINT FK_61E62B3FCABFECF FOREIGN KEY (id_data_tables) REFERENCES data_tables (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE data_revisions ADD CONSTRAINT FK_61E62B3FA06E4D9 FOREIGN KEY (id_users) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE data_revisions ADD CONSTRAINT FK_61E62B3F0E4F513 FOREIGN KEY (id_impersonating_users) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE data_revisions ADD CONSTRAINT FK_61E62B38D9ED39F FOREIGN KEY (id_operations) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE data_revisions ADD CONSTRAINT FK_61E62B3CC0CC82 FOREIGN KEY (id_sources) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE data_revisions ADD CONSTRAINT FK_61E62B3C9696F3E FOREIGN KEY (id_transactions) REFERENCES transactions (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE data_revision_changes ADD CONSTRAINT FK_20E342773B78DFB5 FOREIGN KEY (id_data_revisions) REFERENCES data_revisions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE data_revision_changes ADD CONSTRAINT FK_20E3427770627804 FOREIGN KEY (id_data_cols) REFERENCES data_cols (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE data_revision_changes ADD CONSTRAINT FK_20E3427720E4EF5E FOREIGN KEY (id_languages) REFERENCES languages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE data_revision_changes ADD CONSTRAINT FK_20E34277EF29D1FB FOREIGN KEY (id_before_values) REFERENCES data_revision_values (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE data_revision_changes ADD CONSTRAINT FK_20E34277A59CCFE FOREIGN KEY (id_after_values) REFERENCES data_revision_values (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE data_revision_values ADD CONSTRAINT FK_F95D06E631F18364 FOREIGN KEY (id_data_rows) REFERENCES data_rows (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE data_rows ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_rows DROP version');
        $this->addSql('DROP TABLE data_revision_changes');
        $this->addSql('DROP TABLE data_revision_values');
        $this->addSql('DROP TABLE data_revisions');
        $this->addSql("DELETE FROM lookups WHERE type_code IN ('dataRevisionOperations', 'dataRevisionSources')");
    }
}
