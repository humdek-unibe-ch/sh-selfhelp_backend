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
 * Remove the known-dead SelfHelp 1 leftovers (finding F38, decided 2026-11-12):
 *
 * - table `libraries` (23 SH1 rows seeded by `Version20260501000100`; nothing
 *   in SH2 reads it). The "about the platform" block is generated from what is
 *   installed instead of a stored list.
 * - table `rel_sections_navigation` (0 rows, no code).
 * - column `users.intern` (never true: no code or fixture sets it, and the
 *   `intern = false` filters it fed were always true).
 * - the two `hookTypes` lookup rows (the SH1 `hooks` table is long gone).
 *
 * `up()` first checks that no foreign key column still points at a `hookTypes`
 * row and stops the migration if one does, so nothing is deleted by cascade.
 *
 * `down()` brings back the structure and the two lookup rows. It does not
 * restore the 23 SH1 `libraries` rows; they were never read.
 */
final class Version20261112163406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the dead SH1 leftovers: libraries, rel_sections_navigation, users.intern and the hookTypes lookup rows.';
    }

    public function up(Schema $schema): void
    {
        $this->assertNothingReferencesHookTypes();

        $this->addSql("DELETE FROM lookups WHERE type_code = 'hookTypes'");
        $this->addSql('ALTER TABLE rel_sections_navigation DROP FOREIGN KEY `fk_rel_sections_navigation_id_child_section`');
        $this->addSql('ALTER TABLE rel_sections_navigation DROP FOREIGN KEY `fk_rel_sections_navigation_id_pages`');
        $this->addSql('ALTER TABLE rel_sections_navigation DROP FOREIGN KEY `fk_rel_sections_navigation_id_parent_section`');
        $this->addSql('DROP TABLE libraries');
        $this->addSql('DROP TABLE rel_sections_navigation');
        $this->addSql('ALTER TABLE users DROP intern');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD intern TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE TABLE libraries (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(250) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_general_ci`, version VARCHAR(500) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_general_ci`, license VARCHAR(1000) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_general_ci`, comments VARCHAR(1000) CHARACTER SET utf8mb3 DEFAULT NULL COLLATE `utf8mb3_general_ci`, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE rel_sections_navigation (id_parent_section INT NOT NULL, id_child_section INT NOT NULL, id_pages INT NOT NULL, position INT NOT NULL, INDEX idx_rel_sections_navigation_id_child_section (id_child_section), INDEX idx_rel_sections_navigation_id_pages (id_pages), INDEX idx_rel_sections_navigation_id_parent_section (id_parent_section), PRIMARY KEY (id_parent_section, id_child_section, id_pages)) DEFAULT CHARACTER SET utf8mb3 COLLATE `utf8mb3_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE rel_sections_navigation ADD CONSTRAINT `fk_rel_sections_navigation_id_child_section` FOREIGN KEY (id_child_section) REFERENCES sections (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rel_sections_navigation ADD CONSTRAINT `fk_rel_sections_navigation_id_pages` FOREIGN KEY (id_pages) REFERENCES pages (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rel_sections_navigation ADD CONSTRAINT `fk_rel_sections_navigation_id_parent_section` FOREIGN KEY (id_parent_section) REFERENCES sections (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql(
            'INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES '
            . "('hookTypes', 'hook_overwrite_return', 'Overwrite return value', 'On execution it overwrites the return value of the function'), "
            . "('hookTypes', 'hook_on_function_execute', 'On function execute', 'On function execute trigger event and we can execute another hook function')"
        );
    }

    /**
     * Stop when any foreign key column that points at `lookups.id` still holds
     * the id of a `hookTypes` row. Every such column is found in
     * `information_schema`, so a column added later is covered too.
     */
    private function assertNothingReferencesHookTypes(): void
    {
        /** @var list<array{tbl: string, col: string}> $columns */
        $columns = $this->connection->fetchAllAssociative(
            'SELECT table_name AS tbl, column_name AS col '
            . 'FROM information_schema.key_column_usage '
            . "WHERE table_schema = DATABASE() AND referenced_table_name = 'lookups' AND referenced_column_name = 'id'"
        );

        foreach ($columns as $column) {
            $count = $this->connection->fetchOne(sprintf(
                "SELECT COUNT(*) FROM %s WHERE %s IN (SELECT id FROM lookups WHERE type_code = 'hookTypes')",
                $this->connection->quoteSingleIdentifier($column['tbl']),
                $this->connection->quoteSingleIdentifier($column['col'])
            ));

            $this->abortIf(
                (int) (is_scalar($count) ? $count : 0) > 0,
                sprintf('Refusing to delete the hookTypes lookups: %s.%s still points at one.', $column['tbl'], $column['col'])
            );
        }
    }
}
