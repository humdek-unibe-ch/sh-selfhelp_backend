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
 * Live sync follow-ups (F259): GET page lock is readable by delete and publish callers.
 *
 * Adds `admin.page.delete`, `admin.page_version.publish` and
 * `admin.page_version.delete` to `admin_pages_lock_get` (alongside the existing
 * `admin.page.update`). Acquire, release and take-over stay update-only.
 * Data-only — generated with `doctrine:migrations:generate` (no schema diff).
 */
final class Version20261123112023 extends AbstractMigration
{
    private const ROUTE = 'admin_pages_lock_get';

    private const VERSION = 'v1';

    /** @var list<string> */
    private const ADDED = [
        'admin.page.delete',
        'admin.page_version.publish',
        'admin.page_version.delete',
    ];

    public function getDescription(): string
    {
        return 'Allow delete and publish callers to read the page edit lock status (admin_pages_lock_get)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ADDED as $permission) {
            $this->addSql(
                'INSERT IGNORE INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id FROM api_routes ar INNER JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$permission, self::ROUTE, self::VERSION]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ADDED as $permission) {
            $this->addSql(
                'DELETE rarp FROM rel_api_routes_permissions rarp '
                . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
                . 'INNER JOIN permissions p ON p.id = rarp.id_permissions '
                . 'WHERE ar.route_name = ? AND ar.version = ? AND p.name = ?',
                [self::ROUTE, self::VERSION, $permission]
            );
        }
    }
}
