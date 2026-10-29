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
 * Register the access-checked form file delivery route:
 *   GET /cms-api/v1/forms/files/{record_id}/{field}
 *
 * Form uploads moved from public/uploads/form-files/ (served raw by the web
 * server, readable by anyone) to var/form-files/. Like `assets_serve`, the route
 * has no permission link: FormFileController allows the record owner or a user
 * with READ on the form's data table.
 */
final class Version20261028095413 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ROUTE_NAME = 'form_files_serve';

    public function getDescription(): string
    {
        return 'Register the access-checked form file delivery route (/cms-api/v1/forms/files/{record_id}/{field}).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
            [self::ROUTE_NAME, self::VERSION]
        );
        $this->addSql(
            'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
            [
                self::ROUTE_NAME,
                self::VERSION,
                '/forms/files/{record_id}/{field}',
                'App\\Controller\\Api\\V1\\Frontend\\FormFileController::serve',
                'GET',
                '{"record_id":"\\\\d+","field":"[a-zA-Z0-9_-]+"}',
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
            [self::ROUTE_NAME, self::VERSION]
        );
    }
}
