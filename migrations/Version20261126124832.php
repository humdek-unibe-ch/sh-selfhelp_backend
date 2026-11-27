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
 * Content reference of a record (contract C19).
 *
 *  - `data_rows.content_ref`: the reference of the latest write that named one.
 *  - `data_revisions.content_ref`: the reference of that one write.
 *
 * A reference names the content a record was written for: `page:<id>@v<n>` (a
 * published page version, or `@draft` for an editor's preview) or
 * `res:<type>:<id>@v<n>` (a plugin item version). Pinning an unfinished record
 * to its item version reads the row's reference.
 *
 * Existing rows stay NULL ("unknown"); nothing is converted. The existing index
 * `idx_data_rows_table_user_timestamp` already covers the pin lookup (table and
 * user, newest first, trigger type in the index), so no index is added.
 * Hand-filled migration generated with `doctrine:migrations:generate`.
 */
final class Version20261126124832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add content_ref to data_rows and data_revisions (content version of a record write, C19)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_rows ADD content_ref VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE data_revisions ADD content_ref VARCHAR(191) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE data_revisions DROP content_ref');
        $this->addSql('ALTER TABLE data_rows DROP content_ref');
    }
}
