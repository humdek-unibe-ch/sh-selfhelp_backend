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
 * Assets: uuid identity, per-asset view groups (none = public), CMS management
 * via role_data_access, folders as an organisation-only tree, the asset bundle
 * export/import routes, and no `admin` or `therapist` group (admin rights come
 * from the admin role; `subject` is the one built-in group).
 *
 * Fresh installs only: the platform is unreleased, so this goes straight from
 * the baseline `assets` table to the final shape with no data backfill.
 */
final class Version20261103112133 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ASSET_RESOURCE_TYPE = 'asset';
    private const UPDATE_PERMISSION = 'admin.asset.update';

    /** Routes added in up() and removed in down(). Permission null = public. */
    private const NEW_ROUTES = [
        ['admin_assets_update', '/admin/assets/{assetId}', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::updateAsset', 'POST', '{"assetId":"[0-9]+"}', self::UPDATE_PERMISSION],
        ['admin_assets_bulk_groups', '/admin/assets/bulk-groups', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::bulkUpdateAssetGroups', 'POST', null, self::UPDATE_PERMISSION],
        ['admin_assets_bulk_folder', '/admin/assets/bulk-folder', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::bulkMoveAssetsToFolder', 'POST', null, self::UPDATE_PERMISSION],
        ['admin_assets_bulk_delete', '/admin/assets/bulk-delete', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::bulkDeleteAssets', 'POST', null, 'admin.asset.delete'],
        ['admin_assets_export', '/admin/assets/export', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::exportAssets', 'POST', null, 'admin.asset.read'],
        ['admin_assets_import', '/admin/assets/import', 'App\\Controller\\Api\\V1\\Admin\\AdminAssetController::importAssets', 'POST', null, 'admin.asset.create'],
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
        return 'Assets: uuid identity, view groups per asset (none = public), CMS management via role_data_access, organisation-only folder tree, export/import routes; the admin and therapist groups are removed.';
    }

    public function up(Schema $schema): void
    {
        // 1. Groups only decide who sees what. Admin rights come from the admin
        //    role, so there is no `admin` group, and every group except the one
        //    built-in default (`subject`) is custom, so `therapist` goes too.
        //    Memberships and page/asset ACL rows cascade; role_data_access has no
        //    foreign key to a group, so its rows for a removed group are deleted
        //    by hand.
        $this->addSql("DELETE FROM `groups` WHERE name IN ('admin', 'therapist')");
        $this->addSql(
            'DELETE rda FROM role_data_access rda '
            . "INNER JOIN lookups l ON l.id = rda.id_resource_types AND l.type_code = 'resourceTypes' AND l.lookup_code = 'group' "
            . 'LEFT JOIN `groups` g ON g.id = rda.resource_id WHERE g.id IS NULL'
        );

        // 2. Folders: an organisation-only tree.
        $this->addSql('CREATE TABLE assets_folders (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, id_parent INT DEFAULT NULL, INDEX idx_assets_folders_id_parent (id_parent), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE assets_folders ADD CONSTRAINT FK_6CD159451BB9D5A2 FOREIGN KEY (id_parent) REFERENCES assets_folders (id) ON DELETE CASCADE');

        // 3. Assets: uuid identity replaces folder, file name and file path
        //    (bytes live at var/assets/<uuid>).
        $this->addSql('DROP INDEX uq_assets_file_name ON assets');
        $this->addSql('ALTER TABLE assets DROP folder, DROP file_name, DROP file_path, ADD uuid VARCHAR(36) NOT NULL, ADD name VARCHAR(255) DEFAULT NULL, ADD mime_type VARCHAR(150) DEFAULT NULL, ADD size INT DEFAULT NULL, ADD alt VARCHAR(500) DEFAULT NULL, ADD created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, ADD updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, ADD id_assets_folders INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_assets_uuid ON assets (uuid)');
        $this->addSql('CREATE INDEX IDX_79D17D8E51B7E443 ON assets (id_assets_folders)');
        $this->addSql('ALTER TABLE assets ADD CONSTRAINT FK_79D17D8E51B7E443 FOREIGN KEY (id_assets_folders) REFERENCES assets_folders (id) ON DELETE SET NULL');

        // 4. Viewing: per-asset groups, none = public.
        $this->addSql('CREATE TABLE rel_assets_groups (id_assets INT NOT NULL, id_groups INT NOT NULL, INDEX IDX_8736C2CA5FE6C863 (id_assets), INDEX IDX_8736C2CAD65A8C9D (id_groups), PRIMARY KEY (id_assets, id_groups)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE rel_assets_groups ADD CONSTRAINT FK_8736C2CA5FE6C863 FOREIGN KEY (id_assets) REFERENCES assets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rel_assets_groups ADD CONSTRAINT FK_8736C2CAD65A8C9D FOREIGN KEY (id_groups) REFERENCES `groups` (id) ON DELETE CASCADE');

        // 5. CMS management: resource type `asset` in role_data_access.
        $this->addSql(
            "INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description) VALUES ('resourceTypes', ?, 'Asset', 'An uploaded asset (file)')",
            [self::ASSET_RESOURCE_TYPE]
        );

        // 6. Permission + routes. Whoever can upload assets can also edit them.
        $this->addSql(
            "INSERT IGNORE INTO permissions (name, description) VALUES (?, 'Edit assets: rename, alt text, groups, replace the file')",
            [self::UPDATE_PERMISSION]
        );
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
    }

    public function down(Schema $schema): void
    {
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

        $this->addSql(
            "DELETE rda FROM role_data_access rda INNER JOIN lookups l ON l.id = rda.id_resource_types AND l.type_code = 'resourceTypes' AND l.lookup_code = ?",
            [self::ASSET_RESOURCE_TYPE]
        );
        $this->addSql("DELETE FROM lookups WHERE type_code = 'resourceTypes' AND lookup_code = ?", [self::ASSET_RESOURCE_TYPE]);

        $this->addSql('ALTER TABLE rel_assets_groups DROP FOREIGN KEY FK_8736C2CA5FE6C863');
        $this->addSql('ALTER TABLE rel_assets_groups DROP FOREIGN KEY FK_8736C2CAD65A8C9D');
        $this->addSql('DROP TABLE rel_assets_groups');

        $this->addSql('ALTER TABLE assets DROP FOREIGN KEY FK_79D17D8E51B7E443');
        $this->addSql('DROP INDEX IDX_79D17D8E51B7E443 ON assets');
        $this->addSql('DROP INDEX uq_assets_uuid ON assets');
        $this->addSql('ALTER TABLE assets DROP uuid, DROP name, DROP mime_type, DROP size, DROP alt, DROP created_at, DROP updated_at, DROP id_assets_folders, ADD folder VARCHAR(100) DEFAULT NULL, ADD file_name VARCHAR(100) DEFAULT NULL, ADD file_path VARCHAR(1000) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_assets_file_name ON assets (file_name)');

        $this->addSql('ALTER TABLE assets_folders DROP FOREIGN KEY FK_6CD159451BB9D5A2');
        $this->addSql('DROP TABLE assets_folders');

        // The removed groups come back empty: memberships, ACL rows and
        // role grants of the admin and therapist groups are not restored.
        foreach (['admin' => 'Administrators', 'therapist' => 'Therapists'] as $name => $description) {
            $this->addSql(
                'INSERT INTO `groups` (name, description, requires_2fa) '
                . "SELECT '" . $name . "', '" . $description . "', 0 FROM DUAL "
                . "WHERE NOT EXISTS (SELECT 1 FROM `groups` WHERE name = '" . $name . "')"
            );
        }
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
