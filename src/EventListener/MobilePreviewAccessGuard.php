<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\EventListener;

use App\Service\Auth\JWTService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Hard scope-down for the CMS mobile preview's scoped JWT.
 *
 * Runs on `kernel.controller` at a HIGHER priority (20) than
 * {@see ApiSecurityListener} (10), so an out-of-scope preview token is rejected
 * before any permission logic or controller runs. It reads the decoded payload
 * that {@see \App\Security\JWTTokenAuthenticator} already stored on the request
 * (`_jwt_payload`) — no second JWT decode.
 *
 * A token minted by {@see JWTService::createMobilePreviewToken()} carries the
 * admin's identity (so pages render exactly as the admin sees them) but is
 * restricted here to a GET allowlist of read-only core render routes PLUS the
 * core frontend form routes (`/cms-api/v{n}/forms/*`) PLUS any plugin PUBLIC
 * runtime route (`/cms-api/v{n}/plugins/...`). The latter two let the previewed
 * page's forms be exercised end-to-end — a plugin style (e.g. the SurveyJS
 * runtime) loads, autosaves and submits, and a core form submits / updates /
 * deletes — exactly as on the live page, which is the whole point of a preview.
 * Everything else — every other core mutation, every admin route (core or
 * plugin), every non-listed core read — is denied. The form and plugin public
 * routes carry no route permission and enforce their own ACL / page-access /
 * per-response ownership checks, so this keeps a leaked preview token to the
 * same public/admin-visible surface the iframe already shows.
 */
class MobilePreviewAccessGuard implements EventSubscriberInterface
{
    /**
     * The core routes a `purpose: mobile_preview` token may call with GET. All
     * are read-only reads the preview renderer needs: resolve + render a page,
     * fetch the navigation payload (drawer/bottom-tab menus + startup pages —
     * without it the preview shell falls back to modal-only rendering), list
     * languages, read the plugin manifest, and read the (admin) user data the
     * shared renderer expects. Plugin PUBLIC runtime routes are allowed in
     * addition to this list — see {@see isPluginPublicRoute()}.
     */
    private const ALLOWED_ROUTES = [
        'pages_get_by_keyword_v1',
        'pages_get_all_v1',
        'pages_get_all_with_language_v1',
        'navigation_get_v1',
        'languages_get_all_v1',
        'plugins_manifest_v1',
        'auth_user_data_get_v1',
    ];

    /**
     * Core frontend form routes the previewed page's forms call to submit /
     * update / delete their data (registered by
     * {@see \DoctrineMigrations\Version20260602081706}). Like the plugin PUBLIC
     * routes, these carry no `rel_api_routes_permissions` entry and enforce
     * their own ACL / page-access checks inside {@see \App\Controller\Api\V1\Frontend\FormController},
     * so a previewed admin (or impersonated user) may exercise a page's forms
     * end-to-end exactly as on the live page. Allowed with their declared
     * methods (POST/PUT/DELETE), not GET.
     */
    private const ALLOWED_FORM_ROUTES = [
        'form_submit_v1',
        'form_update_v1',
        'form_delete_v1',
    ];

