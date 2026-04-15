<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the dedicated reminder-metadata table for scheduled jobs.
 *
 * The migration also removes legacy reminder columns from `scheduledJobs` when
 * they are present so schema diffs stay aligned with the Doctrine mapping.
 */
final class Version20260413130000 extends AbstractMigration
{
    /**
     * Describe the migration for Doctrine tooling.
     */
    public function getDescription(): string
    {
        return 'Create dedicated scheduled job reminder metadata table';
    }

    /**
     * Apply the reminder-metadata schema changes.
     *
     * @param Schema $schema
     *   The Doctrine schema object provided by the migration runtime.
     */
    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['scheduledJobs_reminders'])) {
            $this->addSql('CREATE TABLE scheduledJobs_reminders (id INT AUTO_INCREMENT NOT NULL, session_start_date DATETIME DEFAULT NULL, session_end_date DATETIME DEFAULT NULL, id_scheduledJobs INT NOT NULL, id_parentScheduledJobs INT DEFAULT NULL, id_dataTables INT DEFAULT NULL, UNIQUE INDEX UNIQ_23156A608030BA52 (id_scheduledJobs), INDEX IDX_23156A60C1B9838E (id_parentScheduledJobs), INDEX IDX_23156A60E2E6A7C3 (id_dataTables), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE scheduledJobs_reminders ADD CONSTRAINT FK_23156A608030BA52 FOREIGN KEY (id_scheduledJobs) REFERENCES scheduledJobs (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE scheduledJobs_reminders ADD CONSTRAINT FK_23156A60C1B9838E FOREIGN KEY (id_parentScheduledJobs) REFERENCES scheduledJobs (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE scheduledJobs_reminders ADD CONSTRAINT FK_23156A60E2E6A7C3 FOREIGN KEY (id_dataTables) REFERENCES dataTables (id) ON DELETE SET NULL');
        }

        if ($schemaManager->tablesExist(['scheduledJobs'])) {
            $scheduledJobsTable = $schemaManager->introspectTable('scheduledJobs');

            if ($scheduledJobsTable->hasIndex('IDX_3E186B37C1B9838E')) {
                $this->addSql('DROP INDEX IDX_3E186B37C1B9838E ON scheduledJobs');
            }

            if ($scheduledJobsTable->hasIndex('IDX_3E186B37DF817200')) {
                $this->addSql('DROP INDEX IDX_3E186B37DF817200 ON scheduledJobs');
            }

            $columnsToDrop = [];
            foreach (['id_parentScheduledJobs', 'id_reminderDataTables', 'reminder_session_start_date', 'reminder_session_end_date'] as $columnName) {
                if ($scheduledJobsTable->hasColumn($columnName)) {
                    $columnsToDrop[] = 'DROP COLUMN ' . $columnName;
                }
            }

            if ($columnsToDrop !== []) {
                $this->addSql('ALTER TABLE scheduledJobs ' . implode(', ', $columnsToDrop));
            }
        }
    }

    /**
     * Revert the reminder-metadata schema changes.
     *
     * @param Schema $schema
     *   The Doctrine schema object provided by the migration runtime.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduledJobs_reminders');
    }
}
