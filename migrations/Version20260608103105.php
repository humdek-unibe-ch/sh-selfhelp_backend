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
 * Add the scheduled_job_runner_settings.retention_max_runs column so the
 * scheduled-job runner can auto-prune its run-history audit table (issue #34).
 */
final class Version20260608103105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scheduled_job_runner_settings.retention_max_runs for runner audit retention (issue #34).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_job_runner_settings ADD retention_max_runs INT DEFAULT 5000');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_job_runner_settings DROP retention_max_runs');
    }
}
