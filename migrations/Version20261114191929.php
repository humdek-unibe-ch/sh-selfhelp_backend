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
 * Admin data-browser record history routes (contract 6).
 *
 * Gated by `admin.data.read` at the route; the controller also requires the
 * caller's UPDATE grant on the named data table.
 */
final class Version20261114191929 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const PERMISSION = 'admin.data.read';

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string|null}> */
    private const ROUTES = [
        [
            'admin_data_record_history',
            '/admin/data/tables/{tableName}/records/{recordId}/history',
            'App\\Controller\\Api\\V1\\Admin\\AdminDataController::recordHistory',
            'GET',
            '{"recordId":"\\\\d+"}',
        ],
        [
            'admin_data_record_version',
            '/admin/data/tables/{tableName}/records/{recordId}/versions/{version}',
            'App\\Controller\\Api\\V1\\Admin\\AdminDataController::recordVersion',
            'GET',
            '{"recordId":"\\\\d+","version":"\\\\d+"}',
        ],
        [
            'admin_data_record_compare',
            '/admin/data/tables/{tableName}/records/{recordId}/compare',
            'App\\Controller\\Api\\V1\\Admin\\AdminDataController::recordCompare',
            'GET',
            '{"recordId":"\\\\d+"}',
        ],
        [
            'admin_data_record_restore',
            '/admin/data/tables/{tableName}/records/{recordId}/restore',
            'App\\Controller\\Api\\V1\\Admin\\AdminDataController::recordRestore',
            'POST',
            '{"recordId":"\\\\d+"}',
        ],
        [
            'admin_data_record_version_file',
            '/admin/data/tables/{tableName}/records/{recordId}/versions/{version}/files/{fieldKey}',
            'App\\Controller\\Api\\V1\\Admin\\AdminDataController::recordVersionFile',
            'GET',
            '{"recordId":"\\\\d+","version":"\\\\d+","fieldKey":"[A-Za-z0-9_]+"}',
        ],
    ];

    public function getDescription(): string
    {
        return 'Register admin data browser record history routes under admin.data.read.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROUTES as [$name, $path, $controller, $method, $requirements]) {
            $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
            $this->addSql(
                'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
                [$name, self::VERSION, $path, $controller, $method, $requirements],
            );
            $this->addSql(
                'INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id FROM api_routes ar JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [self::PERMISSION, $name, self::VERSION],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $names = array_column(self::ROUTES, 0);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $this->addSql(
            "DELETE rarp FROM rel_api_routes_permissions rarp "
            . "INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes "
            . "WHERE ar.route_name IN ($placeholders) AND ar.version = ?",
            [...$names, self::VERSION],
        );
        $this->addSql(
            "DELETE FROM api_routes WHERE route_name IN ($placeholders) AND version = ?",
            [...$names, self::VERSION],
        );
    }
}
