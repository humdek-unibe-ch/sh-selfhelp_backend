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
 * Register the public path-resolution API route (issue #30):
 *   GET /cms-api/v1/pages/resolve?path=...&language_id=...&preview=...
 *
 * Open-access by design (NO `rel_api_routes_permissions` row), exactly like
 * `pages_get_by_keyword`: the resolver only maps a path to a page, and the
 * resolved page still applies full page ACL / published-draft / preview /
 * platform / data-access security inside `PageService::getPageByPublicPath()`.
 *
 * The path is static (`/pages/resolve`) so `ApiRouteLoader`'s
 * static-before-dynamic ordering keeps it from being shadowed by
 * `/pages/by-keyword/{keyword}`. INSERT IGNORE on the (route_name, version)
 * unique key keeps the migration idempotent.
 */
final class Version20260630084409 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the open-access GET /pages/resolve api_route (DB-driven public path resolution).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`)
            VALUES (
                'pages_resolve_path',
                'v1',
                '/pages/resolve',
                'App\\Controller\\Api\\V1\\Frontend\\PageController::resolvePublicPath',
                'GET',
                NULL,
                JSON_OBJECT(
                    'path', JSON_OBJECT('in', 'query', 'required', true),
                    'language_id', JSON_OBJECT('in', 'query', 'required', false),
                    'preview', JSON_OBJECT('in', 'query', 'required', false)
                )
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'pages_resolve_path' AND `version` = 'v1'");
    }
}
