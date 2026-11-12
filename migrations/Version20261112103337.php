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
 * Assets library: file type, duplicate warning, folders that are only deleted
 * when empty, folder access, and an admin role that is implicit.
 *
 * - `assets.extension`: the checked extension. The stored file is named by its
 *   uuid and has none, and sniffing cannot tell a CSV from text or a DOCX from
 *   a ZIP, so the extension is what gives the right type and download name.
 * - `assets.content_hash` (SHA-256, indexed): lets an upload report identical
 *   assets that already exist. Nothing is refused.
 * - `assets_folders.id_parent` becomes RESTRICT: only an empty folder is
 *   deleted, so a folder can no longer take its subfolders with it.
 * - Route `POST /admin/asset-folders/{folderId}/access`: one-time apply of
 *   groups and/or managing roles to every asset in a folder and its subfolders.
 * - The admin role manages every asset without a grant, so its grants on
 *   assets are removed (the code no longer lists or writes them). `down()`
 *   cannot bring them back; it does not need to, the role still manages
 *   everything through its short-circuit.
 *
 * Existing assets keep a NULL extension and hash until their file is replaced;
 * the platform is unreleased, so there is no backfill.
 */
final class Version20261112103337 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ROUTE_NAME = 'admin_asset_folders_access';
    private const ROUTE_PATH = '/admin/asset-folders/{folderId}/access';
    private const ROUTE_CONTROLLER = 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::setAssetFolderAccess';
    private const ROUTE_PERMISSION = 'admin.asset.update';

    public function getDescription(): string
    {
        return 'Assets library: extension and content hash, folders deleted only when empty, folder access route, implicit admin role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assets ADD extension VARCHAR(20) DEFAULT NULL, ADD content_hash CHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_assets_content_hash ON assets (content_hash)');
        $this->addSql('ALTER TABLE assets_folders DROP FOREIGN KEY `FK_6CD159451BB9D5A2`');
        $this->addSql('ALTER TABLE assets_folders ADD CONSTRAINT FK_6CD159451BB9D5A2 FOREIGN KEY (id_parent) REFERENCES assets_folders (id) ON DELETE RESTRICT');

        // The folder access route, linked to its permission.
        $this->addSql(
            'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
            [self::ROUTE_NAME, self::VERSION, self::ROUTE_PATH, self::ROUTE_CONTROLLER, 'POST', '{"folderId":"[0-9]+"}']
        );
        $this->addSql(
            'INSERT INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
            . 'SELECT ar.id, p.id FROM api_routes ar INNER JOIN permissions p ON p.name = ? '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            [self::ROUTE_PERMISSION, self::ROUTE_NAME, self::VERSION]
        );

        // The admin role is implicit: drop its asset grants. It is found by
        // name, like DataAccessSecurityService::userHasAdminRole() does (F10).
        $this->addSql(
            'DELETE rda FROM role_data_access rda '
            . 'INNER JOIN roles r ON r.id = rda.id_roles AND r.name = ? '
            . "INNER JOIN lookups l ON l.id = rda.id_resource_types AND l.type_code = 'resourceTypes' AND l.lookup_code = 'asset'",
            ['admin']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rarp FROM rel_api_routes_permissions rarp '
            . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            [self::ROUTE_NAME, self::VERSION]
        );
        $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [self::ROUTE_NAME, self::VERSION]);

        // Folders deleted while the FK was RESTRICT cannot have had children,
        // so putting CASCADE back loses nothing.
        $this->addSql('ALTER TABLE assets_folders DROP FOREIGN KEY FK_6CD159451BB9D5A2');
        $this->addSql('ALTER TABLE assets_folders ADD CONSTRAINT `FK_6CD159451BB9D5A2` FOREIGN KEY (id_parent) REFERENCES assets_folders (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('DROP INDEX idx_assets_content_hash ON assets');
        $this->addSql('ALTER TABLE assets DROP extension, DROP content_hash');
    }
}
