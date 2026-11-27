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
 * F280: remove the grants of resources that no longer exist.
 *
 * `role_data_access` has no foreign key to the page, group, data table or
 * asset a grant points at, and deleting one of them never removed its grants
 * (only asset delete did). Rule 4.6f counts every row of a role, so these
 * stale rows counted as rights the role holds. From now on the delete paths
 * remove the grants themselves (`ResourceGrantService::revokeAll()`); this
 * data migration clears what earlier deletes left behind.
 *
 * Only the four core resource types are touched: the rows of a plugin type
 * go with the type's lookup entry when the plugin is purged.
 * Data-only, generated with `doctrine:migrations:generate` (no schema diff).
 */
final class Version20261126100652 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete role_data_access rows of pages, groups, data tables and assets that no longer exist (F280)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE rda FROM role_data_access rda
            INNER JOIN lookups lt ON lt.id = rda.id_resource_types AND lt.type_code = 'resourceTypes'
            WHERE (lt.lookup_code = 'pages' AND NOT EXISTS (SELECT 1 FROM pages p WHERE p.id = rda.resource_id))
               OR (lt.lookup_code = 'group' AND NOT EXISTS (SELECT 1 FROM `groups` g WHERE g.id = rda.resource_id))
               OR (lt.lookup_code = 'data_table' AND NOT EXISTS (SELECT 1 FROM data_tables d WHERE d.id = rda.resource_id))
               OR (lt.lookup_code = 'asset' AND NOT EXISTS (SELECT 1 FROM assets a WHERE a.id = rda.resource_id))
        SQL);
    }

    public function down(Schema $schema): void
    {
        // The removed rows pointed at resources that do not exist, so there is nothing to restore.
    }
}
