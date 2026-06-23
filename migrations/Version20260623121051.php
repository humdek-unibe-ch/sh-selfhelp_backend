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
 * CMS mobile preview session routes (mobile-preview service).
 *
 * Seeds two `/cms-api/v1` routes:
 *   - admin_mobile_preview_session (POST /admin/mobile-preview/session) — gated
 *     by the NEW `admin.mobile_preview.create` permission (granted to the admin
 *     role). Mints the one-time preview code for the page-editor panel.
 *   - mobile_preview_session_exchange (POST /mobile-preview/session/exchange) —
 *     PUBLIC, like `health` / `plugins_manifest` (no row in
 *     `rel_api_routes_permissions`). The one-time code IS the credential; the
 *     route returns a short-lived scoped preview JWT.
 *
 * Follows the additive route-seeding pattern of Version20260605081254 /
 * Version20260602091045. No schema change.
 */
final class Version20260623121051 extends AbstractMigration
{
    private const VERSION = 'v1';

    public function getDescription(): string
    {
        return 'Seed CMS mobile-preview session routes (admin mint + public exchange) and the admin.mobile_preview.create permission.';
    }

    public function up(Schema $schema): void
    {
        // --- Permission: admin.mobile_preview.create (granted to admin role) ---
        $this->addSql("INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES ('admin.mobile_preview.create', 'Can mint CMS mobile-preview sessions')");
        $this->addSql(<<<SQL
            INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`)
            SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = 'admin'
            WHERE p.name = 'admin.mobile_preview.create'
        SQL);

        // --- Admin mint route (JWT + admin.mobile_preview.create) ---
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', ['admin_mobile_preview_session', self::VERSION]);
        $this->addSql(
            'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
            [
                'admin_mobile_preview_session',
                self::VERSION,
                '/admin/mobile-preview/session',
                'App\\Controller\\Api\\V1\\Admin\\AdminMobilePreviewController::createSession',
                'POST',
            ]
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
            . "SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? WHERE ar.route_name = ? AND ar.version = ?",
            ['admin.mobile_preview.create', 'admin_mobile_preview_session', self::VERSION]
        );

        // --- Public exchange route (no permission link, like `health`) ---
        $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', ['mobile_preview_session_exchange', self::VERSION]);
        $this->addSql(
            'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
            [
                'mobile_preview_session_exchange',
                self::VERSION,
                '/mobile-preview/session/exchange',
                'App\\Controller\\Api\\V1\\MobilePreviewController::exchange',
                'POST',
            ]
        );
    }

    public function down(Schema $schema): void
    {
        foreach (['admin_mobile_preview_session', 'mobile_preview_session_exchange'] as $routeName) {
            $this->addSql(
                'DELETE rarp FROM `rel_api_routes_permissions` rarp JOIN `api_routes` ar ON ar.id = rarp.id_api_routes WHERE ar.route_name = ? AND ar.version = ?',
                [$routeName, self::VERSION]
            );
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$routeName, self::VERSION]);
        }

        $this->addSql("DELETE rpr FROM `rel_permissions_roles` rpr JOIN `permissions` p ON p.id = rpr.id_permissions WHERE p.name = 'admin.mobile_preview.create'");
        $this->addSql("DELETE FROM `permissions` WHERE name = 'admin.mobile_preview.create'");
    }
}
