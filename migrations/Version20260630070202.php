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
 * Normalise two display-name route names so they stop duplicating the API
 * version suffix (issue #56 follow-up).
 *
 * `Version20260626121351` and `Version20260629074552` seeded their `api_routes`
 * rows with a `_v1` suffix baked into `route_name`
 * (`admin_data_table_columns_display_name_patch_v1`,
 * `admin_data_table_display_name_patch_v1`). The API version already lives in
 * `api_routes.version`, so the suffix is redundant and is rejected by
 * {@see \App\Tests\Integration\Routing\ApiRouteInventoryTest::testStoredRouteNamesDoNotDuplicateTheVersionSuffix}.
 *
 * This is a metadata-only rename: only `api_routes.route_name` changes. The
 * path, controller, methods, version and the `rel_api_routes_permissions` links
 * (joined by `id_api_routes`) are untouched, so the routes keep resolving and
 * stay guarded exactly as before. No external contract depends on the internal
 * route name.
 */
final class Version20260630070202 extends AbstractMigration
{
    private const VERSION = 'v1';

    /** @var array<string, string> old route_name => normalised route_name */
    private const RENAMES = [
        'admin_data_table_columns_display_name_patch_v1' => 'admin_data_table_columns_display_name_patch',
        'admin_data_table_display_name_patch_v1' => 'admin_data_table_display_name_patch',
    ];

    public function getDescription(): string
    {
        return 'Drop the redundant _v1 suffix from the data-table display-name api_routes.route_name values.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->renameRoute($old, $new);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->renameRoute($new, $old);
        }
    }

    private function renameRoute(string $from, string $to): void
    {
        $this->addSql(
            'UPDATE `api_routes` SET route_name = ? WHERE route_name = ? AND version = ?',
            [$to, $from, self::VERSION]
        );
    }
}
