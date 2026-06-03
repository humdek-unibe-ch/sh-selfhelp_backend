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
 * Adds admin registration code permissions, API routes, and route-permission links.
 */
final class Version20260529074436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin.registration_code permissions and API routes (GET, POST).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `permissions` (`name`, `description`)
            VALUES
                ('admin.registration_code.read',   'Can read registration codes'),
                ('admin.registration_code.create', 'Can create registration codes')
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES
                ('admin_registration_codes_get_all', 'v1', 'GET',  '/admin/registration-codes', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminRegistrationCodeController::getAll', NULL, NULL),
                ('admin_registration_codes_create',  'v1', 'POST', '/admin/registration-codes', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminRegistrationCodeController::create', NULL, NULL)
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`)
            SELECT p.id, r.id
            FROM `permissions` p, `roles` r
            WHERE p.`name` IN (
                'admin.registration_code.read',
                'admin.registration_code.create'
            ) AND r.`name` = 'admin'
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.`name` = 'admin.registration_code.read'
            WHERE ar.`route_name` = 'admin_registration_codes_get_all' AND ar.`version` = 'v1'
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.`name` = 'admin.registration_code.create'
            WHERE ar.`route_name` = 'admin_registration_codes_create' AND ar.`version` = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE rarp FROM `rel_api_routes_permissions` rarp
            JOIN `api_routes` ar ON ar.id = rarp.id_api_routes
            WHERE ar.`route_name` IN (
                'admin_registration_codes_get_all',
                'admin_registration_codes_create'
            ) AND ar.`version` = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE `route_name` IN (
                'admin_registration_codes_get_all',
                'admin_registration_codes_create'
            ) AND `version` = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE rpr FROM `rel_permissions_roles` rpr
            JOIN `permissions` p ON p.id = rpr.id_permissions
            WHERE p.`name` IN (
                'admin.registration_code.read',
                'admin.registration_code.create'
            )
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `permissions`
            WHERE `name` IN (
                'admin.registration_code.read',
                'admin.registration_code.create'
            )
        SQL);
    }
}
