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
 * Register the ACL-enforced asset delivery route:
 *   GET /cms-api/v1/assets/{folder}/{filename}
 *
 * Deliberately registered WITHOUT a permission link (like the public `health`
 * and `plugins_manifest` routes). Access is resource-side, decided per folder by
 * AssetFolderAclService: an open-access folder must be readable by anonymous
 * callers, a closed folder only by the caller's granted groups. A route-level
 * permission would wrongly 403 anonymous readers of public folders.
 *
 * This route replaces static delivery from public/uploads/assets/, which
 * bypassed PHP entirely (Apache serves an existing file before index.php runs)
 * and therefore made folder ACLs unenforceable.
 */
final class Version20260728091825 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const ROUTE_NAME = 'assets_serve';

    public function getDescription(): string
    {
        return 'Register the ACL-enforced asset delivery route (/cms-api/v1/assets/{folder}/{filename}).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
            [self::ROUTE_NAME, self::VERSION]
        );
        $this->addSql(
            'INSERT INTO api_routes (route_name, version, path, controller, methods, requirements, params, id_plugins) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
            [
                self::ROUTE_NAME,
                self::VERSION,
                '/assets/{folder}/{filename}',
                'App\\Controller\\Api\\V1\\AssetController::serve',
                'GET',
                // filename allows dots (extensions) but no slashes; traversal is
                // additionally rejected in AssetStorageService::resolveForDelivery.
                '{"folder":"[a-zA-Z0-9_-]+","filename":"[a-zA-Z0-9_.-]+"}',
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE rarp FROM rel_api_routes_permissions rarp '
            . 'INNER JOIN api_routes ar ON ar.id = rarp.id_api_routes '
            . 'WHERE ar.route_name = ? AND ar.version = ?',
            [self::ROUTE_NAME, self::VERSION]
        );
        $this->addSql(
            'DELETE FROM api_routes WHERE route_name = ? AND version = ?',
            [self::ROUTE_NAME, self::VERSION]
        );
    }
}
