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
 * Live sync (2026-11-20): the page draft version and the page edit lock.
 *
 * - `pages.draft_version`: counts the writes of a page's draft. Every draft
 *   write sends the value it saw (`X-Page-Version`) and a write based on an older
 *   value is refused with 409 (contract C8).
 * - Four routes for the edit lock, a lease kept in the cache store, no table
 *   (contract C9): status, acquire/renew, release and take over. They need
 *   `admin.page.update` and the update grant on the page, like the draft writes.
 *
 * The column came from `doctrine:migrations:diff`; the diff also listed the
 * `acl_version` columns of the previous migration (the dev database had not run
 * it), which were removed here so each migration owns its own change.
 */
final class Version20261120115909 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const PERMISSION = 'admin.page.update';
    private const REQUIREMENTS = '{"page_id": "[0-9]+"}';
    private const PARAMS = '{"page_id": {"in": "path", "required": true}}';

    /** Routes added in up() and removed in down(). */
    private const NEW_ROUTES = [
        ['admin_pages_lock_get', '/admin/pages/{page_id}/lock', 'App\\Controller\\Api\\V1\\Admin\\AdminPageLockController::getLock', 'GET'],
        ['admin_pages_lock_acquire', '/admin/pages/{page_id}/lock', 'App\\Controller\\Api\\V1\\Admin\\AdminPageLockController::acquireLock', 'POST'],
        ['admin_pages_lock_release', '/admin/pages/{page_id}/lock', 'App\\Controller\\Api\\V1\\Admin\\AdminPageLockController::releaseLock', 'DELETE'],
        ['admin_pages_lock_take_over', '/admin/pages/{page_id}/lock/take-over', 'App\\Controller\\Api\\V1\\Admin\\AdminPageLockController::takeOverLock', 'POST'],
    ];

    public function getDescription(): string
    {
        return 'Page draft version (pages.draft_version) and the four page edit lock routes (live sync C8, C9)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pages ADD draft_version INT DEFAULT 1 NOT NULL');

        foreach (self::NEW_ROUTES as [$name, $path, $controller, $method]) {
            $this->addSql(
                'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, NULL)',
                [$name, self::VERSION, $path, $controller, $method, self::REQUIREMENTS, self::PARAMS]
            );
            $this->addSql(
                'INSERT INTO rel_api_routes_permissions (id_api_routes, id_permissions) '
                . 'SELECT ar.id, p.id FROM api_routes ar INNER JOIN permissions p ON p.name = ? '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [self::PERMISSION, $name, self::VERSION]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::NEW_ROUTES as [$name]) {
            $this->addSql(
                'DELETE rarp FROM rel_api_routes_permissions rarp '
                . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
                . 'WHERE ar.route_name = ? AND ar.version = ?',
                [$name, self::VERSION]
            );
            $this->addSql('DELETE FROM api_routes WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
        }

        $this->addSql('ALTER TABLE pages DROP draft_version');
    }
}
