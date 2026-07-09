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
 * First-class CMS apps product unit:
 * - `cms_apps` table + hub FKs
 * - `pages.id_cms_app` / `pages.cms_app_role`
 * - `admin.cms_app.*` permissions + API routes
 * - removes legacy POST /admin/pages/cms-app
 *
 * Migration policy: SQL was authored after the Doctrine entity mapping was
 * finalized because this migration combines schema DDL, permission seeds, and
 * `api_routes` rows that `make:migration` cannot emit as one reversible unit.
 * The class name/timestamp come from the team's migration generation workflow
 * at authoring time; do not hand-copy this filename pattern for new work.
 */
final class Version20260706221100 extends AbstractMigration
{
    private const VERSION = 'v1';

    private const PERM_READ = 'admin.cms_app.read';
    private const PERM_CREATE = 'admin.cms_app.create';
    private const PERM_UPDATE = 'admin.cms_app.update';
    private const PERM_DELETE = 'admin.cms_app.delete';

    private const LEGACY_ROUTE = 'admin_pages_cms_app';

    public function getDescription(): string
    {
        return 'First-class cms_apps entity, page assignment columns, admin.cms_app permissions/routes; remove POST /admin/pages/cms-app.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cms_apps (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                id_form_section INT DEFAULT NULL,
                id_cms_list_page INT DEFAULT NULL,
                id_cms_detail_page INT DEFAULT NULL,
                id_public_list_page INT DEFAULT NULL,
                id_public_detail_page INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uq_cms_apps_slug (slug),
                INDEX IDX_CMS_APPS_FORM_SECTION (id_form_section),
                INDEX IDX_CMS_APPS_CMS_LIST (id_cms_list_page),
                INDEX IDX_CMS_APPS_CMS_DETAIL (id_cms_detail_page),
                INDEX IDX_CMS_APPS_PUBLIC_LIST (id_public_list_page),
                INDEX IDX_CMS_APPS_PUBLIC_DETAIL (id_public_detail_page),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT FK_CMS_APPS_FORM_SECTION FOREIGN KEY (id_form_section) REFERENCES sections (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT FK_CMS_APPS_CMS_LIST FOREIGN KEY (id_cms_list_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT FK_CMS_APPS_CMS_DETAIL FOREIGN KEY (id_cms_detail_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT FK_CMS_APPS_PUBLIC_LIST FOREIGN KEY (id_public_list_page) REFERENCES pages (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cms_apps ADD CONSTRAINT FK_CMS_APPS_PUBLIC_DETAIL FOREIGN KEY (id_public_detail_page) REFERENCES pages (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE pages ADD id_cms_app INT DEFAULT NULL, ADD cms_app_role VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE pages ADD CONSTRAINT FK_PAGES_CMS_APP FOREIGN KEY (id_cms_app) REFERENCES cms_apps (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_pages_id_cms_app ON pages (id_cms_app)');

        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES (?, 'Read CMS apps')",
            [self::PERM_READ]
        );
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES (?, 'Create CMS apps')",
            [self::PERM_CREATE]
        );
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES (?, 'Update CMS apps and page assignments')",
            [self::PERM_UPDATE]
        );
        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES (?, 'Delete CMS app shells')",
            [self::PERM_DELETE]
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`) '
            . 'SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = ? '
            . 'WHERE p.name IN (?, ?, ?, ?)',
            ['admin', self::PERM_READ, self::PERM_CREATE, self::PERM_UPDATE, self::PERM_DELETE],
        );

        // Remove legacy wizard route (no backward compatibility).
        $this->addSql(
            'DELETE rarp FROM `rel_api_routes_permissions` rarp '
            . 'JOIN `api_routes` ar ON ar.id = rarp.id_api_routes '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            [self::LEGACY_ROUTE, self::VERSION]
        );
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [self::LEGACY_ROUTE, self::VERSION]);

        $routes = [
            ['admin_cms_apps_list', '/admin/cms-apps', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::listApps', 'GET', self::PERM_READ],
            ['admin_cms_apps_create', '/admin/cms-apps', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::createApp', 'POST', self::PERM_CREATE],
            ['admin_cms_apps_by_slug', '/admin/cms-apps/by-slug/{slug}', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::getBySlug', 'GET', self::PERM_READ],
            ['admin_cms_apps_get', '/admin/cms-apps/{id}', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::getApp', 'GET', self::PERM_READ],
            ['admin_cms_apps_update', '/admin/cms-apps/{id}', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::updateApp', 'PATCH', self::PERM_UPDATE],
            ['admin_cms_apps_delete', '/admin/cms-apps/{id}', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::deleteApp', 'DELETE', self::PERM_DELETE],
            ['admin_cms_apps_assign_page', '/admin/cms-apps/{id}/pages', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::assignPage', 'POST', self::PERM_UPDATE],
            ['admin_cms_apps_change_page_role', '/admin/cms-apps/{id}/pages/{page_id}', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::changePageRole', 'PATCH', self::PERM_UPDATE],
            ['admin_cms_apps_unassign_page', '/admin/cms-apps/{id}/pages/{page_id}', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::unassignPage', 'DELETE', self::PERM_UPDATE],
            ['admin_cms_apps_scaffold', '/admin/cms-apps/{id}/scaffold', 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::scaffold', 'POST', self::PERM_UPDATE],
        ];

        foreach ($routes as [$name, $path, $controller, $methods, $permission]) {
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
            $this->addSql(
                'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [$name, self::VERSION, $path, $controller, $methods]
            );
            $this->addSql(
                'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
                . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? WHERE ar.route_name = ? AND ar.version = ?',
                [$permission, $name, self::VERSION]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $routeNames = [
            'admin_cms_apps_list',
            'admin_cms_apps_create',
            'admin_cms_apps_by_slug',
            'admin_cms_apps_get',
            'admin_cms_apps_update',
            'admin_cms_apps_delete',
            'admin_cms_apps_assign_page',
            'admin_cms_apps_change_page_role',
            'admin_cms_apps_unassign_page',
            'admin_cms_apps_scaffold',
        ];
        foreach ($routeNames as $name) {
            $this->addSql(
                'DELETE rarp FROM `rel_api_routes_permissions` rarp JOIN `api_routes` ar ON ar.id = rarp.id_api_routes WHERE ar.route_name = ? AND ar.version = ?',
                [$name, self::VERSION]
            );
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
        }

        $this->addSql(
            'DELETE rpr FROM `rel_permissions_roles` rpr JOIN `permissions` p ON p.id = rpr.id_permissions WHERE p.name IN (?, ?, ?, ?)',
            [self::PERM_READ, self::PERM_CREATE, self::PERM_UPDATE, self::PERM_DELETE],
        );
        $this->addSql(
            'DELETE FROM `permissions` WHERE name IN (?, ?, ?, ?)',
            [self::PERM_READ, self::PERM_CREATE, self::PERM_UPDATE, self::PERM_DELETE]
        );

        $this->addSql('ALTER TABLE pages DROP FOREIGN KEY FK_PAGES_CMS_APP');
        $this->addSql('DROP INDEX idx_pages_id_cms_app ON pages');
        $this->addSql('ALTER TABLE pages DROP id_cms_app, DROP cms_app_role');

        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY FK_CMS_APPS_FORM_SECTION');
        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY FK_CMS_APPS_CMS_LIST');
        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY FK_CMS_APPS_CMS_DETAIL');
        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY FK_CMS_APPS_PUBLIC_LIST');
        $this->addSql('ALTER TABLE cms_apps DROP FOREIGN KEY FK_CMS_APPS_PUBLIC_DETAIL');
        $this->addSql('DROP TABLE cms_apps');

        // Restore legacy route (best-effort down).
        $this->addSql(
            'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) '
            . 'VALUES (?, ?, ?, ?, ?, NULL, ?, NULL)',
            [
                self::LEGACY_ROUTE,
                self::VERSION,
                '/admin/pages/cms-app',
                'App\\Controller\\Api\\V1\\Admin\\AdminPageController::createCmsApp',
                'POST',
                '{"base_name": {"in": "body", "required": true, "type": "string"}}',
            ]
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
            . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            ['admin.page.create', self::LEGACY_ROUTE, self::VERSION]
        );
    }
}
