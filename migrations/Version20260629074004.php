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
 * Issue #56: give data_tables the same auto/manual display-name provenance as
 * data_cols. A nullable id_display_name_source FK (reusing the existing
 * `dataColDisplayNameSource` auto|manual lookup rows) lets an admin lock a
 * manually-renamed table so the form section's `displayName` field stops
 * overwriting it on every save. NULL = the default `auto` provenance.
 */
final class Version20260629074004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add id_display_name_source provenance FK to data_tables (issue #56).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_tables ADD id_display_name_source INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_tables ADD CONSTRAINT FK_E4B93338BE6458B2 FOREIGN KEY (id_display_name_source) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E4B93338BE6458B2 ON data_tables (id_display_name_source)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_tables DROP FOREIGN KEY FK_E4B93338BE6458B2');
        $this->addSql('DROP INDEX IDX_E4B93338BE6458B2 ON data_tables');
        $this->addSql('ALTER TABLE data_tables DROP id_display_name_source');
    }
}
