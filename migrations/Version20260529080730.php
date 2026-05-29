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
 * Aligns the `plugins.signature_ed25519` column with the
 * `App\Entity\Plugin\Plugin` mapping, which declares the column comment
 * "Base64 Ed25519 detached signature of the canonical signedPayload".
 *
 * The column was created without that comment in
 * Version20260522062453, so `doctrine:schema:validate` reported the
 * database as out of sync with the entity mapping. Adding the comment
 * here makes the (strict) schema validation pass in CI and on existing
 * installs without altering any data.
 */
final class Version20260529080730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the entity-declared comment to plugins.signature_ed25519 so the schema matches the mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE plugins CHANGE signature_ed25519 signature_ed25519 VARCHAR(512) DEFAULT NULL COMMENT 'Base64 Ed25519 detached signature of the canonical signedPayload'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plugins CHANGE signature_ed25519 signature_ed25519 VARCHAR(512) DEFAULT NULL');
    }
}
