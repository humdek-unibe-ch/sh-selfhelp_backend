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
 * Security hardening for anonymous (unauthenticated) frontend access.
 *
 * Two cohesive changes that follow from fixing the "anonymous request is
 * treated as user id 1 (admin)" ACL bug in the service layer:
 *
 * 1. Mark the global styling pages `sh-global-css` and `sh-global-values`
 *    as `is_open_access = 1`. Once anonymous callers stop inheriting the
 *    admin group's ACLs they only see open-access pages; these two pages
 *    must render for public visitors (e.g. the login screen needs global
 *    CSS/values before authentication). `sh-cms-preferences` is left
 *    private on purpose.
 *
 * 2. Remove the duplicate `pages_get_one` API route
 *    (`GET /pages/{page_id}`). Page content is resolved exclusively by
 *    keyword (`GET /pages/by-keyword/{keyword}`) across web, mobile and the
 *    shared client; the numeric-id route was unused legacy surface. After
 *    this migration the API-route cache must be cleared
 *    (`php bin/console cache:clear-api-routes`) so the loader drops the row.
 */
final class Version20260623082726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden anonymous access: open sh-global-css/sh-global-values, remove duplicate pages_get_one route.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE `pages` SET `is_open_access` = 1 WHERE `keyword` IN ('sh-global-css', 'sh-global-values')"
        );

        // Remove any permission links first (defensive — this route ships
        // without permission rows), then the route row itself.
        $this->addSql(<<<SQL
            DELETE rap FROM `rel_api_routes_permissions` rap
            JOIN `api_routes` ar ON ar.id = rap.id_api_routes
            WHERE ar.route_name = 'pages_get_one' AND ar.version = 'v1'
        SQL);
        $this->addSql("DELETE FROM `api_routes` WHERE `route_name` = 'pages_get_one' AND `version` = 'v1'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE `pages` SET `is_open_access` = 0 WHERE `keyword` IN ('sh-global-css', 'sh-global-values')"
        );

        // Restore the numeric-id page route exactly as it was seeded.
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `api_routes`
                (`route_name`, `version`, `path`, `controller`, `methods`, `requirements`, `params`)
            VALUES (
                'pages_get_one',
                'v1',
                '/pages/{page_id}',
                'App\\\\Controller\\\\Api\\\\V1\\\\Frontend\\\\PageController::getPage',
                'GET',
                JSON_OBJECT('page_id', '[0-9]+'),
                NULL
            )
        SQL);
    }
}
