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
 * Promote asset folders to a first-class table so folder-level settings have a
 * row to live on, and add the `is_open_access` flag.
 *
 * An open-access folder is readable by everyone, including anonymous callers,
 * mirroring the page contract (`pages.is_open_access`). It never confers
 * `manage` — writing still requires an explicit group grant.
 *
 * `assets_folders_groups.folder` (a plain string) becomes the FK
 * `id_assets_folders`, so a grant can no longer point at a folder that does not
 * exist. Existing grants are preserved: the folder table is seeded from the
 * distinct folder names already present in `assets_folders_groups` and `assets`
 * BEFORE the old string column is dropped.
 *
 * `assets.folder` intentionally stays a varchar — it doubles as the on-disk
 * path segment and is matched to this table by name.
 */
final class Version20260728083949 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add assets_folders table with is_open_access and repoint assets_folders_groups to it via FK.';
    }

    public function up(Schema $schema): void
    {
        // ENGINE is explicit: this server's default_storage_engine is MyISAM,
        // which silently IGNORES foreign keys. Without InnoDB the FK below is
        // accepted and dropped, leaving exactly the orphaned-grant problem this
        // migration exists to prevent.
        $this->addSql('CREATE TABLE assets_folders (id INT AUTO_INCREMENT NOT NULL, folder VARCHAR(100) NOT NULL, is_open_access TINYINT(1) DEFAULT 0 NOT NULL, UNIQUE INDEX uq_assets_folders_folder (folder), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        // assets_folders_groups was created MyISAM by Version20260722092220 (same
        // server default), so convert it before adding the FK.
        $this->addSql('ALTER TABLE assets_folders_groups ENGINE = InnoDB');

        // Seed folder rows from every folder name already in use, so no existing
        // grant is orphaned and every known folder is manageable in the admin UI.
        $this->addSql('INSERT INTO assets_folders (folder, is_open_access) SELECT DISTINCT folder, 0 FROM assets_folders_groups WHERE folder IS NOT NULL AND folder <> \'\'');
        $this->addSql('INSERT INTO assets_folders (folder, is_open_access) SELECT DISTINCT a.folder, 0 FROM assets a WHERE a.folder IS NOT NULL AND a.folder <> \'\' AND NOT EXISTS (SELECT 1 FROM assets_folders af WHERE af.folder = a.folder)');

        // Add the FK column, backfill it by matching on the old string, and only
        // then drop the string column.
        $this->addSql('ALTER TABLE assets_folders_groups ADD id_assets_folders INT NULL');
        $this->addSql('UPDATE assets_folders_groups afg INNER JOIN assets_folders af ON af.folder = afg.folder SET afg.id_assets_folders = af.id');

        // Defensive: a grant whose folder somehow found no row would violate the
        // NOT NULL below. There should be none after the seeding above.
        $this->addSql('DELETE FROM assets_folders_groups WHERE id_assets_folders IS NULL');

        $this->addSql('DROP INDEX idx_assets_folders_groups_folder ON assets_folders_groups');
        $this->addSql('DROP INDEX uq_assets_folders_groups_folder_group ON assets_folders_groups');
        $this->addSql('ALTER TABLE assets_folders_groups DROP folder');
        $this->addSql('ALTER TABLE assets_folders_groups MODIFY id_assets_folders INT NOT NULL');

        $this->addSql('ALTER TABLE assets_folders_groups ADD CONSTRAINT FK_F189C34051B7E443 FOREIGN KEY (id_assets_folders) REFERENCES assets_folders (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_F189C34051B7E443 ON assets_folders_groups (id_assets_folders)');
        $this->addSql('CREATE UNIQUE INDEX uq_assets_folders_groups_folder_group ON assets_folders_groups (id_assets_folders, id_groups)');

        // Version20260722092220 declared this FK, but the table was MyISAM then so
        // MySQL accepted and discarded it. Now that the table is InnoDB, create it
        // for real (idempotent: skipped when an earlier InnoDB install already has it).
        $this->addSql(
            'SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = \'assets_folders_groups\' '
            . 'AND CONSTRAINT_NAME = \'FK_F189C340D65A8C9D\' AND CONSTRAINT_TYPE = \'FOREIGN KEY\')'
        );
        $this->addSql(
            'SET @sql := IF(@fk_exists = 0, '
            . '\'ALTER TABLE assets_folders_groups ADD CONSTRAINT FK_F189C340D65A8C9D FOREIGN KEY (id_groups) REFERENCES `groups` (id) ON DELETE CASCADE\', '
            . '\'DO 0\')'
        );
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        // Restore the string column and backfill it from the FK before dropping
        // the folder table, so grants survive the round trip. The is_open_access
        // flag has no pre-migration home and is necessarily lost.
        $this->addSql('ALTER TABLE assets_folders_groups ADD folder VARCHAR(100) NULL');
        $this->addSql('UPDATE assets_folders_groups afg INNER JOIN assets_folders af ON af.id = afg.id_assets_folders SET afg.folder = af.folder');

        $this->addSql('ALTER TABLE assets_folders_groups DROP FOREIGN KEY FK_F189C34051B7E443');

        // Drop the id_groups FK if present, so the table returns to the state
        // Version20260722092220's own down() expects (it drops this FK itself).
        $this->addSql(
            'SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = \'assets_folders_groups\' '
            . 'AND CONSTRAINT_NAME = \'FK_F189C340D65A8C9D\' AND CONSTRAINT_TYPE = \'FOREIGN KEY\')'
        );
        $this->addSql(
            'SET @sql := IF(@fk_exists = 1, '
            . '\'ALTER TABLE assets_folders_groups DROP FOREIGN KEY FK_F189C340D65A8C9D\', '
            . '\'DO 0\')'
        );
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql('DROP INDEX IDX_F189C34051B7E443 ON assets_folders_groups');
        $this->addSql('DROP INDEX uq_assets_folders_groups_folder_group ON assets_folders_groups');
        $this->addSql('ALTER TABLE assets_folders_groups DROP id_assets_folders');
        $this->addSql('ALTER TABLE assets_folders_groups MODIFY folder VARCHAR(100) NOT NULL');

        $this->addSql('CREATE INDEX idx_assets_folders_groups_folder ON assets_folders_groups (folder)');
        $this->addSql('CREATE UNIQUE INDEX uq_assets_folders_groups_folder_group ON assets_folders_groups (folder, id_groups)');

        $this->addSql('DROP TABLE assets_folders');
    }
}
