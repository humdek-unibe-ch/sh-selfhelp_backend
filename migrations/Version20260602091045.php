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
 * Public deployment readiness route: seeds the `/cms-api/v1/health`
 * route into `api_routes` (plan §18.3, deployment-readiness slice).
 *
 * The route is intentionally PUBLIC: no row is added to
 * `rel_api_routes_permissions`, exactly like the `plugins_manifest`
 * public route (see Version20260522062459). The controller
 * ({@see \App\Controller\Api\V1\HealthController}) is read-only and
 * returns a minimal `{status, database}` payload that leaks nothing,
 * so post-deploy smoke checks and orchestrator readiness probes can
 * call it unauthenticated.
 */
final class Version20260602091045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the public /cms-api/v1/health deployment readiness route.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'health',
                'v1',
                'GET',
                '/health',
                'App\\\\Controller\\\\Api\\\\V1\\\\HealthController::health',
                NULL,
                NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No permission links were created for this public route, so a
        // straight delete is enough (and is a no-op if it was never seeded).
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'health' AND `version` = 'v1'");
    }
}
