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
 * Adds single-table and bulk-ZIP export API routes under admin.data.read permission.
 */
final class Version20260603092955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin data export routes: GET /admin/data/tables/{tableName}/export and POST /admin/data/tables/bulk-export.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES
                ('admin_data_table_export_get_v1',       'v1', 'GET',  '/admin/data/tables/{tableName}/export', 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminDataController::exportTable',  NULL, NULL),
                ('admin_data_tables_bulk_export_post_v1', 'v1', 'POST', '/admin/data/tables/bulk-export',        'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminDataController::exportTables', NULL, NULL)
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.`name` = 'admin.data.read'
            WHERE ar.`route_name` IN ('admin_data_table_export_get_v1', 'admin_data_tables_bulk_export_post_v1')
              AND ar.`version` = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE rarp FROM `rel_api_routes_permissions` rarp
            JOIN `api_routes` ar ON ar.id = rarp.id_api_routes
            WHERE ar.`route_name` IN ('admin_data_table_export_get_v1', 'admin_data_tables_bulk_export_post_v1')
              AND ar.`version` = 'v1'
        SQL);

        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE `route_name` IN ('admin_data_table_export_get_v1', 'admin_data_tables_bulk_export_post_v1')
              AND `version` = 'v1'
        SQL);
    }
}
