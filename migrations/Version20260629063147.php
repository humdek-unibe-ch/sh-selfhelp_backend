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
 * Add GET /admin/sections/{section_id}/data-variables (issue #56).
 *
 * The interpolation variable picker used to ride along in the cached getSection
 * payload (SECTION scope), so a data column created by a later form submission
 * (which only invalidates the DATA_TABLE scope) did not appear in the picker
 * until the section was re-saved. The picker now has its own endpoint that the
 * CMS section inspector fetches fresh; DataVariableResolver assembles it from
 * its granular SECTION (hierarchy) + DATA_TABLE (columns) caches, so adding a
 * column or editing data_config both refresh it immediately.
 *
 * Read-only section data, so it reuses the same `admin.page.read` permission as
 * the sibling `admin_sections_get` route.
 */
final class Version20260629063147 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ROUTE_NAME = 'admin_sections_data_variables_get';
    private const PERMISSION = 'admin.page.read';

    public function getDescription(): string
    {
        return 'Add admin_sections_data_variables_get route: GET /admin/sections/{section_id}/data-variables (permission admin.page.read).';
    }

    public function up(Schema $schema): void
    {
        // Route (idempotent: drop any stale row first, then insert).
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [self::ROUTE_NAME, self::VERSION]);
        $this->addSql(
            'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
            [
                self::ROUTE_NAME,
                self::VERSION,
                '/admin/sections/{section_id}/data-variables',
                'App\\Controller\\Api\\V1\\Admin\\AdminSectionController::getSectionDataVariables',
                'GET',
                '{"section_id": "[0-9]+"}',
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
