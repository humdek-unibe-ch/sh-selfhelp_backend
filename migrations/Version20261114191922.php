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
 * Section-scoped current-file route for list / data-view / entry-list /
 * entry-record records (F165).
 *
 *   GET /cms-api/v1/lists/{section_id}/records/{record_id}/files/{field_key}
 *
 * No route permission: access is decided per request (page ACL, list boundary,
 * files_public). Same pattern as the other form_list_* routes.
 */
final class Version20261114191922 extends AbstractMigration
{
    private const API_VERSION = 'v1';
    private const ROUTE = 'form_list_current_file';
    private const PATH = '/lists/{section_id}/records/{record_id}/files/{field_key}';
    private const CONTROLLER = 'App\\Controller\\Api\\V1\\Frontend\\FormListController::currentFile';

    public function getDescription(): string
    {
        return 'Register GET /lists/{section}/records/{record}/files/{field} for page-scoped current file delivery.';
    }

    public function up(Schema $schema): void
    {
        $requirements = json_encode([
            'section_id' => '\\d+',
            'record_id' => '\\d+',
            'field_key' => '[A-Za-z0-9_]+',
        ], JSON_THROW_ON_ERROR);

        $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [self::ROUTE, self::API_VERSION]);
        $this->addSql(
            'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
            [self::ROUTE, self::API_VERSION, self::PATH, self::CONTROLLER, 'GET', $requirements],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [self::ROUTE, self::API_VERSION]);
    }
}
