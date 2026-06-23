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
 * Normalize API route names so the API version lives only in `api_routes.version`.
 *
 * `ApiRouteLoader` registers Symfony routes as `<route_name>_<version>`. Some
 * historical seed rows accidentally stored names like `admin_users_get_all_v1`
 * with `version = v1`, producing runtime route names ending in `_v1_v1`.
 */
final class Version20260623145211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize api_routes.route_name values by removing duplicated trailing version suffixes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            UPDATE `api_routes` ar
            LEFT JOIN `api_routes` existing
              ON existing.`version` = ar.`version`
             AND existing.`route_name` = LEFT(ar.`route_name`, CHAR_LENGTH(ar.`route_name`) - CHAR_LENGTH(CONCAT('_', ar.`version`)))
             AND existing.`id` <> ar.`id`
            SET ar.`route_name` = LEFT(ar.`route_name`, CHAR_LENGTH(ar.`route_name`) - CHAR_LENGTH(CONCAT('_', ar.`version`)))
            WHERE ar.`version` <> ''
              AND RIGHT(ar.`route_name`, CHAR_LENGTH(CONCAT('_', ar.`version`))) = CONCAT('_', ar.`version`)
              AND existing.`id` IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No-op by design: restoring duplicated route-name suffixes would
        // reintroduce invalid runtime names such as `*_v1_v1`.
    }
}
