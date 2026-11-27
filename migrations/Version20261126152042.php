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
 * Record version reference on scheduled jobs (contract C26).
 *
 * `scheduled_jobs.record_ref` names which version of a record triggered the job
 * (`record:<id>@v<n>`). Nullable, no FK: jobs without a record leave it null,
 * and the value stays when history is erased. Hand-filled migration generated
 * with `doctrine:migrations:generate`.
 */
final class Version20261126152042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add record_ref to scheduled_jobs (record version a job belongs to, C26)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_jobs ADD record_ref VARCHAR(191) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_jobs DROP record_ref');
    }
}
