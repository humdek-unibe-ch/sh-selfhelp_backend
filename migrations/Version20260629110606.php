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
 * Add GET /admin/interpolation/variables (issue #56 v2).
 *
 * One context-aware endpoint backs the whole CMS interpolation `{{ }}` picker
 * (section, page/config, action, global) so the variable catalog is defined once
 * and only ever offers tokens that interpolate at runtime. Read-only admin
 * metadata, so it reuses the same `admin.page.read` permission as the sibling
 * section data-variables route.
 */
final class Version20260629110606 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ROUTE_NAME = 'admin_interpolation_variables_get';
    private const PERMISSION = 'admin.page.read';

    public function getDescription(): string
    {
        return 'Add admin_interpolation_variables_get route: GET /admin/interpolation/variables (permission admin.page.read).';
    }

    public function up(Schema $schema): void
    {
        // Route (idempotent: drop any stale row first, then insert).
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [self::ROUTE_NAME, self::VERSION]);
        $this->addSql(
            'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
            [
                self::ROUTE_NAME,
                self::VERSION,
                '/admin/interpolation/variables',
                'App\\Controller\\Api\\V1\\Admin\\AdminInterpolationController::getVariables',
                'GET',
            ]
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
            . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? WHERE ar.route_name = ? AND ar.version = ?',
            [self::PERMISSION, self::ROUTE_NAME, self::VERSION]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rarp FROM `rel_api_routes_permissions` rarp JOIN `api_routes` ar ON ar.id = rarp.id_api_routes WHERE ar.route_name = ? AND ar.version = ?',
            [self::ROUTE_NAME, self::VERSION]
        );
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [self::ROUTE_NAME, self::VERSION]);
    }
}
