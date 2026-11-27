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
 * Live sync (2026-11-20): `groups.acl_version` and `roles.acl_version`, the
 * same opaque token `users.acl_version` is. A group or role change bumps its own
 * value once instead of every member's or holder's `users.acl_version`; the
 * access stamp (`X-Acl-Stamp`) folds the user's, groups' and roles' values.
 *
 * Generated with `doctrine:migrations:diff` (schema diff only; both columns are
 * nullable, so no data step is needed).
 */
final class Version20261120103240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add acl_version to groups and roles (live sync, access stamp)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `groups` ADD acl_version VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE roles ADD acl_version VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `groups` DROP acl_version');
        $this->addSql('ALTER TABLE roles DROP acl_version');
    }
}
