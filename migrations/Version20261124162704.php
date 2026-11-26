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
 * F91: index data_rows.id_users so user cleanup does not scan the whole table.
 */
final class Version20261124162704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idx_data_rows_id_users for user cleanup by owner';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_data_rows_id_users ON data_rows (id_users)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_data_rows_id_users ON data_rows');
    }
}
