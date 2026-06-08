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
 * Add dedicated password-reset token storage on `users`.
 *
 * Recovery tokens move off the shared `users.token` (account-validation) column
 * into `password_reset_token` + a short UTC `password_reset_expires_at` expiry
 * so an outstanding invite and a reset request can no longer overwrite each
 * other, and leaked reset links expire after one hour (issue #32).
 */
final class Version20260608101552 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dedicated password_reset_token + password_reset_expires_at columns to users (issue #32).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD password_reset_token VARCHAR(64) DEFAULT NULL, ADD password_reset_expires_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP password_reset_token, DROP password_reset_expires_at');
    }
}
