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
 * Seed the initial `page_routes` contract for the baseline pages (issue #30).
 *
 * DESIGN DECISION — curated list, NOT a blind `pages.url` conversion.
 * ------------------------------------------------------------------
 * This migration seeds an EXPLICIT, curated {@see self::ROUTES} list keyed by
 * page keyword rather than auto-converting every `pages.url` into a route. That
 * is deliberate:
 *   - The parameterized auth flows (`/reset/{user_id}/{token}`,
 *     `/validate/{user_id}/{token}`) need typed `requirements` and canonical
 *     ordering that CANNOT be inferred safely from the legacy AltoRouter
 *     `[i:uid]/[a:token]` syntax stored in `pages.url`.
 *   - A blind `pages.url` -> pattern pass would risk creating duplicate active
 *     patterns (`pages.url` is not globally unique), which the resolver rejects
 *     loudly, and would emit malformed patterns for any non-Symfony URL.
 *   - This is a fresh-baseline seed: every page that exists at this point is a
 *     seeded core page with a known, stable slug, all of which are enumerated
 *     below. Custom pages created afterwards receive their routes through the
 *     admin editor's Routes panel (`PageRouteService::syncRoutes()` on
 *     create/update), the page importer, or the CMS-app wizard — so no page is
 *     left unroutable.
 * The curated set covers:
 *   - reset-password: `/reset` (canonical) + `/reset/{user_id}/{token}` + the
 *     legacy `/reset-password` alias.
 *   - validate: `/validate/{user_id}/{token}` (canonical), replacing the legacy
 *     AltoRouter `[i:uid]/[a:token]` syntax.
 *   - home: `/home` (canonical) + `/` alias so the site root resolves.
 *   - every other static core page by its slug.
 *
 * Token placeholders use the safe, non-hex-only regex `[A-Za-z0-9._~-]+` and
 * `user_id` uses `\d+` (decisions from issue #30). Pages with an empty
 * `pages.url` (the `sh-*` config/value holder pages) are intentionally absent
 * because they are not publicly resolvable.
 *
 * Routes are matched to pages by keyword (ids differ across installs) and use
 * `INSERT IGNORE` on the per-page unique key so the migration is idempotent.
 * Requirements are passed as bound JSON parameters to avoid SQL/JSON
 * backslash-escaping pitfalls.
 */
final class Version20260630083708 extends AbstractMigration
{
    /**
     * Initial route contract.
     *
     * @var list<array{keyword:string, pattern:string, requirements:array<string,string>|null, canonical:bool, priority:int}>
     */
    private const ROUTES = [
        ['keyword' => 'login', 'pattern' => '/login', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'home', 'pattern' => '/home', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'home', 'pattern' => '/', 'requirements' => null, 'canonical' => false, 'priority' => 10],
        ['keyword' => 'missing', 'pattern' => '/missing', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'no-access', 'pattern' => '/no-access', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'no-access-guest', 'pattern' => '/no-access-guest', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'agb', 'pattern' => '/agb', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'impressum', 'pattern' => '/impressum', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'disclaimer', 'pattern' => '/disclaimer', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'privacy', 'pattern' => '/privacy', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'profile', 'pattern' => '/profile', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'register', 'pattern' => '/register', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'maintenance', 'pattern' => '/maintenance', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'two-factor-authentication', 'pattern' => '/two-factor-authentication', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'validate', 'pattern' => '/validate/{user_id}/{token}', 'requirements' => ['user_id' => '\\d+', 'token' => '[A-Za-z0-9._~-]+'], 'canonical' => true, 'priority' => 50],
        ['keyword' => 'reset-password', 'pattern' => '/reset', 'requirements' => null, 'canonical' => true, 'priority' => 100],
        ['keyword' => 'reset-password', 'pattern' => '/reset/{user_id}/{token}', 'requirements' => ['user_id' => '\\d+', 'token' => '[A-Za-z0-9._~-]+'], 'canonical' => false, 'priority' => 50],
        ['keyword' => 'reset-password', 'pattern' => '/reset-password', 'requirements' => null, 'canonical' => false, 'priority' => 90],
    ];

    public function getDescription(): string
    {
        return 'Seed initial page_routes contract (reset/validate parameterized, home root alias) from existing pages.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROUTES as $route) {
            $this->addSql(
                'INSERT IGNORE INTO page_routes (id_pages, path_pattern, requirements, is_canonical, is_active, priority, created_at) '
                . 'SELECT p.id, ?, ?, ?, 1, ?, NOW() FROM pages p WHERE p.keyword = ?',
                [
                    $route['pattern'],
                    $route['requirements'] !== null ? json_encode($route['requirements']) : null,
                    $route['canonical'] ? 1 : 0,
                    $route['priority'],
                    $route['keyword'],
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::ROUTES as $route) {
            $this->addSql(
                'DELETE pr FROM page_routes pr '
                . 'INNER JOIN pages p ON p.id = pr.id_pages '
                . 'WHERE p.keyword = ? AND pr.path_pattern = ?',
                [$route['keyword'], $route['pattern']]
            );
        }
    }
}
