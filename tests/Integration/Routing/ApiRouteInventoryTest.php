<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Routing;

use App\Repository\ApiRouteRepository;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\RouterInterface;

/**
 * Guardrail inventory for the database-backed `/cms-api` route system
 * (plan "Test Foundation And Guardrails").
 *
 * Every row in `api_routes` is loaded by {@see \App\Routing\ApiRouteLoader}
 * into the router collection and dispatched by name. These tests load the
 * REAL router collection and assert, for every DB route:
 *   - it is registered in the collection under `<route_name>_<version>`;
 *   - its `_controller` resolves to an existing `Class::method`;
 *   - it declares at least one valid HTTP method;
 *   - it is permission-guarded UNLESS it is a known, reviewed public route.
 *
 * The public allowlist makes adding a new UNGUARDED core route impossible to
 * do silently: the route either gets a permission row or a conscious entry
 * here (plan "permission coverage checklist"). The list is derived from the
 * current seeded baseline; every entry is a route that authenticates via JWT
 * + internal ACL/self checks rather than a route-permission row.
 */
#[Group('security')]
final class ApiRouteInventoryTest extends QaKernelTestCase
{
    /** @var list<string> */
    private const VALID_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    /**
     * Core (non-plugin) `/cms-api` routes that intentionally carry NO route
     * permission. Each relies on JWT authentication plus an internal ACL /
     * self / always-allowed check instead of a `rel_api_routes_permissions`
     * row. Keys are the router-collection names (`<route_name>_<version>`).
     *
     * @var list<string>
     */
    private const INTENTIONALLY_PUBLIC = [
        // Auth / session lifecycle.
        'auth_login_v1',
        'auth_logout_v1',
        'auth_refresh_token_v1',
        'auth_set_language_v1',
        'auth_2fa_verify_v1',
        'auth_events_stream_v1_v1',
        // Public self-registration (creates a blocked account + validation email).
        'auth_register_v1',
        // Self-service profile: JWT-authenticated, operates only on the caller.
        'auth_user_data_get_v1_v1',
        'auth_user_account_delete_v1_v1',
        'auth_user_name_update_v1_v1',
        'auth_user_password_update_v1_v1',
        'auth_user_timezone_update_v1_v1',
        // Stop-impersonation is ALWAYS allowed by ApiSecurityListener (only
        // way out of an impersonation session).
        'admin_users_stop_impersonate_v1_v1',
        // Frontend forms enforce their own page/section ACL inside the service.
        'form_submit_v1',
        'form_update_v1',
        'form_delete_v1',
        // Public frontend reads (page ACL handled per-page; lookups/languages
        // and CSS classes are public catalogue data).
        'frontend_css_classes_get_all_v1',
        'languages_get_all_v1',
        'system_lookups_v1',
        'pages_get_all_v1',
        'pages_get_one_v1',
        'pages_get_by_keyword_v1',
        'pages_get_all_with_language_v1',
        'plugins_manifest_v1',
        // Health probe + the public user-validation (set-password) flow.
        'health_v1',
        'user_validate_token_v1',
        'user_complete_validation_v1',
    ];

    public function testEveryDatabaseRouteResolvesToARealControllerMethod(): void
    {
        $router = $this->service(RouterInterface::class);
        $collection = $router->getRouteCollection();
        $rows = $this->service(ApiRouteRepository::class)->findAllRoutesWithPermissionsAsArray();

        self::assertGreaterThan(
            50,
            count($rows),
            'Expected the seeded api_routes table to contain the full core route set.'
        );

        $unregistered = [];
        $badController = [];
        $badMethods = [];

        foreach ($rows as $row) {
            $name = $this->routeName($row);
            $route = $collection->get($name);
            if ($route === null) {
                $unregistered[] = $name;
                continue;
            }

            $controller = $route->getDefault('_controller');
            if (!is_string($controller) || !$this->controllerResolves($controller)) {
                $badController[] = $name . ' => ' . var_export($controller, true);
            }

            $methods = $route->getMethods();
            if ($methods === [] || array_diff($methods, self::VALID_METHODS) !== []) {
                $badMethods[] = $name . ' => [' . implode(',', $methods) . ']';
            }
        }

        self::assertSame(
            [],
            $unregistered,
            "DB routes missing from the router collection:\n" . implode("\n", $unregistered)
        );
        self::assertSame(
            [],
            $badController,
            "Routes whose _controller does not resolve to a real Class::method:\n" . implode("\n", $badController)
        );
        self::assertSame(
            [],
            $badMethods,
            "Routes with missing/invalid HTTP methods:\n" . implode("\n", $badMethods)
        );
    }

    public function testEveryCoreRouteIsGuardedUnlessIntentionallyPublic(): void
    {
        $rows = $this->service(ApiRouteRepository::class)->findAllRoutesWithPermissionsAsArray();

        $unguarded = [];
        foreach ($rows as $row) {
            // Plugin-owned routes follow the plugin manifest convention, not
            // the core public allowlist.
            if (($row['id_plugins'] ?? null) !== null) {
                continue;
            }

            $permissions = is_array($row['permission_names'] ?? null) ? $row['permission_names'] : [];
            if ($permissions !== []) {
                continue;
            }

            $name = $this->routeName($row);
            if (!in_array($name, self::INTENTIONALLY_PUBLIC, true)) {
                $unguarded[] = $name;
            }
        }

        self::assertSame(
            [],
            $unguarded,
            "Permission-less core route(s) not on the reviewed public allowlist.\n"
            . "Add a route permission (rel_api_routes_permissions) or, after review, list them in "
            . "INTENTIONALLY_PUBLIC:\n" . implode("\n", $unguarded)
        );
    }

    /**
     * Every entry on the public allowlist must still correspond to a real,
     * currently-permission-less route — otherwise the allowlist rots and
     * silently hides a future guarded->public regression.
     */
    public function testPublicAllowlistHasNoStaleEntries(): void
    {
        $rows = $this->service(ApiRouteRepository::class)->findAllRoutesWithPermissionsAsArray();

        $publicNow = [];
        foreach ($rows as $row) {
            if (($row['id_plugins'] ?? null) !== null) {
                continue;
            }
            $permissions = is_array($row['permission_names'] ?? null) ? $row['permission_names'] : [];
            if ($permissions === []) {
                $publicNow[] = $this->routeName($row);
            }
        }

        $stale = array_values(array_diff(self::INTENTIONALLY_PUBLIC, $publicNow));
        self::assertSame(
            [],
            $stale,
            "Stale INTENTIONALLY_PUBLIC entries (route now guarded or removed): " . implode(', ', $stale)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function routeName(array $row): string
    {
        $routeName = is_scalar($row['route_name'] ?? null) ? (string) $row['route_name'] : '';
        $version = is_scalar($row['version'] ?? null) ? (string) $row['version'] : '';

        return $routeName . '_' . $version;
    }

    private function controllerResolves(string $controller): bool
    {
        if (!str_contains($controller, '::')) {
            // Invokable controller service / class.
            return class_exists($controller);
        }

        [$class, $method] = explode('::', $controller, 2);

        return class_exists($class) && method_exists($class, $method);
    }
}
