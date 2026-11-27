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
 * Removes the permission `admin.page.insert` ("Can insert content into pages").
 * No API route and no check ever used it: `admin.page.update` covers editing a
 * page and adding, moving and removing its sections. Decided 2026-11-19 (user:
 * "Remove it (Recommended)").
 *
 * Data-only — generated with `doctrine:migrations:generate` because there is no
 * schema diff. down() brings the permission back for the superuser role only:
 * which other roles held it is not kept (it granted nothing).
 */
final class Version20261119150228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the unused permission admin.page.insert';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE rpr FROM rel_permissions_roles rpr
            INNER JOIN permissions p ON p.id = rpr.id_permissions
            WHERE p.name = 'admin.page.insert'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE rap FROM rel_api_routes_permissions rap
            INNER JOIN permissions p ON p.id = rap.id_permissions
            WHERE p.name = 'admin.page.insert'
            SQL);
        $this->addSql("DELETE FROM permissions WHERE name = 'admin.page.insert'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "INSERT IGNORE INTO permissions (name, description)"
            . " VALUES ('admin.page.insert', 'Can insert content into pages')"
        );
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles)
            SELECT p.id, r.id
            FROM permissions p
            CROSS JOIN roles r
            WHERE r.is_superuser = 1
              AND p.name = 'admin.page.insert'
            SQL);
    }
}
