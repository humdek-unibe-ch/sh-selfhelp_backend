<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Demote the lookups endpoint from "admin" to "system" status.
 *
 * Two coupled changes:
 *
 *   1. Rename the route. `admin_lookups` becomes `system_lookups`, and the
 *      URL moves from `/admin/lookups` to `/lookups`. Living under the
 *      `/admin/*` prefix was misleading: the response is pure reference
 *      data (timezones, type codes, weekdays, audit categories) consumed
 *      by both admin tooling AND public frontend styles such as
 *      `ProfileStyle` (timezone selector). The new path matches the
 *      project's existing convention for shared reference endpoints
 *      (`/languages`).
 *
 *   2. Drop the `admin.access` permission row. Gating the endpoint on
 *      `admin.access` produced a hard regression where any non-admin
 *      authenticated user — natural login OR an admin's impersonation
 *      session targeting a non-admin — got `403 Forbidden` the first
 *      time they opened their own profile page. The endpoint still
 *      requires authentication via the JWT firewall (anonymous callers
 *      get 401), but any logged-in user can now read it.
 *
 * Mirrored in `db/update_scripts/api_routes.sql` so fresh installs boot
 * with the same shape.
 *
 * @see docs/developer/03-authentication-authorization.md "Effective-identity rule"
 */
final class Version20260508160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demote /admin/lookups to /lookups (system_lookups), drop admin.access — used by ProfileStyle';
    }

    public function up(Schema $schema): void
    {
        // (1) Drop the admin.access permission row, if it exists.
        $this->addSql("
            DELETE arp
            FROM api_routes_permissions arp
            JOIN api_routes ar ON ar.id = arp.id_api_routes
            JOIN permissions p ON p.id = arp.id_permissions
            WHERE ar.route_name = 'admin_lookups'
              AND p.name = 'admin.access'
        ");

        // (2) Rename the route and move it out of the /admin/* namespace.
        $this->addSql("
            UPDATE api_routes
            SET route_name = 'system_lookups',
                path = '/lookups'
            WHERE route_name = 'admin_lookups'
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            UPDATE api_routes
            SET route_name = 'admin_lookups',
                path = '/admin/lookups'
            WHERE route_name = 'system_lookups'
        ");

        $this->addSql("
            INSERT IGNORE INTO api_routes_permissions (id_api_routes, id_permissions)
            SELECT ar.id, p.id
            FROM api_routes ar
            JOIN permissions p ON p.name = 'admin.access'
            WHERE ar.route_name = 'admin_lookups'
        ");
    }
}
