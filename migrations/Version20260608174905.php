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
 * Seed the public manager update-loop routes that close the CMS <-> Manager
 * update loop (plan: distribution/update execution).
 *
 *   - GET  /cms-api/v1/manager/system/update/pending
 *   - POST /cms-api/v1/manager/system/update/{operationId}/status
 *
 * Both routes are intentionally PUBLIC (no `rel_api_routes_permissions` rows,
 * exactly like the `health` route in Version20260602091045): the JWT/ACL
 * pipeline skips permission-less routes. Access is gated instead by the
 * per-instance manager bearer token verified in-controller
 * ({@see \App\Controller\Api\V1\Manager\SystemManagerController}). The service
 * layer scopes every read/write to the server-derived instance id, so a manager
 * bound to one instance can never read or affect another instance's operations.
 */
final class Version20260608174905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed public manager update-loop routes (pending claim + status write-back).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'manager_system_update_pending',
                'v1',
                'GET',
                '/manager/system/update/pending',
                'App\\\\Controller\\\\Api\\\\V1\\\\Manager\\\\SystemManagerController::getPending',
                NULL,
                NULL
            )
        SQL);

        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `methods`, `path`, `controller`, `requirements`, `params`)
            VALUES (
                'manager_system_update_status',
                'v1',
                'POST',
                '/manager/system/update/{operationId}/status',
                'App\\\\Controller\\\\Api\\\\V1\\\\Manager\\\\SystemManagerController::postStatus',
                JSON_OBJECT('operationId', '[A-Za-z0-9._-]+'),
                NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No permission links were created for these public routes, so a
        // straight delete is enough (no-op if they were never seeded).
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` IN ('manager_system_update_pending', 'manager_system_update_status') AND `version` = 'v1'");
    }
}