    public function __construct(
        private readonly JWTService $jwtService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 20 > ApiSecurityListener's 10: reject out-of-scope preview
        // tokens before permission checks or the controller execute.
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 20],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/cms-api/')) {
            return;
        }
        if ($request->getMethod() === 'OPTIONS') {
            return;
        }

        $payload = $this->jwtPayload($request);
        if ($payload === null || !$this->jwtService->isMobilePreviewPayload($payload)) {
            // Not a preview token — normal security flow applies.
            return;
        }

        $routeRaw = $request->attributes->get('_route');
        $routeName = is_string($routeRaw) ? $routeRaw : '';

        $allowed = $request->getMethod() === 'GET'
            && in_array($routeName, self::ALLOWED_ROUTES, true)
            && $this->scopeAllows($request, $routeName, $payload);

        // Core frontend form routes (submit/update/delete) let the previewed
        // page's forms be tested end-to-end. They mirror the plugin public
        // routes below: no route permission, own ACL/page-access enforcement in
        // FormController, and the preview runs as the admin's (or impersonated)
        // identity — so submitting a form in preview behaves like the live page.
        if (!$allowed && in_array($routeName, self::ALLOWED_FORM_ROUTES, true)) {
            $allowed = true;
        }

        // A previewed page may embed plugin styles (e.g. the SurveyJS runtime)
        // that load, autosave and submit through the plugin's PUBLIC api. Those
        // routes live under `/cms-api/v{n}/plugins/...` — never the
        // permission-gated `/cms-api/v{n}/admin/...` surface — carry no route
        // permission, and enforce their own per-response ownership checks, so
        // the previewed admin may use them exactly as on the live page.
        if (!$allowed && $this->isPluginPublicRoute($request)) {
            $allowed = true;
        }

        if (!$allowed) {
            $this->logger->warning('[MobilePreviewAccessGuard] Blocked out-of-scope preview-token request.', [
                'route'  => $routeName,
                'method' => $request->getMethod(),
                'path'   => $request->getPathInfo(),
            ]);

            throw new AccessDeniedException('This action is not permitted for a mobile preview session.');
        }
    }

    /**
     * A plugin's PUBLIC runtime route (`/cms-api/v{n}/plugins/...`) — the
     * frontend/mobile surface a rendered page's plugin styles call to load and
     * submit. The permission-gated admin surface
     * (`/cms-api/v{n}/admin/plugins/...`) is intentionally NOT matched here:
     * `admin/` follows the version segment, so it never satisfies this prefix.
     */
    private function isPluginPublicRoute(Request $request): bool
    {
        return preg_match('#^/cms-api/v\d+/plugins/#', $request->getPathInfo()) === 1;
    }

    /**
     * Read + normalise the decoded JWT payload the authenticator stashed on
     * the request. Returns null when there is no JWT payload.
     *
     * @return array<string, mixed>|null
     */
    private function jwtPayload(Request $request): ?array
    {
        $raw = $request->attributes->get('_jwt_payload');
        if (!is_array($raw)) {
            return null;
        }

        $payload = [];
        foreach ($raw as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }

    /**
     * Enforce the optional scope that was bound into the one-time preview code
     * and copied into the scoped JWT at exchange time.
     *
     * @param array<string, mixed> $payload
     */
    private function scopeAllows(Request $request, string $routeName, array $payload): bool
    {
        $scope = $this->scopePayload($payload);
        if ($scope === []) {
            return true;
        }

        if ($routeName === 'pages_get_by_keyword_v1') {
            $keyword = $this->requestString($request, 'keyword');
            if (($scope['page_id'] ?? null) !== null && ($scope['keyword'] ?? null) === null) {
                return false;
            }
            if (array_key_exists('keyword', $scope) && $scope['keyword'] !== null && $scope['keyword'] !== $keyword) {
                return false;
            }
        }

        if ($routeName === 'pages_get_all_with_language_v1' || $routeName === 'pages_get_by_keyword_v1') {
            $languageId = $this->requestInt($request, 'language_id');
            if (array_key_exists('language_id', $scope) && $scope['language_id'] !== null && $languageId !== null && $scope['language_id'] !== $languageId) {
                return false;
            }
        }

        if ($routeName === 'pages_get_by_keyword_v1') {
            $preview = $request->query->getBoolean('preview', false);
            if (($scope['draft'] ?? null) === false && $preview) {
                return false;
            }
            if (($scope['draft'] ?? null) === true && !$preview) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{keyword?: string|null, page_id?: int|null, language_id?: int|null, draft?: bool|null}
     */
    private function scopePayload(array $payload): array
    {
        $raw = $payload['mobile_preview_scope'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $scope = [];
        if (array_key_exists('keyword', $raw)) {
            $scope['keyword'] = is_string($raw['keyword']) && $raw['keyword'] !== '' ? $raw['keyword'] : null;
        }
        if (array_key_exists('page_id', $raw)) {
            $scope['page_id'] = is_numeric($raw['page_id']) ? (int) $raw['page_id'] : null;
        }
        if (array_key_exists('language_id', $raw)) {
            $scope['language_id'] = is_numeric($raw['language_id']) ? (int) $raw['language_id'] : null;
        }
        if (array_key_exists('draft', $raw)) {
            $scope['draft'] = is_bool($raw['draft']) ? $raw['draft'] : null;
        }

        return $scope;
    }

    private function requestString(Request $request, string $name): ?string
    {
        $value = $request->attributes->get($name);
        if (!is_string($value)) {
            $routeParams = $request->attributes->get('_route_params');
            $value = is_array($routeParams) && is_string($routeParams[$name] ?? null) ? $routeParams[$name] : null;
        }

        return $value;
    }

    private function requestInt(Request $request, string $name): ?int
    {
        $value = $request->attributes->get($name);
        if (!is_numeric($value)) {
            $routeParams = $request->attributes->get('_route_params');
            $value = is_array($routeParams) && is_numeric($routeParams[$name] ?? null) ? $routeParams[$name] : null;
        }
        if (!is_numeric($value)) {
            $value = $request->query->get($name);
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
