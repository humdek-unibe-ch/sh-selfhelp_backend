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
 * Registers the public password-recovery endpoints in api_routes:
 *
 *   - POST /auth/forgot-password -> AuthController::forgotPassword
 *   - POST /auth/reset-password  -> AuthController::resetPassword
 *
 * Both are public (no permission row needed) — the same pattern used by
 * /auth/login and /auth/register. The actions live in AuthController and
 * delegate to PasswordResetService.
 */
final class Version20260605144355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public POST /auth/forgot-password and /auth/reset-password API routes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'auth_forgot_password',
                'v1',
                'POST',
                '/auth/forgot-password',
                'App\\\\Controller\\\\Api\\\\V1\\\\Auth\\\\AuthController::forgotPassword',
                NULL,
                NULL
            )
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'auth_reset_password',
                'v1',
                'POST',
                '/auth/reset-password',
                'App\\\\Controller\\\\Api\\\\V1\\\\Auth\\\\AuthController::resetPassword',
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
            WHERE ar.`route_name` IN ('auth_forgot_password', 'auth_reset_password')
              AND ar.`version` = 'v1'
        SQL);
        $this->addSql(<<<SQL
            DELETE FROM `api_routes`
            WHERE `route_name` IN ('auth_forgot_password', 'auth_reset_password')
              AND `version` = 'v1'
        SQL);
    }
}
