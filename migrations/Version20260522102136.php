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
 * Registers the `GET /admin/plugins/available` route powering the
 * admin UI's "Available" tab. The endpoint walks every enabled
 * `PluginSource` and returns the registry-advertised plugin entries
 * the host is not yet running.
 *
 * Requires the existing `admin.plugins.manage` permission.
 */
final class Version20260522102136 extends AbstractMigration
{
    private const ROUTE_NAME = 'admin_plugins_available';
    private const PERMISSION = 'admin.plugins.manage';

    public function getDescription(): string
    {
        return 'Plugin manager: register GET /admin/plugins/available route.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'admin_plugins_available',
                'v1',
                'GET',
                '/admin/plugins/available',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\Plugin\\\\AdminPluginController::listAvailable',
                NULL,
                NULL
            )
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
                SELECT ar.id, p.id
                FROM `api_routes` ar
                JOIN `permissions` p ON p.name = 'admin.plugins.manage'
                WHERE ar.route_name = 'admin_plugins_available' AND ar.version = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE rap FROM `rel_api_routes_permissions` rap
                JOIN `api_routes` ar ON ar.id = rap.id_api_routes
                WHERE ar.route_name = 'admin_plugins_available'
        SQL);
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'admin_plugins_available'");
    }
}
