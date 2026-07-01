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
 * Create the `page_routes` table: the DB-driven, parameterized public route
 * contract for CMS pages (issue #30).
 *
 * One page can own several patterns (e.g. `/reset` plus
 * `/reset/{user_id}/{token}`). `path_pattern` uses Symfony route syntax and
 * `requirements` maps each placeholder to a regex. The
 * {@see \App\Routing\PageRouteResolverService} builds a Symfony RouteCollection
 * from the active rows; {@see \App\Routing\RouteConflictValidator} enforces
 * global active-pattern uniqueness + dynamic ambiguity at the service layer
 * (MySQL 8 has no "active rows only" filtered unique index, so the DB carries
 * only the per-page `uq_page_routes_id_pages_path_pattern` guard).
 *
 * Constraint/index names follow the repo snake_case convention
 * (`fk_<table>_<column>`, `idx_<table>_<column>`, `uq_<table>_<columns>`).
 */
final class Version20260630083439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create page_routes (DB-driven parameterized public route contract for CMS pages).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE page_routes (
                id INT AUTO_INCREMENT NOT NULL,
                id_pages INT NOT NULL,
                path_pattern VARCHAR(255) NOT NULL,
                requirements JSON DEFAULT NULL COMMENT 'Placeholder name -> regex requirement, e.g. {"record_id":"\\\\d+"}',
                is_canonical TINYINT DEFAULT 0 NOT NULL,
                is_active TINYINT DEFAULT 1 NOT NULL,
                priority INT DEFAULT 0 NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME DEFAULT NULL,
                INDEX idx_page_routes_id_pages (id_pages),
                INDEX idx_page_routes_path_pattern (path_pattern),
                INDEX idx_page_routes_is_active (is_active),
                UNIQUE INDEX uq_page_routes_id_pages_path_pattern (id_pages, path_pattern),
                PRIMARY KEY (id),
                CONSTRAINT fk_page_routes_id_pages FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE page_routes');
    }
}
