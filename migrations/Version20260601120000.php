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
 * Replaces the single-code create route with export (GET) and generate (POST) routes.
 */
final class Version20260601120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace registration_codes_create route with export and generate routes.';
    }

    public function up(Schema $schema): void
    {
        // Remove the old single-code create route and its permission link
        $this->addSql(<<<SQL
            DELETE rarp FROM `rel_api_routes_permissions` rarp
            JOIN `api_routes` ar ON ar.id = rarp.id_api_routes
            WHERE ar.`route_name` = 'admin_registration_codes_create' AND ar.`version` = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE `route_name` = 'admin_registration_codes_create' AND `version` = 'v1'
        SQL);

        // Add export and generate routes
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES
                ('admin_registration_codes_export',   'v1', 'GET',  '/admin/registration-codes/export',   'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminRegistrationCodeController::export',   NULL, NULL),
                ('admin_registration_codes_generate', 'v1', 'POST', '/admin/registration-codes/generate', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminRegistrationCodeController::generate', NULL, NULL)
        SQL);

        // Link export → read permission
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.`name` = 'admin.registration_code.read'
            WHERE ar.`route_name` = 'admin_registration_codes_export' AND ar.`version` = 'v1'
        SQL);

        // Link generate → create permission
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.`name` = 'admin.registration_code.create'
            WHERE ar.`route_name` = 'admin_registration_codes_generate' AND ar.`version` = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Remove export and generate routes
        $this->addSql(<<<SQL
            DELETE rarp FROM `rel_api_routes_permissions` rarp
            JOIN `api_routes` ar ON ar.id = rarp.id_api_routes
            WHERE ar.`route_name` IN ('admin_registration_codes_export', 'admin_registration_codes_generate')
              AND ar.`version` = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE `route_name` IN ('admin_registration_codes_export', 'admin_registration_codes_generate')
              AND `version` = 'v1'
        SQL);

        // Restore the single-code create route
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES
                ('admin_registration_codes_create', 'v1', 'POST', '/admin/registration-codes', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminRegistrationCodeController::create', NULL, NULL)
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.`name` = 'admin.registration_code.create'
            WHERE ar.`route_name` = 'admin_registration_codes_create' AND ar.`version` = 'v1'
        SQL);
    }
}
