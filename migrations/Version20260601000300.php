<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

require_once __DIR__ . '/LegacySeedTrait.php';

/**
 * Seed migration: API routes + route-permission links.
 *
 * Materializes every row that `ApiRouteLoader` needs to register
 * the CMS API under `/cms-api/v1`. Without this seed the backend boots
 * with an empty route table and every API call returns 404.
 *
 * This migration folds in the three piecemeal route migrations from
 * the legacy history (`Version20260424120000`, `Version20260508140000`,
 * `Version20260508160000`) plus the contents of
 * `db/legacy/update_scripts/api_routes.sql`. Sourced from
 * `db/legacy/new_create_db.sql`.
 *
 * Depends on:
 *   - Version20260601000000_CanonicalBaseline (schema)
 *   - Version20260601000100_SeedReferenceData (permissions)
 */
final class Version20260601000300 extends AbstractMigration
{
    use LegacySeedTrait;

    public function getDescription(): string
    {
        return 'Seed api_routes and rel_api_routes_permissions.';
    }

    public function up(Schema $schema): void
    {
        $this->seedFromLegacy('api_routes');
        $this->seedFromLegacy('api_routes_permissions');

        // ============================================================
        // Routes added AFTER the legacy `new_create_db.sql` snapshot was
        // taken — they used to live in the now-deleted piecemeal route
        // migrations (Version20260421000000, Version20260424120000,
        // Version20260425000000, Version20260430131025,
        // Version20260508140000). The legacy dump never received those
        // INSERTs, so the LegacySeedTrait sweep above misses them. We
        // re-add them explicitly here so a fresh install reaches the
        // same final api_routes state as a long-running production DB.
        // ============================================================
        $this->seedMissingRoutes();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM `rel_api_routes_permissions`');
        $this->addSql('DELETE FROM `api_routes`');
    }

    /**
     * Inserts the 6 API routes that were added by the deleted piecemeal
     * route migrations and are NOT present in the legacy SQL dump. Each
     * INSERT IGNOREs on the unique (route_name, version) key so the
     * migration is safe to re-run.
     */
    private function seedMissingRoutes(): void
    {
        // (1) pages_get_by_keyword — was Version20260421000000.
        //     Frontend BFF slug resolver.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`)
            VALUES (
                'pages_get_by_keyword',
                'v1',
                '/pages/by-keyword/{keyword}',
                'App\\\\Controller\\\\Api\\\\V1\\\\Frontend\\\\PageController::getPageByKeyword',
                'GET',
                JSON_OBJECT('keyword', '[a-zA-Z0-9_\\\\-]+'),
                JSON_OBJECT(
                    'keyword', JSON_OBJECT('in', 'path', 'required', true),
                    'language_id', JSON_OBJECT('in', 'query', 'required', false),
                    'preview', JSON_OBJECT('in', 'query', 'required', false)
                )
            )
        SQL);

        // (2) admin_styles_schema_get — was Version20260424120000.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'admin_styles_schema_get',
                'v1',
                'GET',
                '/admin/styles/schema',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminStyleController::getStylesSchema',
                '[]',
                '[]'
            )
        SQL);
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = 'admin.access'
            WHERE ar.route_name = 'admin_styles_schema_get'
        SQL);

        // (3) admin_ai_section_prompt_template_get — was Version20260424120000.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'admin_ai_section_prompt_template_get',
                'v1',
                'GET',
                '/admin/ai/section-prompt-template',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminStyleController::getSectionPromptTemplate',
                '[]',
                '[]'
            )
        SQL);
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = 'admin.page.export'
            WHERE ar.route_name = 'admin_ai_section_prompt_template_get'
        SQL);

        // (4) auth_events_stream_v1 — was Version20260425000000.
        //     Mercure SSE bootstrap. PUBLIC_ACCESS by route prefix, no
        //     permission row — the controller authenticates manually.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'auth_events_stream_v1',
                'v1',
                'GET',
                '/auth/events',
                'App\\\\Controller\\\\Api\\\\V1\\\\Auth\\\\AuthEventsController::events',
                NULL,
                NULL
            )
        SQL);

        // (5) admin_pages_bulk_remove_sections — was Version20260430131025.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`)
            VALUES (
                'admin_pages_bulk_remove_sections',
                'v1',
                '/admin/pages/{page_id}/sections',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminPageController::bulkRemoveSectionsFromPage',
                'DELETE',
                JSON_OBJECT('page_id', '[0-9]+'),
                JSON_OBJECT(
                    'sectionIds', JSON_OBJECT('in', 'body', 'required', true, 'type', 'array')
                )
            )
        SQL);
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = 'admin.page.update'
            WHERE ar.route_name = 'admin_pages_bulk_remove_sections'
              AND ar.version = 'v1'
        SQL);

        // (6) admin_users_stop_impersonate_v1 — was Version20260508140000.
        //     PUBLIC by design (see deleted migration's note): while
        //     impersonating, the request authenticates as the target
        //     user and the controller verifies the impersonation claim.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'admin_users_stop_impersonate_v1',
                'v1',
                'POST',
                '/admin/users/stop-impersonate',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminUserController::stopImpersonateUser',
                NULL,
                NULL
            )
        SQL);

        // (7) admin_sections_delete — single-section DELETE endpoint.
        //     Lives in `db/legacy/update_scripts/api_routes.sql` but
        //     was missing from `db/legacy/new_create_db.sql`, so the
        //     LegacySeedTrait sweep does not pick it up. Without this
        //     row the DELETE `/admin/pages/{page_id}/sections/{section_id}`
        //     endpoint 404s on a fresh install.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`)
            VALUES (
                'admin_sections_delete',
                'v1',
                '/admin/pages/{page_id}/sections/{section_id}',
                'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminSectionController::deleteSection',
                'DELETE',
                JSON_OBJECT('page_id', '[0-9]+', 'section_id', '[0-9]+'),
                NULL
            )
        SQL);
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`)
            SELECT ar.id, p.id
            FROM `api_routes` ar
            JOIN `permissions` p ON p.name = 'admin.page.delete'
            WHERE ar.route_name = 'admin_sections_delete'
              AND ar.version = 'v1'
        SQL);
    }
}
