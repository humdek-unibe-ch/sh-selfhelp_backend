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
 * Registers the POST /auth/register endpoint in api_routes.
 *
 * The route is public (no permission row needed) — the same pattern used by
 * /auth/login. The action lives in AuthController. The service reads
 * open_registration and group_id server-side from the CMS section.
 */
final class Version20260528073304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add POST /auth/register API route.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'register',
                'v1',
                'POST',
                '/auth/register',
                'App\\\\Controller\\\\Api\\\\V1\\\\Auth\\\\AuthController::register',
                NULL,
                NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE rarp FROM `rel_api_routes_permissions` rarp
            JOIN `api_routes` ar ON ar.id = rarp.id_api_routes
            WHERE ar.`route_name` = 'register' AND ar.`version` = 'v1'
        SQL);
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'auth_register' AND `version` = 'v1'");
    }
}
