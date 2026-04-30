<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260430131025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Added bulk sections remove api route';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
        INSERT INTO selfhelp2.api_routes
        (
            route_name,
            version,
            path,
            controller,
            methods,
            requirements,
            params
        )
        VALUES
        (
            'admin_pages_bulk_remove_sections',
            'v1',
            '/admin/pages/{page_id}/sections',
            'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminPageController::bulkRemoveSectionsFromPage',
            'DELETE',
            JSON_OBJECT('page_id', '[0-9]+'),
            JSON_OBJECT(
                'sectionIds', JSON_OBJECT(
                    'in', 'body',
                    'required', true,
                    'type', 'array'
                )
            )
        )
    ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
        DELETE FROM selfhelp2.api_routes
        WHERE route_name = 'admin_pages_bulk_remove_sections'
          AND version = 'v1'
    ");
    }
}
