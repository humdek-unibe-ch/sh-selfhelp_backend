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
 * Issue #56: model `data_cols.display_name` provenance as a lookups FK instead of
 * a free-text column.
 *
 * The provenance flag (`auto` | `manual`) becomes the central-registry lookup
 * group `dataColDisplayNameSource`, and `data_cols.display_name_source`
 * (VARCHAR) is replaced by the nullable FK `data_cols.id_display_name_source`
 * → `lookups.id`. A NULL FK is the default `auto`; only an admin-curated label
 * points at the `manual` lookup, so auto label pushes from later submissions
 * never overwrite it.
 */
final class Version20260626143127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue #56: replace data_cols.display_name_source (VARCHAR auto|manual) with the lookups FK data_cols.id_display_name_source (group dataColDisplayNameSource).';
    }

    public function up(Schema $schema): void
    {
        // 1. Seed the provenance values into the central lookup registry (core-owned).
        $this->addSql(
            "INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES "
            . "('dataColDisplayNameSource', 'auto', 'Auto', 'data_cols.display_name is auto-derived from the submitted form/SurveyJS label and may be refreshed by later submissions'), "
            . "('dataColDisplayNameSource', 'manual', 'Manual', 'data_cols.display_name was curated by an admin and is never overwritten by submissions')"
        );

        // 2. Add the nullable FK column (NULL = default 'auto' provenance).
        $this->addSql('ALTER TABLE data_cols ADD id_display_name_source INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_cols ADD CONSTRAINT FK_F057C423BE6458B2 FOREIGN KEY (id_display_name_source) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F057C423BE6458B2 ON data_cols (id_display_name_source)');

        // 3. Backfill: only 'manual' rows need an explicit FK; 'auto' stays NULL.
        $this->addSql(
            "UPDATE data_cols dc "
            . "JOIN lookups l ON l.type_code = 'dataColDisplayNameSource' AND l.lookup_code = 'manual' "
            . "SET dc.id_display_name_source = l.id "
            . "WHERE dc.display_name_source = 'manual'"
        );

        // 4. Drop the old free-text column.
        $this->addSql('ALTER TABLE data_cols DROP display_name_source');
    }

    public function down(Schema $schema): void
    {
        // Restore the free-text column and re-derive it from the FK.
        $this->addSql("ALTER TABLE data_cols ADD display_name_source VARCHAR(16) DEFAULT 'auto' NOT NULL");
        $this->addSql(
            "UPDATE data_cols dc "
            . "LEFT JOIN lookups l ON l.id = dc.id_display_name_source "
            . "SET dc.display_name_source = COALESCE(l.lookup_code, 'auto')"
        );

        // Drop the FK column.
        $this->addSql('ALTER TABLE data_cols DROP FOREIGN KEY FK_F057C423BE6458B2');
        $this->addSql('DROP INDEX IDX_F057C423BE6458B2 ON data_cols');
        $this->addSql('ALTER TABLE data_cols DROP id_display_name_source');

        // Remove the seeded lookup values.
        $this->addSql("DELETE FROM lookups WHERE type_code = 'dataColDisplayNameSource'");
    }
}
