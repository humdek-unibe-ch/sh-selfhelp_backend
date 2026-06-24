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
 * restricted here to GET + an explicit allowlist of read-only render routes.
 * Anything else — every mutation, every admin route, every non-listed read —
 * is denied. This guarantees a leaked preview token cannot do more than re-read
 * the same public/admin-visible page data the iframe already shows.
 */
class MobilePreviewAccessGuard implements EventSubscriberInterface
{
    /**
     * The ONLY routes a `purpose: mobile_preview` token may call. All are
     * read-only GETs the preview renderer needs: resolve + render a page,
     * list languages, read the plugin manifest, and read the (admin) user
     * data the shared renderer expects.
     */
    private const ALLOWED_ROUTES = [
        'pages_get_by_keyword_v1',
        'pages_get_all_v1',
        'pages_get_all_with_language_v1',
        'languages_get_all_v1',
        'plugins_manifest_v1',
        'auth_user_data_get_v1',
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
