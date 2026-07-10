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
 * Seed the final page-routing and CMS authoring contracts after the structural
 * tables/columns have been created by Version20260710092337.
 *
 * This deliberately skips the branch's transient page-icon catalog and the
 * retired POST /admin/pages/cms-app route. Menu-item icons and the first-class
 * /admin/cms-apps API are the only final contracts.
 */
final class Version20260710093044 extends AbstractMigration
{
    private const VERSION = 'v1';

    private const CMS_APP_PERMISSION_READ = 'admin.cms_app.read';
    private const CMS_APP_PERMISSION_CREATE = 'admin.cms_app.create';
    private const CMS_APP_PERMISSION_UPDATE = 'admin.cms_app.update';
    private const CMS_APP_PERMISSION_DELETE = 'admin.cms_app.delete';

    /** @var list<string> */
    private const NEW_FIELDS = [
        'open_in_modal',
        'close_modal_on_save',
        'redirect_on_save',
        'add_url',
        'edit_url',
        'modal_width',
        'modal_height',
    ];

    /** @var list<string> */
    private const FIELD_REF_TABLES = [
        'sections_fields_translation',
        'rel_fields_styles',
        'rel_fields_pages',
        'pages_fields_translation',
        'rel_fields_page_types',
    ];

    /**
     * @var list<array{
     *     keyword: string,
     *     pattern: string,
     *     requirements: array<string, string>|null,
     *     canonical: bool,
     *     priority: int
     * }>
     */
    private const PAGE_ROUTES = [
        ['keyword' => 'login', 'pattern' => '/login', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'home', 'pattern' => '/home', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'home', 'pattern' => '/', 'requirements' => null, 'canonical' => false, 'priority' => 10],
        ['keyword' => 'missing', 'pattern' => '/missing', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'no-access', 'pattern' => '/no-access', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'no-access-guest', 'pattern' => '/no-access-guest', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'agb', 'pattern' => '/agb', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'impressum', 'pattern' => '/impressum', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'disclaimer', 'pattern' => '/disclaimer', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'privacy', 'pattern' => '/privacy', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'profile', 'pattern' => '/profile', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'register', 'pattern' => '/register', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'maintenance', 'pattern' => '/maintenance', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'two-factor-authentication', 'pattern' => '/two-factor-authentication', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'validate', 'pattern' => '/validate/{user_id}/{token}', 'requirements' => ['user_id' => '\\d+', 'token' => '[A-Za-z0-9._~-]+'], 'canonical' => true, 'priority' => 50],
        ['keyword' => 'reset-password', 'pattern' => '/reset', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'reset-password', 'pattern' => '/reset/{user_id}/{token}', 'requirements' => ['user_id' => '\\d+', 'token' => '[A-Za-z0-9._~-]+'], 'canonical' => false, 'priority' => 50],
        ['keyword' => 'reset-password', 'pattern' => '/reset-password', 'requirements' => null, 'canonical' => false, 'priority' => 90],
    ];

    public function getDescription(): string
    {
        return 'Seed page surfaces/routes, page-bundle and CMS-app APIs, final modal/form authoring fields, button metadata, and the guarded validate layout.';
    }

    public function up(Schema $schema): void
    {
        $this->seedPageSurfaces();
        $this->seedPageRoutes();
        $this->seedCmsAppPermissions();
        $this->seedApiRoutes();
        $this->seedAuthoringCatalog();
        $this->updateButtonLinkMetadata();
        $this->centerUntouchedValidatePage();
    }

    public function down(Schema $schema): void
    {
        $this->restoreUntouchedValidatePage();
        $this->restoreButtonLinkMetadata();
        $this->removeAuthoringCatalog();
        $this->removeApiRoutes();
        $this->removeCmsAppPermissions();
        $this->removePageRoutes();
        $this->removePageSurfaces();
    }

