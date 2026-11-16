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
 * Value type of a data column (F22), so typed filters and sorts of the shared
 * data query engine never guess a type from the stored text.
 *
 * `data_cols.id_value_type` is a nullable FK to the lookup group
 * `dataColValueTypes` (`text` | `number` | `date` | `datetime` | `boolean`),
 * the same pattern as `id_display_name_source`. NULL is the default `text`.
 * A known form input supplies the type when its column is created and refreshes
 * it when the form is saved; any other source (SurveyJS, imports, plugins)
 * stays `text` until a type is declared explicitly.
 */
final class Version20261113104850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add data_cols.id_value_type (lookup group dataColValueTypes) for typed filters and sorts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES "
            . "('dataColValueTypes', 'text', 'Text', 'Compared and sorted as text (the default)'), "
            . "('dataColValueTypes', 'number', 'Number', 'Compared and sorted as a decimal number; values that are not numbers sort last'), "
            . "('dataColValueTypes', 'date', 'Date', 'Compared and sorted as a calendar date (YYYY-MM-DD)'), "
            . "('dataColValueTypes', 'datetime', 'Date and time', 'Compared and sorted as a date with time (YYYY-MM-DD HH:MM[:SS])'), "
            . "('dataColValueTypes', 'boolean', 'Yes/No', 'Compared as true or false (1, true, yes, on / 0, false, no, off)')"
        );

        $this->addSql('ALTER TABLE data_cols ADD id_value_type INT DEFAULT NULL');
        $this->addSql('ALTER TABLE data_cols ADD CONSTRAINT FK_F057C42380BB8C27 FOREIGN KEY (id_value_type) REFERENCES lookups (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F057C42380BB8C27 ON data_cols (id_value_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_cols DROP FOREIGN KEY FK_F057C42380BB8C27');
        $this->addSql('DROP INDEX IDX_F057C42380BB8C27 ON data_cols');
        $this->addSql('ALTER TABLE data_cols DROP id_value_type');
        $this->addSql("DELETE FROM lookups WHERE type_code = 'dataColValueTypes'");
    }
}
