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
 * Revision source `plugin` (contract C20): the door of a record written by a
 * plugin through `PluginRecordWriterInterface::saveFor()`, on behalf of a user
 * (a therapist writing for a participant) or of the system (a job).
 *
 * Only a lookup row is added (`dataRevisionSources`); no data is converted.
 * Going back re-points the revisions that used the door to `system` (the
 * column is NOT NULL, and history is never deleted) and removes the row.
 * Hand-filled migration generated with `doctrine:migrations:generate`.
 */
final class Version20261126125239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the revision source plugin (lookup dataRevisionSources, C20).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES "
            . "('dataRevisionSources', 'plugin', 'Plugin', 'Written by a plugin, for a user it manages or for the system')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE data_revisions SET id_sources = (SELECT id FROM lookups WHERE type_code = 'dataRevisionSources' AND lookup_code = 'system') "
            . "WHERE id_sources = (SELECT id FROM lookups WHERE type_code = 'dataRevisionSources' AND lookup_code = 'plugin')"
        );
        $this->addSql("DELETE FROM lookups WHERE type_code = 'dataRevisionSources' AND lookup_code = 'plugin'");
    }
}
