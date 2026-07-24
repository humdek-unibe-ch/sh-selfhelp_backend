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
 * Asset folder-level ACLs and asset export/import bundle API.
 *
 * Creates the assets_folders_groups table and registers the DB-backed
 * export/import API routes:
 *   POST /admin/assets/export  -> admin.asset.read
 *   POST /admin/assets/import  -> admin.asset.create
 *
 * The folder-ACL API is group-scoped and gated by admin.group.acl; those routes
 * are registered by the follow-up migration Version20260722134223.
 */
final class Version20260722092220 extends AbstractMigration
{
    private const VERSION = 'v1';

    /**
     * @var list<array{name: string, path: string, controller: string, method: string, permission: string}>
     */
    private const ROUTES = [
        [
            'name' => 'admin_assets_export',
            'path' => '/admin/assets/export',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::exportAssets',
            'method' => 'POST',
            'permission' => 'admin.asset.read',
        ],
        [
            'name' => 'admin_assets_import',
            'path' => '/admin/assets/import',
            'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::importAssets',
            'method' => 'POST',
            'permission' => 'admin.asset.create',
        ],
    ];

    public function getDescription(): string
    {
        return 'Asset folder ACLs table and asset export/import API routes.';
    }

    public function up(Schema $schema): void
    {
        // Folder-level ACL table (auto-generated from the AssetFolderGroup entity).
        $this->addSql('CREATE TABLE assets_folders_groups (id INT AUTO_INCREMENT NOT NULL, folder VARCHAR(100) NOT NULL, access_level VARCHAR(20) NOT NULL, id_groups INT NOT NULL, INDEX IDX_F189C340D65A8C9D (id_groups), INDEX idx_assets_folders_groups_folder (folder), UNIQUE INDEX uq_assets_folders_groups_folder_group (folder, id_groups), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assets_folders_groups ADD CONSTRAINT FK_F189C340D65A8C9D FOREIGN KEY (id_groups) REFERENCES `groups` (id) ON DELETE CASCADE');

        // API routes + permission links.
        foreach (self::ROUTES as $route) {
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$route['name'], self::VERSION]
            );
            $this->addSql(
                'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
                . 'VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [$route['name'], self::VERSION, $route['path'], $route['controller'], $route['method']]
            );
            $this->addSql(
                'INSERT INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id FROM api_routes ar INNER JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$route['permission'], $route['name'], self::VERSION]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ROUTES as $route) {
            $this->addSql(
                'DELETE rarp FROM rel_api_routes_permissions rarp '
                . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$route['name'], self::VERSION]
            );
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$route['name'], self::VERSION]
            );
        }

        $this->addSql('ALTER TABLE assets_folders_groups DROP FOREIGN KEY FK_F189C340D65A8C9D');
        $this->addSql('DROP TABLE assets_folders_groups');
    }
}
