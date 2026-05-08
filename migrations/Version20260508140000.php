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
 * Register the `POST /cms-api/v1/admin/users/stop-impersonate` route.
 *
 * Backs the impersonation hardening shipped in v8.0.0:
 *
 *   - {@see \App\Controller\Api\V1\Admin\AdminUserController::stopImpersonateUser}
 *     is the controller; it reads the JWT from the Authorization header,
 *     verifies the `impersonation: true` claim, blacklists the token via
 *     {@see \App\Service\Auth\JWTService::blacklistAccessToken}, and writes
 *     an audit row in `transactions`.
 *
 *   - The route is referenced by name from
 *     {@see \App\EventListener\ApiSecurityListener::IMPERSONATION_ALWAYS_ALLOWED_ROUTE}
 *     — keep that constant in sync if this name ever changes.
 *
 *   - No `api_routes_permissions` row is registered: while impersonating,
 *     the request authenticates as the *target* user (who typically lacks
 *     `admin.user.impersonate`). The controller itself verifies the
 *     impersonation flag before doing anything destructive, so any
 *     authenticated request can hit the endpoint safely.
 *
 * The same `INSERT IGNORE` is mirrored in `db/update_scripts/api_routes.sql`
 * so fresh installs bootstrapped from the SQL seed pick the route up.
 *
 * @see docs/api-usage/07-admin-users.md "Stop impersonating"
 * @see docs/developer/03-authentication-authorization.md "Impersonation"
 */
final class Version20260508140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register /admin/users/stop-impersonate route for impersonation lifecycle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT IGNORE INTO api_routes
                (route_name, version, methods, path, controller, requirements, params)
            VALUES
                ('admin_users_stop_impersonate_v1', 'v1', 'POST', '/admin/users/stop-impersonate',
                 'App\\\\Controller\\\\Api\\\\V1\\\\Admin\\\\AdminUserController::stopImpersonateUser',
                 NULL, NULL)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            DELETE FROM api_routes
            WHERE route_name = 'admin_users_stop_impersonate_v1'
        ");
    }
}
