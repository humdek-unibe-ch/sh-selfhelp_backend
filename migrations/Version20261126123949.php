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
 * Admin route for where a plugin resource is used (contract C7):
 * `GET /admin/resources/{type}/{id}/usage`, permission `admin.access`.
 *
 * Generated empty with `doctrine:migrations:generate` and filled by hand
 * (wave 2 of task `2026-11-25-plugin-resource-hooks`, part 3).
 */
final class Version20261126123949 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ROUTE = 'admin_resources_usage_get';
    private const PATH = '/admin/resources/{type}/{id}/usage';
    private const CONTROLLER = 'App\\Controller\\Api\\V1\\Admin\\AdminResourceUsageController::getUsage';
    private const METHOD = 'GET';
    private const PERMISSION = 'admin.access';
    private const REQUIREMENTS = '{"type":"[a-z][a-z0-9_]{1,63}","id":"[0-9]+"}';
    private const PARAMS = '{"type":{"in":"path","required":true},"id":{"in":"path","required":true}}';

    public function getDescription(): string
    {
        return 'Register admin_resources_usage_get (plugin resource where-used, contract C7).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, NULL)',
            [self::ROUTE, self::VERSION, self::PATH, self::CONTROLLER, self::METHOD, self::REQUIREMENTS, self::PARAMS]
        );
        $this->addSql(
            'INSERT INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
            . 'SELECT ar.id, p.id FROM api_routes ar INNER JOIN permissions p ON p.name = ? '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            [self::PERMISSION, self::ROUTE, self::VERSION]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rarp FROM rel_api_routes_permissions rarp '
            . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            [self::ROUTE, self::VERSION]
        );
        $this->addSql(
            'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
            [self::ROUTE, self::VERSION]
        );
    }
}
