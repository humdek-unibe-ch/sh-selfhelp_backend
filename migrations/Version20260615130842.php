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
 * Add the frontend-only update columns to `system_update_operations`.
 *
 * The frontend ships independently of the core, so an instance already on the
 * newest core can still update to a newer compatible frontend. A frontend-only
 * operation is a stateless container swap (no migration, no backup) performed by
 * the SelfHelp Manager. Two columns capture it:
 *
 *   - `kind`                    : 'core' (default, back-compat) or 'frontend'.
 *   - `target_frontend_version` : the frontend version a 'frontend' op targets
 *                                 (NULL for a core op — the manager resolves the
 *                                 compatible frontend itself).
 *
 * Existing rows default to `kind = 'core'`, preserving their original meaning.
 */
final class Version20260615130842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add kind + target_frontend_version to system_update_operations (frontend-only update support).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE system_update_operations "
            . "ADD kind VARCHAR(16) DEFAULT 'core' NOT NULL COMMENT 'What the operation updates: core (default) or frontend (stateless frontend-only swap)', "
            . "ADD target_frontend_version VARCHAR(50) DEFAULT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE system_update_operations DROP kind, DROP target_frontend_version');
    }
}
