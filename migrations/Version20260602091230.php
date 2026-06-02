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
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `permissions` (`name`, `description`)
            VALUES ('admin.registration_code.delete', 'Can delete registration codes')
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES
                ('admin_registration_codes_delete', 'v1', 'DELETE', '/admin/registration-codes/{code}', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminRegistrationCodeController::delete', NULL, NULL)
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`)
            SELECT p.id, r.id
            FROM `permissions` p, `roles` r
            WHERE p.`name` = 'admin.registration_code.delete' AND r.`name` = 'admin'
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.`name` = 'admin.registration_code.delete'
            WHERE ar.`route_name` = 'admin_registration_codes_delete' AND ar.`version` = 'v1'
        SQL);
    }
}
