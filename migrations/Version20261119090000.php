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
 * Value type `file` for data columns whose cells name uploaded form files
 * without a `file-input` section feeding them (a plugin's upload column, for
 * example SurveyJS). The Data screen, the file delivery and the zip download
 * treat such a column like a form's file column.
 */
final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the data column value type file (lookup dataColValueTypes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES "
            . "('dataColValueTypes', 'file', 'File', 'The cells name uploaded files; compared and sorted as text')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE data_cols SET id_value_type = NULL WHERE id_value_type IN (SELECT id FROM (SELECT id FROM lookups WHERE type_code = 'dataColValueTypes' AND lookup_code = 'file') l)");
        $this->addSql("DELETE FROM lookups WHERE type_code = 'dataColValueTypes' AND lookup_code = 'file'");
    }
}
