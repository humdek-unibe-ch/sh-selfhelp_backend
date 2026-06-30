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
 * Immutable `data_cols.field_key` + mutable `display_name` (issue #56).
 *
 * `data_cols.name` historically served as BOTH the immutable storage key
 * (looked up at write time to find the column for a submitted field) AND the
 * mutable admin/question label. Renaming the label created a second column and
 * fragmented one logical field across rows. This migration splits the two:
 *
 *   - `name`         -> `field_key`  (immutable, opaque storage key)
 *   - `display_name` (new, nullable) mutable human label
 *   - `display_name_source` (new)    `auto` | `manual` provenance flag so an
 *                                    auto label refresh never overwrites a
 *                                    manually curated one
 *   - UNIQUE (id_data_tables, field_key)
 *
 * `field_key` is an opaque ASCII identifier whose derivation is owned by each
 * data source (core CMS forms use `section_<input section id>`; SurveyJS uses
 * `question.name`). It therefore needs no special collation — the table's
 * default collation is sufficient and keeps Doctrine's schema comparator in
 * sync (the DataCol entity declares no per-column collation override).
 *
 * `build_dynamic_columns` (consumed by the three data-table read procedures)
 * is rebuilt to pivot on `field_key` with backtick-safe aliases so dotted
 * SurveyJS keys (e.g. `page.panel.question`) project as opaque literal
 * column labels.
 *
 * Not transactional: it mixes DDL (ALTER / CREATE INDEX / CREATE FUNCTION),
 * and MySQL auto-commits DDL, so an outer transaction is not meaningful here.
 */
final class Version20260626120120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'data_cols: rename name -> field_key (immutable opaque key), add display_name + display_name_source, add unique key, pivot build_dynamic_columns by field_key.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Rename the storage key column (preserving values) and add the mutable
        // label + provenance columns. No collation change: field_key inherits
        // the data_cols default collation, so Doctrine sees no schema drift.
        $this->addSql("ALTER TABLE data_cols CHANGE name field_key VARCHAR(255) DEFAULT NULL, ADD display_name VARCHAR(255) DEFAULT NULL, ADD display_name_source VARCHAR(16) DEFAULT 'auto' NOT NULL");

        $this->addSql('CREATE UNIQUE INDEX uq_data_cols_id_data_tables_field_key ON data_cols (id_data_tables, field_key)');

        // Rebuild the dynamic projection helper to pivot by field_key with a
        // backtick-safe alias (REPLACE escapes are defensive; keys are
        // validated to exclude quotes/backticks at write time).
        $this->addSql('DROP FUNCTION IF EXISTS build_dynamic_columns');
        $this->addSql(<<<SQL
            CREATE FUNCTION `build_dynamic_columns`(table_id_param INT) RETURNS TEXT CHARSET utf8mb3
                READS SQL DATA
                DETERMINISTIC
            BEGIN
                DECLARE result_sql TEXT;
                SELECT GROUP_CONCAT(DISTINCT CONCAT('MAX(IF(col.field_key = ''', REPLACE(c.field_key, '''', ''''''), ''', cell.value, NULL)) AS `', REPLACE(c.field_key, '`', '``'), '`')
                       ORDER BY c.id SEPARATOR ', ')
                INTO result_sql
                FROM data_cols c
                WHERE c.id_data_tables = table_id_param;
                RETURN result_sql;
            END
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Restore the original name-based projection helper.
        $this->addSql('DROP FUNCTION IF EXISTS build_dynamic_columns');
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

        $this->addSql('DROP INDEX uq_data_cols_id_data_tables_field_key ON data_cols');
        // Revert the columns: drop the new metadata and rename the key back to `name`.
        $this->addSql('ALTER TABLE data_cols DROP display_name, DROP display_name_source, CHANGE field_key name VARCHAR(255) DEFAULT NULL');
    }
}
