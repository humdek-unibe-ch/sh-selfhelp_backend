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
 * Index on `api_request_logs.id_users`.
 *
 * Removing a user (or cleaning their data) deletes that user's request log rows
 * by `id_users`. The table only had its primary key, so that DELETE scanned the
 * whole log and, under REPEATABLE READ, locked every row it passed until the
 * transaction ended. Every other request that wanted to write its own log row
 * waited behind it (and the test suite saw InnoDB deadlocks from it). With the
 * index the DELETE touches only the rows of that user.
 */
final class Version20261111104125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index api_request_logs.id_users so removing one user does not lock the whole request log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_api_request_logs_id_users ON api_request_logs (id_users)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_api_request_logs_id_users ON api_request_logs');
    }
}
