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
 * "Create list + detail pages" CMS-in-CMS wizard API route (issue #30, Phase 6).
 *
 * Adds the single admin endpoint that powers the wizard:
 *   POST /admin/pages/cms-app -> createCmsApp (admin.page.create)
 *
 * The wizard creates pages, so it is gated by `admin.page.create`. The static
 * `/admin/pages/cms-app` path sorts before the dynamic `/admin/pages/{page_id}`
 * routes in `ApiRouteLoader`, so it is not shadowed. A DELETE-first guard plus
 * INSERT IGNORE on the permission link keep the migration idempotent.
 */
final class Version20260630094834 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ROUTE_NAME = 'admin_pages_cms_app';
    private const ROUTE_PATH = '/admin/pages/cms-app';
    private const CONTROLLER = 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::createCmsApp';
    private const PERMISSION = 'admin.page.create';
    private const PARAMS = '{"base_name": {"in": "body", "required": true, "type": "string"}, "data_table": {"in": "body", "required": true, "type": "string"}}';

    public function getDescription(): string
    {
        return 'Seed the CMS-in-CMS wizard api_route (issue #30 Phase 6): POST /admin/pages/cms-app.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [self::ROUTE_NAME, self::VERSION]);
        $this->addSql(
            'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) '
            . 'VALUES (?, ?, ?, ?, ?, NULL, ?, NULL)',
            [
                self::ROUTE_NAME,
                self::VERSION,
                self::ROUTE_PATH,
                self::CONTROLLER,
                'POST',
                self::PARAMS,
            ]
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
            . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            [self::PERMISSION, self::ROUTE_NAME, self::VERSION]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rarp FROM `rel_api_routes_permissions` rarp '
            . 'JOIN `api_routes` ar ON ar.id = rarp.id_api_routes '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            [self::ROUTE_NAME, self::VERSION]
        );
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [self::ROUTE_NAME, self::VERSION]);
    }
}
