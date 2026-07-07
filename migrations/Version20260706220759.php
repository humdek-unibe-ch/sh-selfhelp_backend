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
 * Dashboard analytics + branding options + headless auth pages:
 *
 * - `page_views` / `page_view_referrers`: anonymous daily page-view
 *   aggregates written by PageViewTrackerService (visitor hash rotates per
 *   day; no PII stored).
 * - `admin.analytics.read` permission + the two admin analytics API routes
 *   (`/admin/analytics/summary`, `/admin/analytics/today`).
 * - navigation_settings: brand block `logo_size` (sm|md|lg|xl) and
 *   `logo_variant` (logo-and-name|logo-only|name-only).
 * - `reset-password`, `validate`, and `maintenance` system pages become
 *   headless so the auth flow renders as standalone cards like login/register.
 */
final class Version20260706220759 extends AbstractMigration
{
    private const VERSION = 'v1';
    private const PERM_ANALYTICS = 'admin.analytics.read';
    private const HEADLESS_KEYWORDS = ['reset-password', 'validate', 'maintenance'];

    public function getDescription(): string
    {
        return 'Analytics tables/routes/permission, branding logo size+variant, headless reset-password/validate/maintenance.';
    }

    public function up(Schema $schema): void
    {
        // ENGINE=InnoDB is explicit: MyISAM-default servers silently drop FK definitions.
        $this->addSql('CREATE TABLE page_view_referrers (id INT AUTO_INCREMENT NOT NULL, view_date DATE NOT NULL, referrer_host VARCHAR(190) NOT NULL, views INT DEFAULT 1 NOT NULL, INDEX idx_page_view_referrers_view_date (view_date), UNIQUE INDEX uq_page_view_referrers_day_host (view_date, referrer_host), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE page_views (id INT AUTO_INCREMENT NOT NULL, view_date DATE NOT NULL, platform VARCHAR(16) NOT NULL, visitor_hash VARCHAR(32) NOT NULL, views INT DEFAULT 1 NOT NULL, id_pages INT NOT NULL, INDEX idx_page_views_view_date (view_date), INDEX idx_page_views_id_pages (id_pages), UNIQUE INDEX uq_page_views_day_page_platform_visitor (view_date, id_pages, platform, visitor_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE page_views ADD CONSTRAINT FK_77716351CEF1A445 FOREIGN KEY (id_pages) REFERENCES pages (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE navigation_settings ADD logo_size VARCHAR(8) DEFAULT \'md\' NOT NULL, ADD logo_variant VARCHAR(16) DEFAULT \'logo-and-name\' NOT NULL');

        $this->addSql(
            "INSERT IGNORE INTO `permissions` (`name`, `description`) VALUES (?, 'Read dashboard analytics (page views, today operations)')",
            [self::PERM_ANALYTICS],
        );
        $this->addSql(
            'INSERT IGNORE INTO `rel_permissions_roles` (`id_permissions`, `id_roles`) '
            . 'SELECT p.id, r.id FROM `permissions` p JOIN `roles` r ON r.name = ? WHERE p.name = ?',
            ['admin', self::PERM_ANALYTICS],
        );

        $routes = [
            ['admin_analytics_summary', '/admin/analytics/summary', 'App\\Controller\\Api\\V1\\Admin\\AdminAnalyticsController::getSummary', 'GET'],
            ['admin_analytics_today', '/admin/analytics/today', 'App\\Controller\\Api\\V1\\Admin\\AdminAnalyticsController::getToday', 'GET'],
        ];
        foreach ($routes as [$name, $path, $controller, $methods]) {
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$name, self::VERSION]);
            $this->addSql(
                'INSERT INTO `api_routes` (route_name, version, path, controller, methods, requirements, params, id_plugins) VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)',
                [$name, self::VERSION, $path, $controller, $methods],
            );
            $this->addSql(
                'INSERT IGNORE INTO `rel_api_routes_permissions` (`id_api_routes`, `id_permissions`) '
                . 'SELECT ar.id, p.id FROM `api_routes` ar JOIN `permissions` p ON p.name = ? WHERE ar.route_name = ? AND ar.version = ?',
                [self::PERM_ANALYTICS, $name, self::VERSION],
            );
        }

        foreach (self::HEADLESS_KEYWORDS as $keyword) {
            $this->addSql('UPDATE pages SET is_headless = 1 WHERE keyword = ? AND is_system = 1', [$keyword]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::HEADLESS_KEYWORDS as $keyword) {
            $this->addSql('UPDATE pages SET is_headless = 0 WHERE keyword = ? AND is_system = 1', [$keyword]);
        }

        foreach (['admin_analytics_summary', 'admin_analytics_today'] as $routeName) {
            $this->addSql('DELETE FROM `rel_api_routes_permissions` WHERE id_api_routes IN (SELECT id FROM `api_routes` WHERE route_name = ? AND version = ?)', [$routeName, self::VERSION]);
            $this->addSql('DELETE FROM `api_routes` WHERE route_name = ? AND version = ?', [$routeName, self::VERSION]);
        }
        $this->addSql('DELETE FROM `rel_permissions_roles` WHERE id_permissions IN (SELECT id FROM `permissions` WHERE name = ?)', [self::PERM_ANALYTICS]);
        $this->addSql('DELETE FROM `permissions` WHERE name = ?', [self::PERM_ANALYTICS]);

        $this->addSql('ALTER TABLE page_views DROP FOREIGN KEY FK_77716351CEF1A445');
        $this->addSql('DROP TABLE page_view_referrers');
        $this->addSql('DROP TABLE page_views');
        $this->addSql('ALTER TABLE navigation_settings DROP logo_size, DROP logo_variant');
    }
}
