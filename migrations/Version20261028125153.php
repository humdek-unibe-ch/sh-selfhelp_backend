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
 * Register the "download all" zip routes for form uploads:
 *
 *   GET /cms-api/v1/forms/files/{record_id}/{field}/zip
 *       Every file of one multi-file field. No route permission, like
 *       `form_files_serve`: FormFileController allows the uploader or users with
 *       READ on the form's data table (anyone when `files_public` is on).
 *   GET /cms-api/v1/admin/data/tables/{tableName}/files?record_ids=...
 *       The files of the rows an admin is looking at, as one zip. Requires
 *       `admin.data.read` (like the CSV/JSON export) plus table READ.
 */
final class Version20261028125153 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const RECORD_ZIP = 'form_files_zip';
    private const TABLE_ZIP = 'admin_data_table_files_zip';

    public function getDescription(): string
    {
        return 'Register the form upload zip routes (per record field and per admin data table).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM api_routes WHERE route_name IN (?, ?) AND version = ?',
            [self::RECORD_ZIP, self::TABLE_ZIP, self::VERSION]
        );
        $insert = 'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)';
        $this->addSql($insert, [
            self::RECORD_ZIP,
            self::VERSION,
            '/forms/files/{record_id}/{field}/zip',
            'App\\Controller\\Api\\V1\\Frontend\\FormFileController::zip',
            'GET',
            '{"record_id":"\\\\d+","field":"[a-zA-Z0-9_-]+"}',
        ]);
        $this->addSql($insert, [
            self::TABLE_ZIP,
            self::VERSION,
            '/admin/data/tables/{tableName}/files',
            'App\\Controller\\Api\\V1\\Admin\\AdminDataController::downloadTableFiles',
            'GET',
            null,
        ]);
        $this->addSql(
            'INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
            . 'SELECT ar.id, p.id FROM api_routes ar JOIN permissions p ON p.name = ? '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            ['admin.data.read', self::TABLE_ZIP, self::VERSION]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rarp FROM rel_api_routes_permissions rarp '
            . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
            . 'WHERE ar.route_name IN (?, ?) AND ar.version = ?',
            [self::RECORD_ZIP, self::TABLE_ZIP, self::VERSION]
        );
        $this->addSql(
            'DELETE FROM api_routes WHERE route_name IN (?, ?) AND version = ?',
            [self::RECORD_ZIP, self::TABLE_ZIP, self::VERSION]
        );
    }
}
