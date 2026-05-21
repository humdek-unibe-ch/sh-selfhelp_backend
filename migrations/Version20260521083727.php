<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521083727 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete section with id 69';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM sections WHERE id = 69');
    }

    public function down(Schema $schema): void {}
}
