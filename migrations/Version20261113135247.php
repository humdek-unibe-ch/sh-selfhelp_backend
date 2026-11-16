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
 * Two composite indexes on `data_rows` for the shared data query engine.
 *
 * Its candidate-id query reads the record rows of one table (optionally of one
 * owner), drops deleted ones, orders them by the entry date with the record id as
 * tie-breaker and takes one page. With only `idx_data_rows_id_data_tables` the
 * database read every record row of the table, and every row of the owner's table
 * for a participant, to find 25 of them (about 1 s and 4 s on a million-record
 * table). The new indexes
 *
 *   (id_data_tables, timestamp, id, id_action_trigger_types)
 *   (id_data_tables, id_users, timestamp, id, id_action_trigger_types)
 *
 * list the rows already in that order and carry the `deleted` marker, so the
 * newest page is a short backward index scan and the total is an index-only
 * count. `id` sits before `id_action_trigger_types` on purpose: the index order
 * then equals `ORDER BY timestamp DESC, id DESC`, so no sort is needed.
 *
 * Both are plain secondary indexes: nothing is stored differently and no query
 * changes meaning. MySQL builds them online (the table stays readable and
 * writable). `idx_data_rows_id_data_tables` is now a prefix of both; it stays,
 * dropping it is a separate clean-up.
 */
final class Version20261113135247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes on data_rows for ordered and owner-scoped pages and counts of the data query engine.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE data_rows '
            . 'ADD INDEX idx_data_rows_table_timestamp (id_data_tables, `timestamp`, id, id_action_trigger_types), '
            . 'ADD INDEX idx_data_rows_table_user_timestamp (id_data_tables, id_users, `timestamp`, id, id_action_trigger_types)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE data_rows DROP INDEX idx_data_rows_table_timestamp, DROP INDEX idx_data_rows_table_user_timestamp'
        );
    }
}
