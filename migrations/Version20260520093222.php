<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520093222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set display = 0 for system global config fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            UPDATE fields 
            SET display = 0
            WHERE name IN (
                'firebase_config',
                'default_language_id',
                'default_timezone',
                'anonymous_users'
            )
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            UPDATE fields 
            SET display = 1
            WHERE name IN (
                'firebase_config',
                'default_language_id',
                'default_timezone',
                'anonymous_users'
            )
        ");
    }
}