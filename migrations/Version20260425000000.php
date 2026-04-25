<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Register the `GET /cms-api/v1/auth/events` Mercure subscriber bootstrap route.
 *
 * The endpoint returns the discovery payload (`hubUrl`, per-user `topic`,
 * subscriber `token`, `expiresIn`) that the Next.js BFF
 * (`src/app/api/auth/events/route.ts`) needs to open an upstream
 * subscription to the Mercure hub on behalf of the browser. Real-time
 * `acl-changed` events are emitted by
 * {@see \App\EventListener\AclVersionMercurePublisher} on Doctrine
 * `postFlush` whenever `users.acl_version` changes (group/role mutations,
 * profile edits, async job grants), so the menu / admin sidebar /
 * page-content all refresh without the user clicking anything.
 *
 * The route lives under `/auth/*` so the `^/cms-api/v1/auth`
 * `PUBLIC_ACCESS` rule applies — `AuthEventsController::events`
 * authenticates manually via `UserContextService` and returns 401 for
 * anonymous callers, matching how `/auth/user-data` works.
 *
 * The same `INSERT IGNORE` is mirrored in
 * `db/update_scripts/api_routes.sql` so a fresh install bootstrapped
 * from the SQL seed picks the route up too.
 *
 * Setup notes for operators upgrading past this migration: see README.md
 * "Real-time push (Mercure)" — you also need a running Mercure hub
 * (provided as `docker-compose.mercure.yml`) and a shared
 * `MERCURE_JWT_SECRET` between Symfony and the hub.
 */
final class Version20260425000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Register /auth/events SSE route for real-time ACL push';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT IGNORE INTO api_routes
                (route_name, version, methods, path, controller, requirements, params)
            VALUES
                ('auth_events_stream_v1', 'v1', 'GET', '/auth/events',
                 'App\\\\Controller\\\\Api\\\\V1\\\\Auth\\\\AuthEventsController::events',
                 NULL, NULL)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            DELETE FROM api_routes
            WHERE route_name = 'auth_events_stream_v1'
        ");
    }
}
