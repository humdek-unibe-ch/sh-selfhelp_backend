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
 * Admin route + permission for curating a data column's display name (issue #56).
 *
 * Companion to `Version20260626120120` (which split `data_cols.name` into the
 * immutable `field_key` + mutable `display_name`). This adds the admin endpoint
 * that lets an operator set a human-facing label for a column WITHOUT changing
 * the storage key:
 *
 *   PATCH /cms-api/v1/admin/data/tables/{tableName}/columns/display-name
 *   body: { "fieldKey": "mood_score", "displayName": "Daily mood" }
 *
 * The opaque (possibly dotted) field key travels in the BODY, never the URL.
 * A dedicated `admin.data.update_columns` permission gates it (granted to the
 * admin role), mirroring `admin.data.delete_columns`.
 */
final class Version20260626121351 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ROUTE_NAME = 'admin_data_table_columns_display_name_patch_v1';
    private const PERMISSION = 'admin.data.update_columns';

    public function getDescription(): string
    {
        return 'Add admin.data.update_columns permission + PATCH /admin/data/tables/{tableName}/columns/display-name route.';
    }

    public function up(Schema $schema): void
    {
        // Permission + grant to the admin role.
        $this->addSql("INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES ('" . self::PERMISSION . "', 'Can rename data table column display labels')");
        $this->addSql(
            'INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`) '
            . "SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = 'admin' WHERE p.name = ?",
            [self::PERMISSION]
        );

        // Route (idempotent: drop any stale row first, then insert).
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [self::ROUTE_NAME, self::VERSION]);
        $this->addSql(
            'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
            [
                self::ROUTE_NAME,
                self::VERSION,
                '/admin/data/tables/{tableName}/columns/display-name',
                'App\\Controller\\Api\\V1\\Admin\\AdminDataController::updateColumnDisplayName',
                'PATCH',
                '{"tableName": "[A-Za-z0-9_-]+"}',
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

        $this->addSql("DELETE rpr FROM `rel_permissions_roles` rpr JOIN `permissions` p ON p.id = rpr.id_permissions WHERE p.name = '" . self::PERMISSION . "'");
        $this->addSql("DELETE FROM `permissions` WHERE name = '" . self::PERMISSION . "'");
    }
}
