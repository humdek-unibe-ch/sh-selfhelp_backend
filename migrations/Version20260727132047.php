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
 * Add the `imported` userStatus lookup so CSV-imported users get a dedicated
 * status. Passive marker only (no auth impact); the activation invite later
 * moves the user imported -> invited -> active. Idempotent via INSERT IGNORE.
 */
final class Version20260727132047 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the `imported` userStatus lookup for CSV-imported users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `lookups` (`type_code`, `lookup_code`, `lookup_value`, `lookup_description`) VALUES
            ('userStatus', 'imported', 'Imported', 'This user was created by a CSV import and has not been invited or activated yet.')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM `lookups` WHERE `type_code` = 'userStatus' AND `lookup_code` = 'imported'");
    }
}
