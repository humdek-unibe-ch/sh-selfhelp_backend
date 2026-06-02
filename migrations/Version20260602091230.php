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
 * Removes the registration code delete route and its permission.
 */
final class Version20260602091230 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove admin_registration_codes_delete route and admin.registration_code.delete permission.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE rarp FROM `rel_api_routes_permissions` rarp
            JOIN `api_routes` ar ON ar.id = rarp.id_api_routes
            WHERE ar.`route_name` = 'admin_registration_codes_delete' AND ar.`version` = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE `route_name` = 'admin_registration_codes_delete' AND `version` = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE rpr FROM `rel_permissions_roles` rpr
            JOIN `permissions` p ON p.id = rpr.id_permissions
            WHERE p.`name` = 'admin.registration_code.delete'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `permissions`
            WHERE `name` = 'admin.registration_code.delete'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException();
    }
}