    private function seedPageSurfaces(): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
            VALUES
                ('pageSurface', 'public', 'Public', 'Public website page rendered on the public frontend under normal page ACL'),
                ('pageSurface', 'cms', 'CMS application', 'CMS-in-CMS application page (admin/editor tooling) grouped separately and route-resolved but ACL-gated')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE pages p
            INNER JOIN lookups l
                ON l.type_code = 'pageSurface' AND l.lookup_code = 'public'
            SET p.id_page_surface = l.id
            WHERE p.id_page_surface IS NULL
            SQL);
    }

    private function removePageSurfaces(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pages p
            INNER JOIN lookups l ON l.id = p.id_page_surface
            SET p.id_page_surface = NULL
            WHERE l.type_code = 'pageSurface'
              AND l.lookup_code IN ('public', 'cms')
            SQL);
        $this->addSql("DELETE FROM lookups WHERE type_code = 'pageSurface' AND lookup_code IN ('public', 'cms')");
    }

    private function seedPageRoutes(): void
    {
        foreach (self::PAGE_ROUTES as $route) {
            $requirements = $route['requirements'] === null
                ? null
                : json_encode($route['requirements'], JSON_THROW_ON_ERROR);

            $this->addSql(
                'INSERT INTO page_routes (id_pages, path_pattern, requirements, is_canonical, is_active, priority, created_at) '
                . 'SELECT p.id, ?, ?, ?, 1, ?, UTC_TIMESTAMP() FROM pages p WHERE p.keyword = ?',
                [
                    $route['pattern'],
                    $requirements,
                    $route['canonical'] ? 1 : 0,
                    $route['priority'],
                    $route['keyword'],
                ]
            );
        }
    }

    private function removePageRoutes(): void
    {
        foreach (self::PAGE_ROUTES as $route) {
            $this->addSql(
                'DELETE pr FROM page_routes pr '
                . 'INNER JOIN pages p ON p.id = pr.id_pages '
                . 'WHERE p.keyword = ? AND pr.path_pattern = ?',
                [$route['keyword'], $route['pattern']]
            );
        }
    }

    private function seedCmsAppPermissions(): void
    {
        $permissions = [
            [self::CMS_APP_PERMISSION_READ, 'Read CMS apps'],
            [self::CMS_APP_PERMISSION_CREATE, 'Create CMS apps'],
            [self::CMS_APP_PERMISSION_UPDATE, 'Update CMS apps and page assignments'],
            [self::CMS_APP_PERMISSION_DELETE, 'Delete CMS app shells'],
        ];

        foreach ($permissions as [$name, $description]) {
            $this->addSql(
                'INSERT INTO permissions (name, description) VALUES (?, ?)',
                [$name, $description]
            );
        }

        $this->addSql(
            'INSERT INTO rel_permissions_roles (id_permissions, id_roles) '
            . 'SELECT p.id, r.id FROM permissions p INNER JOIN roles r ON r.name = ? '
            . 'WHERE p.name IN (?, ?, ?, ?)',
            [
                'admin',
                self::CMS_APP_PERMISSION_READ,
                self::CMS_APP_PERMISSION_CREATE,
                self::CMS_APP_PERMISSION_UPDATE,
                self::CMS_APP_PERMISSION_DELETE,
            ]
        );
    }

    private function removeCmsAppPermissions(): void
    {
        $this->addSql(
            'DELETE rpr FROM rel_permissions_roles rpr '
            . 'INNER JOIN permissions p ON p.id = rpr.id_permissions '
            . 'WHERE p.name IN (?, ?, ?, ?)',
            [
                self::CMS_APP_PERMISSION_READ,
                self::CMS_APP_PERMISSION_CREATE,
                self::CMS_APP_PERMISSION_UPDATE,
                self::CMS_APP_PERMISSION_DELETE,
            ]
        );
        $this->addSql(
            'DELETE FROM permissions WHERE name IN (?, ?, ?, ?)',
            [
                self::CMS_APP_PERMISSION_READ,
                self::CMS_APP_PERMISSION_CREATE,
                self::CMS_APP_PERMISSION_UPDATE,
                self::CMS_APP_PERMISSION_DELETE,
            ]
        );
    }

    private function seedApiRoutes(): void
    {
        $routes = [
            [
                'name' => 'pages_resolve_path',
                'path' => '/pages/resolve',
                'controller' => 'App\\Controller\\Api\\V1\\Frontend\\PageController::resolvePublicPath',
                'method' => 'GET',
                'permission' => null,
                'requirements' => null,
                'params' => '{"path":{"in":"query","required":true},"language_id":{"in":"query","required":false},"preview":{"in":"query","required":false}}',
            ],
            [
                'name' => 'admin_pages_export',
                'path' => '/admin/pages/export',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::exportPages',
                'method' => 'POST',
                'permission' => 'admin.page.export',
                'requirements' => null,
                'params' => '{"pageIds":{"in":"body","required":true,"type":"array"}}',
            ],
            [
                'name' => 'admin_pages_export_suggest',
                'path' => '/admin/pages/{page_id}/export/suggest',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::suggestExportBundle',
                'method' => 'GET',
                'permission' => 'admin.page.export',
                'requirements' => '{"page_id":"[0-9]+"}',
                'params' => null,
            ],
            [
                'name' => 'admin_pages_import_validate',
                'path' => '/admin/pages/import/validate',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::validateImportPages',
                'method' => 'POST',
                'permission' => 'admin.page.create',
                'requirements' => null,
                'params' => '{"bundle":{"in":"body","required":true,"type":"object"},"options":{"in":"body","required":false,"type":"object"}}',
            ],
            [
                'name' => 'admin_pages_import',
                'path' => '/admin/pages/import',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::importPages',
                'method' => 'POST',
                'permission' => 'admin.page.create',
                'requirements' => null,
                'params' => '{"bundle":{"in":"body","required":true,"type":"object"},"options":{"in":"body","required":false,"type":"object"}}',
            ],
            [
                'name' => 'admin_pages_examples',
                'path' => '/admin/pages/examples',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminPageController::getExampleBundles',
                'method' => 'GET',
                'permission' => 'admin.page.export',
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_list',
                'path' => '/admin/cms-apps',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::listApps',
                'method' => 'GET',
                'permission' => self::CMS_APP_PERMISSION_READ,
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_create',
                'path' => '/admin/cms-apps',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::createApp',
                'method' => 'POST',
                'permission' => self::CMS_APP_PERMISSION_CREATE,
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_by_slug',
                'path' => '/admin/cms-apps/by-slug/{slug}',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::getBySlug',
                'method' => 'GET',
                'permission' => self::CMS_APP_PERMISSION_READ,
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_get',
                'path' => '/admin/cms-apps/{id}',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::getApp',
                'method' => 'GET',
                'permission' => self::CMS_APP_PERMISSION_READ,
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_update',
                'path' => '/admin/cms-apps/{id}',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::updateApp',
                'method' => 'PATCH',
                'permission' => self::CMS_APP_PERMISSION_UPDATE,
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_delete',
                'path' => '/admin/cms-apps/{id}',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::deleteApp',
                'method' => 'DELETE',
                'permission' => self::CMS_APP_PERMISSION_DELETE,
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_assign_page',
                'path' => '/admin/cms-apps/{id}/pages',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::assignPage',
                'method' => 'POST',
                'permission' => self::CMS_APP_PERMISSION_UPDATE,
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_change_page_role',
                'path' => '/admin/cms-apps/{id}/pages/{page_id}',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::changePageRole',
                'method' => 'PATCH',
                'permission' => self::CMS_APP_PERMISSION_UPDATE,
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_unassign_page',
                'path' => '/admin/cms-apps/{id}/pages/{page_id}',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::unassignPage',
                'method' => 'DELETE',
                'permission' => self::CMS_APP_PERMISSION_UPDATE,
                'requirements' => null,
                'params' => null,
            ],
            [
                'name' => 'admin_cms_apps_scaffold',
                'path' => '/admin/cms-apps/{id}/scaffold',
                'controller' => 'App\\Controller\\Api\\V1\\Admin\\AdminCmsAppController::scaffold',
                'method' => 'POST',
                'permission' => self::CMS_APP_PERMISSION_UPDATE,
                'requirements' => null,
                'params' => null,
            ],
        ];

        foreach ($routes as $route) {
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$route['name'], self::VERSION]
            );
            $this->addSql(
                'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, NULL)',
                [
                    $route['name'],
                    self::VERSION,
                    $route['path'],
                    $route['controller'],
                    $route['method'],
                    $route['requirements'],
                    $route['params'],
                ]
            );

            if ($route['permission'] !== null) {
                $this->addSql(
                    'INSERT INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                    . 'SELECT ar.id, p.id FROM api_routes ar INNER JOIN permissions p ON p.name = ? '
                    . 'WHERE ar.route_name = ? AND ar.version = ?',
                    [$route['permission'], $route['name'], self::VERSION]
                );
            }
        }
    }

    private function removeApiRoutes(): void
    {
        $routeNames = [
            'pages_resolve_path',
            'admin_pages_export',
            'admin_pages_export_suggest',
            'admin_pages_import_validate',
            'admin_pages_import',
            'admin_pages_examples',
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

        foreach ($routeNames as $routeName) {
            $this->addSql(
                'DELETE rarp FROM rel_api_routes_permissions rarp '
                . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$routeName, self::VERSION]
            );
            $this->addSql(
                'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
                [$routeName, self::VERSION]
            );
        }
    }

    private function seedAuthoringCatalog(): void
    {
        foreach (self::NEW_FIELDS as $fieldName) {
            $this->abortIf(
                $this->fieldExists($fieldName),
                sprintf("Refusing to create authoring field '%s': it already exists.", $fieldName)
            );
        }
        $this->abortIf(
            $this->fieldTypeExists('select-modal-size'),
            "Refusing to create field type 'select-modal-size': it already exists."
        );
        foreach (['checkbox', 'select-page-keyword'] as $requiredType) {
            $this->abortIf(
                !$this->fieldTypeExists($requiredType),
                sprintf("Required field type '%s' is missing.", $requiredType)
            );
        }

        $this->addSql("INSERT INTO field_types (name, position) VALUES ('select-modal-size', 0)");

        $fields = [
            'open_in_modal' => 'checkbox',
            'close_modal_on_save' => 'checkbox',
            'redirect_on_save' => 'select-page-keyword',
            'add_url' => 'select-page-keyword',
            'edit_url' => 'select-page-keyword',
            'modal_width' => 'select-modal-size',
            'modal_height' => 'select-modal-size',
        ];
        foreach ($fields as $fieldName => $fieldType) {
            $this->addSql(
                'INSERT INTO fields (name, id_field_types, display, config) '
                . 'SELECT ?, ft.id, 0, NULL FROM field_types ft WHERE ft.name = ?',
                [$fieldName, $fieldType]
            );
        }

        $pageFields = [
            'open_in_modal' => [
                'Open in modal (web)',
                'When enabled, the website opens this page inside a modal overlay (the page title becomes the modal header, with a close button) instead of a full page. Ideal for CMS-in-CMS create/edit/detail pages opened from a list. Web only - the mobile app opens the page as a normal screen.',
            ],
            'modal_width' => [
                'Modal width (web)',
                'Width of the modal when this page opens in a modal (needs "Open in modal"). Use a CSS length (e.g. "80%", "640px", "48rem") or "auto" to fit the content (capped at 90% of the screen). Leave empty for the default (80%). Web only.',
            ],
            'modal_height' => [
                'Modal height (web)',
                'Height of the modal when this page opens in a modal (needs "Open in modal"). Use a CSS length (e.g. "80%", "600px", "40rem") or "auto" to fit the content (capped at 90% of the screen). Leave empty for the default (80%). Web only.',
            ],
        ];
        foreach (['core', 'experiment'] as $pageType) {
            foreach ($pageFields as $fieldName => [$title, $help]) {
                $this->addSql(
                    'INSERT INTO rel_fields_page_types (id_page_types, id_fields, title, help, default_value) '
                    . 'SELECT pt.id, f.id, ?, ?, NULL FROM page_types pt, fields f '
                    . 'WHERE pt.name = ? AND f.name = ?',
                    [$title, $help, $pageType, $fieldName]
                );
            }
        }

        $styleFields = [
            ['form-log', 'close_modal_on_save', '0', 'When enabled, a successful submit closes the surrounding modal (if this form is shown inside one).', 'Close modal on save'],
            ['form-log', 'redirect_on_save', '', 'Optional URL to navigate to after a successful submit (the parent list is refreshed). Leave empty to stay/close.', 'Redirect on save'],
            ['form-record', 'close_modal_on_save', '0', 'When enabled, a successful submit closes the surrounding modal (if this form is shown inside one).', 'Close modal on save'],
            ['form-record', 'redirect_on_save', '', 'Optional URL to navigate to after a successful submit (the parent list is refreshed). Leave empty to stay/close.', 'Redirect on save'],
            ['show-user-input', 'add_url', '', 'Page or custom URL for the create form. When set, an "Add new" button is shown above the table.', 'Add new URL'],
            ['show-user-input', 'edit_url', '', 'Page or custom URL template for row edit (use {record_id} as placeholder).', 'Open/edit URL'],
        ];
        foreach ($styleFields as [$styleName, $fieldName, $defaultValue, $help, $title]) {
            $this->addSql(
                'INSERT INTO rel_fields_styles (id_styles, id_fields, default_value, help, disabled, hidden, title) '
                . 'SELECT s.id, f.id, ?, ?, 0, 0, ? FROM styles s, fields f '
                . 'WHERE s.name = ? AND f.name = ?',
                [$defaultValue, $help, $title, $styleName, $fieldName]
            );
        }
    }

    private function removeAuthoringCatalog(): void
    {
        foreach (self::NEW_FIELDS as $fieldName) {
            foreach (self::FIELD_REF_TABLES as $table) {
                $this->addSql(
                    sprintf(
                        'DELETE FROM `%s` WHERE id_fields = (SELECT id FROM fields WHERE name = ?)',
                        $table
                    ),
                    [$fieldName]
                );
            }
            $this->addSql('DELETE FROM fields WHERE name = ?', [$fieldName]);
        }
        $this->addSql("DELETE FROM field_types WHERE name = 'select-modal-size'");
    }

    private function updateButtonLinkMetadata(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE rel_fields_styles rfs
            INNER JOIN styles s ON s.id = rfs.id_styles
            INNER JOIN fields f ON f.id = rfs.id_fields
            SET rfs.title = 'Internal page',
                rfs.help = 'Select a page keyword for an internal CMS link. Leave empty or # to use Path / external URL instead.',
                rfs.default_value = ''
            WHERE s.name = 'button' AND f.name = 'page_keyword'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE rel_fields_styles rfs
            INNER JOIN styles s ON s.id = rfs.id_styles
            INNER JOIN fields f ON f.id = rfs.id_fields
            SET rfs.title = 'Path / external URL',
                rfs.help = 'Absolute path (/...) or external URL when Internal page is unset. Used for profile/back links and mailto:.'
            WHERE s.name = 'button' AND f.name = 'url'
            SQL);
    }

    private function restoreButtonLinkMetadata(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE rel_fields_styles rfs
            INNER JOIN styles s ON s.id = rfs.id_styles
            INNER JOIN fields f ON f.id = rfs.id_fields
            SET rfs.title = 'URL',
                rfs.help = 'Select a page keyword to link to. For more information check https://mantine.dev/core/button',
                rfs.default_value = '#'
            WHERE s.name = 'button' AND f.name = 'page_keyword'
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE rel_fields_styles rfs
            INNER JOIN styles s ON s.id = rfs.id_styles
            INNER JOIN fields f ON f.id = rfs.id_fields
            SET rfs.title = 'URL',
                rfs.help = 'External URL to open when the button is a link and no internal page is selected.'
            WHERE s.name = 'button' AND f.name = 'url'
            SQL);
    }

    /**
     * Apply the centering only after the final `size` / `mih` field names exist.
     * The earlier WIP baseline edit could build the section tree, but its `mih`
     * value could not persist at that point in the field-catalog sequence.
     */
    private function centerUntouchedValidatePage(): void
    {
        $needsTreeTransformation = $this->hasUntouchedValidateLayout();
        if (!$needsTreeTransformation && !$this->hasConsolidatedValidateLayout()) {
            return;
        }

        // Accept the original wrapper -> form tree and the exact WIP-centered
        // tree so databases built while the branch was in progress are repaired
        // too; custom trees are left untouched.
        if ($needsTreeTransformation) {
            $this->addSql(<<<'SQL'
                UPDATE sections wrapper
                INNER JOIN styles centered ON centered.name = 'center'
                SET wrapper.id_styles = centered.id
                WHERE wrapper.name = 'validate-sys-wrapper'
                SQL);
            $this->addSql(<<<'SQL'
                INSERT INTO sections (id_styles, name, css)
                SELECT s.id, 'validate-sys-container', 'max-w-md mx-auto px-4 py-12'
                FROM styles s
                WHERE s.name = 'container'
                SQL);
            $this->addSql(<<<'SQL'
                INSERT INTO rel_sections_hierarchy (id_parent_section, id_child_section, position)
                SELECT wrapper.id, container.id, current_relation.position
                FROM sections wrapper
                INNER JOIN rel_sections_hierarchy current_relation
                    ON current_relation.id_parent_section = wrapper.id
                INNER JOIN sections form ON form.id = current_relation.id_child_section
                    AND form.name = 'validate-sys-form'
                INNER JOIN sections container ON container.name = 'validate-sys-container'
                WHERE wrapper.name = 'validate-sys-wrapper'
                SQL);
            $this->addSql(<<<'SQL'
                UPDATE rel_sections_hierarchy current_relation
                INNER JOIN sections wrapper ON wrapper.id = current_relation.id_parent_section
                    AND wrapper.name = 'validate-sys-wrapper'
                INNER JOIN sections form ON form.id = current_relation.id_child_section
                    AND form.name = 'validate-sys-form'
                INNER JOIN sections container ON container.name = 'validate-sys-container'
                SET current_relation.id_parent_section = container.id
                SQL);
        }

        $this->addSql(<<<'SQL'
            UPDATE sections
            SET css = 'max-w-md mx-auto px-4 py-12'
            WHERE name = 'validate-sys-container'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE sft
            FROM sections_fields_translation sft
            INNER JOIN sections wrapper ON wrapper.id = sft.id_sections
            INNER JOIN fields f ON f.id = sft.id_fields
            WHERE wrapper.name = 'validate-sys-wrapper' AND f.name IN ('size', 'mih')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO sections_fields_translation (id_sections, id_fields, id_languages, content, meta)
            SELECT wrapper.id, f.id, lang.id, '100vh', NULL
            FROM sections wrapper
            INNER JOIN fields f ON f.name = 'mih'
            CROSS JOIN (SELECT id FROM languages ORDER BY id LIMIT 1) lang
            WHERE wrapper.name = 'validate-sys-wrapper'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE sft
            FROM sections_fields_translation sft
            INNER JOIN sections container ON container.id = sft.id_sections
            INNER JOIN fields f ON f.id = sft.id_fields
            WHERE container.name = 'validate-sys-container' AND f.name IN ('size', 'mih')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO sections_fields_translation (id_sections, id_fields, id_languages, content, meta)
            SELECT container.id, f.id, lang.id, 'sm', NULL
            FROM sections container
            INNER JOIN fields f ON f.name = 'size'
            CROSS JOIN (SELECT id FROM languages ORDER BY id LIMIT 1) lang
            WHERE container.name = 'validate-sys-container'
            SQL);
    }

    private function restoreUntouchedValidatePage(): void
    {
        if (!$this->hasConsolidatedValidateLayout()) {
            return;
        }

        $this->addSql(<<<'SQL'
            UPDATE rel_sections_hierarchy current_relation
            INNER JOIN sections container ON container.id = current_relation.id_parent_section
                AND container.name = 'validate-sys-container'
            INNER JOIN sections form ON form.id = current_relation.id_child_section
                AND form.name = 'validate-sys-form'
            INNER JOIN sections wrapper ON wrapper.name = 'validate-sys-wrapper'
            SET current_relation.id_parent_section = wrapper.id
            SQL);
        $this->addSql("DELETE FROM sections WHERE name = 'validate-sys-container'");
        $this->addSql(<<<'SQL'
            UPDATE sections wrapper
            INNER JOIN styles container_style ON container_style.name = 'container'
            SET wrapper.id_styles = container_style.id
            WHERE wrapper.name = 'validate-sys-wrapper'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE sft
            FROM sections_fields_translation sft
            INNER JOIN sections wrapper ON wrapper.id = sft.id_sections
            INNER JOIN fields f ON f.id = sft.id_fields
            WHERE wrapper.name = 'validate-sys-wrapper' AND f.name IN ('size', 'mih')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO sections_fields_translation (id_sections, id_fields, id_languages, content, meta)
            SELECT wrapper.id, f.id, lang.id, 'sm', NULL
            FROM sections wrapper
            INNER JOIN fields f ON f.name = 'size'
            CROSS JOIN (SELECT id FROM languages ORDER BY id LIMIT 1) lang
            WHERE wrapper.name = 'validate-sys-wrapper'
            SQL);
    }

    private function hasUntouchedValidateLayout(): bool
    {
        $count = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM pages p
            INNER JOIN rel_pages_sections rps ON rps.id_pages = p.id
            INNER JOIN sections wrapper ON wrapper.id = rps.id_sections
            INNER JOIN styles wrapper_style ON wrapper_style.id = wrapper.id_styles
            INNER JOIN rel_sections_hierarchy hierarchy
                ON hierarchy.id_parent_section = wrapper.id
            INNER JOIN sections form ON form.id = hierarchy.id_child_section
            INNER JOIN styles form_style ON form_style.id = form.id_styles
            WHERE p.keyword = 'validate'
              AND p.is_system = 1
              AND wrapper.name = 'validate-sys-wrapper'
              AND wrapper_style.name = 'container'
              AND form.name = 'validate-sys-form'
              AND form_style.name = 'validate'
              AND NOT EXISTS (
                  SELECT 1 FROM sections existing_container
                  WHERE existing_container.name = 'validate-sys-container'
              )
              AND (SELECT COUNT(*) FROM rel_pages_sections roots WHERE roots.id_pages = p.id) = 1
              AND (SELECT COUNT(*) FROM rel_sections_hierarchy children WHERE children.id_parent_section = wrapper.id) = 1
            SQL);

        return $this->fetchCountEqualsOne($count);
    }

    private function hasConsolidatedValidateLayout(): bool
    {
        $count = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM pages p
            INNER JOIN rel_pages_sections rps ON rps.id_pages = p.id
            INNER JOIN sections wrapper ON wrapper.id = rps.id_sections
            INNER JOIN styles wrapper_style ON wrapper_style.id = wrapper.id_styles
            INNER JOIN rel_sections_hierarchy wrapper_relation
                ON wrapper_relation.id_parent_section = wrapper.id
            INNER JOIN sections container ON container.id = wrapper_relation.id_child_section
            INNER JOIN styles container_style ON container_style.id = container.id_styles
            INNER JOIN rel_sections_hierarchy container_relation
                ON container_relation.id_parent_section = container.id
            INNER JOIN sections form ON form.id = container_relation.id_child_section
            INNER JOIN styles form_style ON form_style.id = form.id_styles
            WHERE p.keyword = 'validate'
              AND p.is_system = 1
              AND wrapper.name = 'validate-sys-wrapper'
              AND wrapper_style.name = 'center'
              AND container.name = 'validate-sys-container'
              AND container_style.name = 'container'
              AND form.name = 'validate-sys-form'
              AND form_style.name = 'validate'
              AND (SELECT COUNT(*) FROM rel_pages_sections roots WHERE roots.id_pages = p.id) = 1
              AND (SELECT COUNT(*) FROM rel_sections_hierarchy children WHERE children.id_parent_section = wrapper.id) = 1
              AND (SELECT COUNT(*) FROM rel_sections_hierarchy children WHERE children.id_parent_section = container.id) = 1
            SQL);

        return $this->fetchCountEqualsOne($count);
    }

    private function fieldExists(string $fieldName): bool
    {
        return $this->fetchCountGreaterThanZero(
            $this->connection->fetchOne(
                'SELECT COUNT(*) FROM fields WHERE name = ?',
                [$fieldName]
            )
        );
    }

    private function fieldTypeExists(string $fieldTypeName): bool
    {
        return $this->fetchCountGreaterThanZero(
            $this->connection->fetchOne(
                'SELECT COUNT(*) FROM field_types WHERE name = ?',
                [$fieldTypeName]
            )
        );
    }

    private function fetchCountEqualsOne(mixed $count): bool
    {
        return (is_int($count) || is_string($count)) && (int) $count === 1;
    }

    private function fetchCountGreaterThanZero(mixed $count): bool
    {
        return (is_int($count) || is_string($count)) && (int) $count > 0;
    }
}
