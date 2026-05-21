<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521123813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align users.last_login with the Doctrine datetime_immutable mapping by changing the column from DATE to DATETIME.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users MODIFY last_login DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users MODIFY last_login DATE DEFAULT NULL');
    }
}
