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
 * Asset rework: uuid identity, per-asset view groups (none = public), CMS
 * management via role_data_access, folders for organisation only, the
 * `researcher` group and no `admin` group. Folder-era URLs in content are not
 * rewritten and files are not moved. Each step is commented in up().
 */
final class Version20261103112133 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ASSET_RESOURCE_TYPE = 'asset';
    private const UPDATE_PERMISSION = 'admin.asset.update';
    private const ACCESS_LEVEL_TYPE = 'assetFolderAccessLevels';

    /** Routes removed in up() and restored in down(). */
    private const FOLDER_ROUTES = [
        ['assets_serve', '/assets/{folder}/{filename}', 'App\\Controller\\Api\\V1\\AssetController::serve', 'GET', '{"folder":"[a-zA-Z0-9_-]+","filename":"[a-zA-Z0-9_.-]+"}', null],
        ['admin_groups_asset_acls_get', '/admin/groups/{groupId}/asset-acls', 'App\\Controller\\Api\\V1\\Admin\\AdminGroupController::getGroupAssetAcls', 'GET', null, 'admin.group.acl'],
        ['admin_groups_asset_acls_update', '/admin/groups/{groupId}/asset-acls', 'App\\Controller\\Api\\V1\\Admin\\AdminGroupController::updateGroupAssetAcls', 'PUT', null, 'admin.group.acl'],
        ['admin_assets_folders_list', '/admin/assets/folders', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::getAssetFolders', 'GET', null, 'admin.asset.read'],
        ['admin_assets_folder_open_access_update', '/admin/assets/folders/{folder}/open-access', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::updateAssetFolderOpenAccess', 'PUT', '{"folder":"[a-zA-Z0-9_-]+"}', 'admin.group.acl'],
    ];

    /** Routes added in up() and removed in down(). Permission null = public. */
    private const NEW_ROUTES = [
        ['admin_assets_update', '/admin/assets/{assetId}', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::updateAsset', 'POST', '{"assetId":"[0-9]+"}', self::UPDATE_PERMISSION],
        ['admin_assets_bulk_groups', '/admin/assets/bulk-groups', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::bulkUpdateAssetGroups', 'POST', null, self::UPDATE_PERMISSION],
        ['admin_assets_bulk_folder', '/admin/assets/bulk-folder', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::bulkMoveAssetsToFolder', 'POST', null, self::UPDATE_PERMISSION],
        ['admin_assets_bulk_delete', '/admin/assets/bulk-delete', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::bulkDeleteAssets', 'POST', null, 'admin.asset.delete'],
        ['admin_asset_folders_list', '/admin/asset-folders', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::getAssetFolders', 'GET', null, 'admin.asset.read'],
        ['admin_asset_folders_create', '/admin/asset-folders', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::saveAssetFolder', 'POST', null, 'admin.asset.create'],
        ['admin_asset_folders_update', '/admin/asset-folders/{folderId}', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::saveAssetFolder', 'PUT', '{"folderId":"[0-9]+"}', self::UPDATE_PERMISSION],
        ['admin_asset_folders_delete', '/admin/asset-folders/{folderId}', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::deleteAssetFolder', 'DELETE', '{"folderId":"[0-9]+"}', 'admin.asset.delete'],
        // Access is per asset (its groups), not per route, so this stays
        // permission-less: public assets must reach anonymous callers.
        ['assets_serve_by_uuid', '/assets/{assetUuid}', 'App\\Controller\\Api\\V1\\AssetController::serveByUuid', 'GET', '{"assetUuid":"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"}', null],
    ];

    private const CREATE_PARAMS_NEW = '{"file": {"in": "form", "required": false}, "files": {"in": "form", "required": false}, "name": {"in": "form", "required": false}, "alt": {"in": "form", "required": false}, "group_ids": {"in": "form", "required": false}, "role_ids": {"in": "form", "required": false}, "folder_id": {"in": "form", "required": false}}';
    private const CREATE_PARAMS_OLD = '{"file": {"in": "form", "required": false}, "files": {"in": "form", "required": false}, "folder": {"in": "form", "required": false}, "file_name": {"in": "form", "required": false}, "overwrite": {"in": "form", "required": false}, "file_names": {"in": "form", "required": false}}';

    /** Image style fields: field => [title, help, old help]. */
    private const IMAGE_FIELDS = [
        'img_src' => ['Image Source', 'The image to show. Pick an uploaded asset, or enter an external URL.', 'The image source. If the image is an asset simply use the full name of the asset here.'],
        'alt' => ['Alt', 'Describes the image for screen readers and is shown if the image cannot be loaded. Leave empty to use the default alt text set on the asset in Asset management; fill it to override that text on this page only.', 'The alternative text to be shown if the image cannot be loaded.'],
        'title' => ['Title', 'The text to be shown when hovering over the image.', 'The text to be shown when hovering over the image.'],
        'is_fluid' => ['Fluid', 'If enabled the image scales responsively.', 'If enabled the image scales responsively.'],
    ];

    public function getDescription(): string
    {
        return 'Assets: uuid identity (replaces file_name; the old name becomes the name label), view groups per asset (none = public), CMS management via role_data_access, default alt; folders become an organisation-only tree.';
    }

    public function up(Schema $schema): void
    {
        // 0. Groups: therapist becomes researcher (created when missing).
        $this->addSql("UPDATE `groups` SET name = 'researcher', description = 'Researchers' WHERE name = 'therapist'");
        $this->addSql(
            'INSERT INTO `groups` (name, description, id_group_types, requires_2fa) '
            . "SELECT 'researcher', 'Researchers', (SELECT id FROM lookups WHERE type_code = 'groupTypes' AND lookup_code = 'db_role' LIMIT 1), 0 FROM DUAL "
            . "WHERE NOT EXISTS (SELECT 1 FROM `groups` WHERE name = 'researcher')"
        );

        // 1. Per-asset group access.
        $this->addSql('CREATE TABLE rel_assets_groups (id_assets INT NOT NULL, id_groups INT NOT NULL, INDEX IDX_8736C2CA5FE6C863 (id_assets), INDEX IDX_8736C2CAD65A8C9D (id_groups), PRIMARY KEY (id_assets, id_groups)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE rel_assets_groups ADD CONSTRAINT FK_8736C2CA5FE6C863 FOREIGN KEY (id_assets) REFERENCES assets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rel_assets_groups ADD CONSTRAINT FK_8736C2CAD65A8C9D FOREIGN KEY (id_groups) REFERENCES `groups` (id) ON DELETE CASCADE');

        // 2. New asset columns; uuid nullable until backfilled. The file name
        //    without extension becomes the label (file_name is dropped in
        //    step 5). Read before any change: up() runs before its SQL.
        $rows = $this->connection->fetchAllAssociative('SELECT id FROM assets');
        $this->addSql('ALTER TABLE assets ADD uuid VARCHAR(36) DEFAULT NULL, ADD name VARCHAR(255) DEFAULT NULL, ADD mime_type VARCHAR(150) DEFAULT NULL, ADD size INT DEFAULT NULL, ADD alt VARCHAR(500) DEFAULT NULL, ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, ADD updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, ADD id_assets_folders INT DEFAULT NULL');
        $this->addSql("UPDATE assets SET name = IF(LOCATE('.', file_name) > 1, SUBSTRING(file_name, 1, CHAR_LENGTH(file_name) - CHAR_LENGTH(SUBSTRING_INDEX(file_name, '.', -1)) - 1), file_name)");
        foreach ($rows as $row) {
            $this->addSql('UPDATE assets SET uuid = ? WHERE id = ?', [$this->uuid(), $row['id']]);
        }
        $this->addSql('ALTER TABLE assets MODIFY uuid VARCHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_assets_uuid ON assets (uuid)');

        // 3. CMS management: resource type `asset` in role_data_access; every
        //    existing asset goes to the roles that can read assets today.
        $this->addSql(
            "INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES ('resourceTypes', ?, 'Asset', 'An uploaded asset (file)')",
            [self::ASSET_RESOURCE_TYPE]
        );
        $this->addSql(
            'INSERT INTO role_data_access (id_roles, id_resource_types, resource_id, crud_permissions) '
            . 'SELECT DISTINCT rpr.id_roles, l.id, a.id, 15 FROM assets a '
            . 'INNER JOIN permissions p ON p.name = ? '
            . 'INNER JOIN rel_permissions_roles rpr ON rpr.id_permissions = p.id '
            . "INNER JOIN lookups l ON l.type_code = 'resourceTypes' AND l.lookup_code = ?",
            ['admin.asset.read', self::ASSET_RESOURCE_TYPE]
        );

        // 4. Viewing: no groups = public. Open-access folders stay public;
        //    closed folders pass their groups on, or `researcher` when they
        //    had none, so nothing private becomes public.
        $this->addSql(
            'INSERT IGNORE INTO rel_assets_groups (id_assets, id_groups) '
            . 'SELECT a.id, afg.id_groups FROM assets a '
            . 'INNER JOIN assets_folders af ON af.folder = a.folder AND af.is_open_access = 0 '
            . 'INNER JOIN assets_folders_groups afg ON afg.id_assets_folders = af.id'
        );
        $this->addSql(
            'INSERT IGNORE INTO rel_assets_groups (id_assets, id_groups) '
            . "SELECT a.id, g.id FROM assets a INNER JOIN `groups` g ON g.name = 'researcher' "
            . 'WHERE a.folder IS NOT NULL AND NOT EXISTS (SELECT 1 FROM assets_folders af WHERE af.folder = a.folder AND af.is_open_access = 1) '
            . 'AND NOT EXISTS (SELECT 1 FROM rel_assets_groups rag WHERE rag.id_assets = a.id)'
        );

        // 5. Folders stay, for organisation only: they lose their grants and
        //    open-access flag and can nest.
        $this->addSql('ALTER TABLE assets_folders_groups DROP FOREIGN KEY `FK_F189C34050BEC532`');
        $this->addSql('ALTER TABLE assets_folders_groups DROP FOREIGN KEY `FK_F189C34051B7E443`');
        $this->addSql('ALTER TABLE assets_folders_groups DROP FOREIGN KEY `FK_F189C340D65A8C9D`');
        $this->addSql('DROP TABLE assets_folders_groups');
        $this->addSql(
            'INSERT INTO assets_folders (folder) SELECT DISTINCT a.folder FROM assets a '
            . "WHERE a.folder IS NOT NULL AND a.folder <> '' AND NOT EXISTS (SELECT 1 FROM assets_folders af WHERE af.folder = a.folder)"
        );
        $this->addSql('UPDATE assets a INNER JOIN assets_folders af ON af.folder = a.folder SET a.id_assets_folders = af.id');
        $this->addSql('DROP INDEX uq_assets_folders_folder ON assets_folders');
        $this->addSql('ALTER TABLE assets_folders CHANGE folder name VARCHAR(100) NOT NULL, DROP is_open_access, ADD id_parent INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_assets_folders_id_parent ON assets_folders (id_parent)');
        $this->addSql('ALTER TABLE assets_folders ADD CONSTRAINT FK_6CD159451BB9D5A2 FOREIGN KEY (id_parent) REFERENCES assets_folders (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_79D17D8E51B7E443 ON assets (id_assets_folders)');
        $this->addSql('ALTER TABLE assets ADD CONSTRAINT FK_79D17D8E51B7E443 FOREIGN KEY (id_assets_folders) REFERENCES assets_folders (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX uq_assets_file_name ON assets');
        $this->addSql('ALTER TABLE assets DROP folder, DROP file_name');
        $this->addSql('DELETE FROM lookups WHERE type_code = ?', [self::ACCESS_LEVEL_TYPE]);

        // 6. Routes + permission.
        foreach (self::FOLDER_ROUTES as [$name]) {
            $this->deleteRoute($name);
        }

        $this->addSql(
            "INSERT IGNORE INTO permissions (name, description) VALUES (?, 'Edit assets: rename, alt text, groups, replace the file')",
            [self::UPDATE_PERMISSION]
        );
        // Whoever could upload assets can now also edit them.
        $this->addSql(
            'INSERT IGNORE INTO rel_permissions_roles (id_permissions, id_roles) '
            . 'SELECT pu.id, rpr.id_roles FROM rel_permissions_roles rpr '
            . 'INNER JOIN permissions pc ON pc.id = rpr.id_permissions AND pc.name = ? '
            . 'INNER JOIN permissions pu ON pu.name = ?',
            ['admin.asset.create', self::UPDATE_PERMISSION]
        );

        foreach (self::NEW_ROUTES as [$name, $path, $controller, $method, $requirements, $permission]) {
            $this->insertRoute($name, $path, $controller, $method, $requirements, $permission);
        }

        $this->addSql(
            'UPDATE api_routes SET params = ? WHERE route_name = ? AND version = ?',
            [self::CREATE_PARAMS_NEW, 'admin_assets_create', self::VERSION]
        );

        // 7. Image style field labels (display only).
        foreach (self::IMAGE_FIELDS as $field => [$title, $help]) {
            $this->updateImageField($field, $title, $help);
        }

        // 8. No admin group: admin rights come from the admin role. Assets it
        //    alone guarded move to researcher first so they stay private.
        $this->addSql(
            'INSERT IGNORE INTO rel_assets_groups (id_assets, id_groups) '
            . "SELECT rag.id_assets, r.id FROM rel_assets_groups rag INNER JOIN `groups` a ON a.id = rag.id_groups AND a.name = 'admin' "
            . "INNER JOIN `groups` r ON r.name = 'researcher' "
            . 'WHERE NOT EXISTS (SELECT 1 FROM (SELECT * FROM rel_assets_groups) other WHERE other.id_assets = rag.id_assets AND other.id_groups <> a.id)'
        );
        $this->addSql("DELETE FROM `groups` WHERE name = 'admin'");
    }

    /**
     * Best-effort reverse: each asset goes back into its folder by name
     * (nesting is lost), folders get their assets' groups as read grants
     * (admin = manage) plus open access when any of their assets was public.
     * `file_name` comes back from `file_path`.
     */
    public function down(Schema $schema): void
    {
        // Memberships and ACL rows of the admin group are not restored.
        $this->addSql(
            'INSERT INTO `groups` (name, description, id_group_types, requires_2fa) '
            . "SELECT 'admin', 'Administrators', (SELECT id FROM lookups WHERE type_code = 'groupTypes' AND lookup_code = 'db_role' LIMIT 1), 0 FROM DUAL "
            . "WHERE NOT EXISTS (SELECT 1 FROM `groups` WHERE name = 'admin')"
        );
        foreach (self::IMAGE_FIELDS as $field => [, , $oldHelp]) {
            $this->updateImageField($field, null, $oldHelp);
        }
        $this->addSql(
            'UPDATE api_routes SET params = ? WHERE route_name = ? AND version = ?',
            [self::CREATE_PARAMS_OLD, 'admin_assets_create', self::VERSION]
        );
        foreach (self::NEW_ROUTES as [$name]) {
            $this->deleteRoute($name);
        }
        $this->addSql(
            'DELETE rpr FROM rel_permissions_roles rpr '
            . 'INNER JOIN permissions p ON p.id = rpr.id_permissions WHERE p.name = ?',
            [self::UPDATE_PERMISSION]
        );
        $this->addSql('DELETE FROM permissions WHERE name = ?', [self::UPDATE_PERMISSION]);
        foreach (self::FOLDER_ROUTES as [$name, $path, $controller, $method, $requirements, $permission]) {
            $this->insertRoute($name, $path, $controller, $method, $requirements, $permission);
        }

        $this->addSql(
            'INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES '
            . "(?, 'read', 'Read', 'View and download the assets in this folder.'), "
            . "(?, 'manage', 'Manage', 'View, download, and also create, import and delete in this folder. Includes read.')",
            [self::ACCESS_LEVEL_TYPE, self::ACCESS_LEVEL_TYPE]
        );

        $this->addSql(
            "DELETE rda FROM role_data_access rda INNER JOIN lookups l ON l.id = rda.id_resource_types AND l.type_code = 'resourceTypes' AND l.lookup_code = ?",
            [self::ASSET_RESOURCE_TYPE]
        );
        $this->addSql("DELETE FROM lookups WHERE type_code = 'resourceTypes' AND lookup_code = ?", [self::ASSET_RESOURCE_TYPE]);

        $this->addSql('ALTER TABLE assets ADD folder VARCHAR(100) DEFAULT NULL, ADD file_name VARCHAR(100) DEFAULT NULL');
        // `uploads/assets/<folder>/<file>` until moved, else `uploads/assets/<uuid>`.
        $this->addSql("UPDATE assets SET file_name = LEFT(SUBSTRING_INDEX(file_path, '/', -1), 100)");
        // Each asset goes back into its own folder (by name; nesting is lost).
        $this->addSql('UPDATE assets a INNER JOIN assets_folders f ON f.id = a.id_assets_folders SET a.folder = f.name');
        $this->addSql('ALTER TABLE assets DROP FOREIGN KEY FK_79D17D8E51B7E443');
        $this->addSql('DROP INDEX IDX_79D17D8E51B7E443 ON assets');
        $this->addSql('ALTER TABLE assets DROP id_assets_folders');
        $this->addSql('ALTER TABLE assets_folders DROP FOREIGN KEY FK_6CD159451BB9D5A2');
        $this->addSql('DROP TABLE assets_folders');

        $this->addSql('CREATE TABLE assets_folders (id INT AUTO_INCREMENT NOT NULL, folder VARCHAR(100) NOT NULL, is_open_access TINYINT(1) DEFAULT 0 NOT NULL, UNIQUE INDEX uq_assets_folders_folder (folder), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE assets_folders_groups (id INT AUTO_INCREMENT NOT NULL, id_groups INT NOT NULL, id_assets_folders INT NOT NULL, id_access_level INT NOT NULL, UNIQUE INDEX uq_assets_folders_groups_folder_group (id_assets_folders, id_groups), INDEX IDX_F189C340D65A8C9D (id_groups), INDEX IDX_F189C34051B7E443 (id_assets_folders), INDEX IDX_F189C34050BEC532 (id_access_level), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE assets_folders_groups ADD CONSTRAINT FK_F189C34050BEC532 FOREIGN KEY (id_access_level) REFERENCES lookups (id)');
        $this->addSql('ALTER TABLE assets_folders_groups ADD CONSTRAINT FK_F189C34051B7E443 FOREIGN KEY (id_assets_folders) REFERENCES assets_folders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assets_folders_groups ADD CONSTRAINT FK_F189C340D65A8C9D FOREIGN KEY (id_groups) REFERENCES `groups` (id) ON DELETE CASCADE');

        $this->addSql('INSERT INTO assets_folders (folder) SELECT DISTINCT folder FROM assets WHERE folder IS NOT NULL');
        $this->addSql(
            'UPDATE assets_folders af SET af.is_open_access = 1 WHERE EXISTS ('
            . 'SELECT 1 FROM assets a WHERE a.folder = af.folder '
            . 'AND NOT EXISTS (SELECT 1 FROM rel_assets_groups rag WHERE rag.id_assets = a.id))'
        );
        $this->addSql(
            'INSERT IGNORE INTO assets_folders_groups (id_groups, id_assets_folders, id_access_level) '
            . 'SELECT DISTINCT rag.id_groups, af.id, '
            . "(SELECT l.id FROM lookups l WHERE l.type_code = ? AND l.lookup_code = IF(g.name = 'admin', 'manage', 'read') LIMIT 1) "
            . 'FROM assets a INNER JOIN rel_assets_groups rag ON rag.id_assets = a.id '
            . 'INNER JOIN `groups` g ON g.id = rag.id_groups '
            . 'INNER JOIN assets_folders af ON af.folder = a.folder',
            [self::ACCESS_LEVEL_TYPE]
        );

        $this->addSql('ALTER TABLE rel_assets_groups DROP FOREIGN KEY FK_8736C2CA5FE6C863');
        $this->addSql('ALTER TABLE rel_assets_groups DROP FOREIGN KEY FK_8736C2CAD65A8C9D');
        $this->addSql('DROP TABLE rel_assets_groups');

        $this->addSql('DROP INDEX uq_assets_uuid ON assets');
        $this->addSql('ALTER TABLE assets DROP uuid, DROP name, DROP mime_type, DROP size, DROP alt, DROP created_at, DROP updated_at');
        $this->addSql('CREATE UNIQUE INDEX uq_assets_file_name ON assets (file_name)');
        $this->addSql("UPDATE `groups` SET name = 'therapist', description = 'Therapists' WHERE name = 'researcher'");
    }

    /** A random RFC 4122 version-4 uuid (as AssetStorageService::generateUuid). */
    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    private function updateImageField(string $field, ?string $title, string $help): void
    {
        $this->addSql(
            'UPDATE rel_fields_styles rfs '
            . 'INNER JOIN styles s ON s.id = rfs.id_styles '
            . 'INNER JOIN fields f ON f.id = rfs.id_fields '
            . 'SET rfs.title = ?, rfs.help = ? '
            . "WHERE s.name = 'image' AND f.name = ?",
            [$title, $help, $field]
        );
    }

    private function insertRoute(string $name, string $path, string $controller, string $method, ?string $requirements, ?string $permission): void
    {
        $this->addSql(
            'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
            [$name, self::VERSION, $path, $controller, $method, $requirements]
        );

        if ($permission !== null) {
            $this->addSql(
                'INSERT INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id FROM api_routes ar INNER JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$permission, $name, self::VERSION]
            );
        }
    }

    private function deleteRoute(string $name): void
    {
        $this->addSql(
            'DELETE rarp FROM rel_api_routes_permissions rarp '
            . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            [$name, self::VERSION]
        );
        $this->addSql(
            'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
            [$name, self::VERSION]
        );
    }
}
