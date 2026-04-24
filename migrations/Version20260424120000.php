<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Register API routes for the style schema and AI section prompt template endpoints.
 *
 * - GET /cms-api/v1/admin/styles/schema (admin.access)
 *     Exposes the full style/field schema so the frontend codegen script,
 *     the import pre-validation pass, and the prompt-template generator can share
 *     one source of truth.
 *
 * - GET /cms-api/v1/admin/ai/section-prompt-template (admin.page.export)
 *     Static file-serve of the generated AI prompt template markdown
 *     (`docs/AI Prompts/ai_section_generation_prompt.md`). Used by the
 *     `Copy AI prompt` button in the admin UI.
 */
final class Version20260424120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register styles/schema and AI section-prompt-template API routes';
    }

    public function up(Schema $schema): void
    {
        // styles/schema
        $this->addSql("
            INSERT IGNORE INTO api_routes
                (route_name, version, methods, path, controller, requirements, params)
            VALUES
                ('admin_styles_schema_get', 'v1', 'GET', '/admin/styles/schema',
                 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminStyleController::getStylesSchema',
                 '[]', '[]')
        ");
        $this->addSql("
            INSERT IGNORE INTO api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.access'
            WHERE ar.route_name = 'admin_styles_schema_get'
        ");

        // ai/section-prompt-template
        $this->addSql("
            INSERT IGNORE INTO api_routes
                (route_name, version, methods, path, controller, requirements, params)
            VALUES
                ('admin_ai_section_prompt_template_get', 'v1', 'GET', '/admin/ai/section-prompt-template',
                 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminStyleController::getSectionPromptTemplate',
                 '[]', '[]')
        ");
        $this->addSql("
            INSERT IGNORE INTO api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.page.export'
            WHERE ar.route_name = 'admin_ai_section_prompt_template_get'
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            DELETE arp FROM api_routes_permissions arp
            JOIN api_routes ar ON ar.id = arp.id_api_routes
            WHERE ar.route_name IN ('admin_styles_schema_get', 'admin_ai_section_prompt_template_get')
        ");
        $this->addSql("
            DELETE FROM api_routes
            WHERE route_name IN ('admin_styles_schema_get', 'admin_ai_section_prompt_template_get')
        ");
    }
}
