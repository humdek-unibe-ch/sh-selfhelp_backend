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
 * Tightens the admin plugin-detail route so reserved static slugs
 * (`available`, `sources`, `operations`, `doctor`) do not get treated
 * as plugin ids by `GET /admin/plugins/{pluginId}`.
 *
 * This keeps the routing rule explicit in the route metadata instead
 * of relying on runtime route-order heuristics in `ApiRouteLoader`.
 */
final class Version20260522124403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plugin manager: exclude reserved admin plugin slugs from admin_plugins_get.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE `api_routes`
            SET `requirements` = JSON_OBJECT(
                'pluginId',
                '(?!(?:available|sources|operations|doctor)$)[a-z][a-z0-9-]*'
            )
            WHERE `route_name` = 'admin_plugins_get'
              AND `version` = 'v1'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE `api_routes`
            SET `requirements` = JSON_OBJECT(
                'pluginId',
                '[a-z][a-z0-9-]*'
            )
            WHERE `route_name` = 'admin_plugins_get'
              AND `version` = 'v1'
        SQL);
    }
}
