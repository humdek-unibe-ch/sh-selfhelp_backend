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
 * Group model cleanup. A group only decides who can see a page or an asset;
 * everything else is handled by roles and permissions. The group type
 * (`groups.id_group_types` and the `groupTypes` lookups `db_role`/`group`) was
 * a leftover from the time groups also carried admin rights, so it is removed.
 *
 * The one thing the old seed hard-coded, "new public pages give `select` to the
 * default groups", becomes a yes/no flag on the group: `add_to_new_pages`. Only
 * the built-in `subject` group carries it after this migration; any number of
 * groups may carry it later.
 */
final class Version20261111082842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Groups: remove the group type (column and lookups), add the add_to_new_pages flag and set it on the subject group.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `groups` ADD add_to_new_pages TINYINT DEFAULT 0 NOT NULL, DROP id_group_types');
        $this->addSql("UPDATE `groups` SET add_to_new_pages = 1 WHERE name = 'subject'");
        $this->addSql("DELETE FROM lookups WHERE type_code = 'groupTypes'");
    }

    public function down(Schema $schema): void
    {
        // The column comes back empty: which group was `db_role` or `group`
        // is not restored (the type never had any effect).
        $this->addSql('ALTER TABLE `groups` ADD id_group_types INT DEFAULT NULL, DROP add_to_new_pages');
        $this->addSql(
            'INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES '
            . "('groupTypes', 'db_role', 'DB Role', 'In the DB role we can set up multiple types of access. It is used for specific custom roles'), "
            . "('groupTypes', 'group', 'Group', 'The group type has only `select` privileges and it is used for access to pages and condition checks')"
        );
    }
}
