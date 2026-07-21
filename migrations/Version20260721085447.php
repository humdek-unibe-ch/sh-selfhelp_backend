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
 * Adds the admin registration-codes stats route
 * (GET /admin/registration-codes/stats) and links it to the existing
 * admin.registration_code.read permission (same as the list endpoint).
 */
final class Version20260721085447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_registration_codes_stats route (GET) linked to admin.registration_code.read.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES
                ('admin_registration_codes_stats', 'v1', 'GET', '/admin/registration-codes/stats', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminRegistrationCodeController::stats', NULL, NULL)
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.`name` = 'admin.registration_code.read'
            WHERE ar.`route_name` = 'admin_registration_codes_stats' AND ar.`version` = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE rarp FROM `rel_api_routes_permissions` rarp
            JOIN `api_routes` ar ON ar.id = rarp.id_api_routes
            WHERE ar.`route_name` = 'admin_registration_codes_stats' AND ar.`version` = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE `route_name` = 'admin_registration_codes_stats' AND `version` = 'v1'
        SQL);
    }
}
