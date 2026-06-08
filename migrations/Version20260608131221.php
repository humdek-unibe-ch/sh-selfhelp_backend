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
 * Increase users.token column length from 32 to 64 to match password_reset_token
 * and accommodate longer validation tokens.
 */
final class Version20260608131221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Increase users.token column length from 32 to 64.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users CHANGE token token VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users CHANGE token token VARCHAR(32) DEFAULT NULL');
    }
}
