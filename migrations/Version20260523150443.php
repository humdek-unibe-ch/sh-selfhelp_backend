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
 * Adds `plugins.signing_key_id` so finalize() can persist the keyId of
 * the Ed25519 trusted key that signed the resolved source. The doctor
 * and lock-file writer surface the value so operators can detect
 * signing drift across hosts and rotate keys cleanly.
 */
final class Version20260523150443 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'plugins.signing_key_id column: persist the trusted-key id used to sign the install/update.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plugins ADD signing_key_id VARCHAR(64) DEFAULT NULL COMMENT \'Ed25519 keyId from the resolved source that signed the plugin\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plugins DROP signing_key_id');
    }
}
