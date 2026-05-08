<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1\Auth;

use App\Service\Auth\UserContextService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Mercure\MercureTopicResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;

/**
 * API V1 Auth Events Controller — Mercure subscriber bootstrap.
 *
 * Returns the discovery payload the frontend BFF needs to subscribe to the
 * authenticated user's private ACL change topic on the Mercure hub:
 *
 * ```
 * GET /cms-api/v1/auth/events
 *
 * {
 *   "status": 200, "message": "OK", "error": null, "logged_in": true, "meta": {...},
 *   "data": {
 *     "hubUrl": "...",
 *     "topic": "...",                 // ACL topic (kept for compatibility)
 *     "impersonationTopic": "...",    // impersonation-status topic
 *     "token": "...",                 // single JWT scoped to BOTH topics
 *     "expiresIn": 3600
 *   }
 * }
 * ```
 *
 * The success payload travels through the standard `ApiResponseFormatter`
 * envelope so this endpoint matches every other auth endpoint
 * (`/auth/login`, `/auth/refresh-token`, `/auth/user-data`, …) and is
 * validated against `config/schemas/api/v1/responses/auth/events.json`.
 *
 * ## Architecture
 *
 * Real-time ACL push is implemented end-to-end with Mercure
 * (`symfony/mercure-bundle` + a dockerised Caddy/Mercure hub):
 *
 *   - **Publisher.** {@see \App\EventListener\AclVersionMercurePublisher}
 *     listens on Doctrine `postFlush` and publishes `acl-changed` to
 *     `https://selfhelp.app/users/{id}/acl` whenever a User row's
 *     `acl_version` column changes.
 *   - **Hub.** Holds the long-lived SSE connection on behalf of every
 *     subscriber. PHP-FPM is *never* used for streaming — Symfony only ever
 *     does cheap fire-and-forget POSTs to the hub.
 *   - **Subscriber bootstrap.** This endpoint. Mints a short-lived JWT
 *     scoped to the caller's per-user topic and returns the public hub URL
 *     so the BFF can open an upstream `Authorization: Bearer …` request to
 *     the hub.
 *   - **BFF proxy.** `frontend/src/app/api/auth/events/route.ts` calls this
 *     endpoint, opens the Mercure subscription, and pipes the byte stream
 *     back to the browser as a same-origin SSE (avoids cross-origin cookie
 *     gymnastics in dev).
 *
 * The subscriber JWT lifetime is intentionally short so a leaked token
 * cannot be replayed for long. The browser's `EventSource` reconnects
 * automatically on idle/timeout and the BFF re-mints a fresh token on each
 * upstream connect.
 *
 * ## Auth
 *
 * Falls under `^/cms-api/v1/auth: PUBLIC_ACCESS` in `security.yaml` so the
 * Lexik JWT firewall runs in lazy mode. The controller manually checks
 * {@see UserContextService::getCurrentUser()} and 401s anonymous callers,
 * matching the convention used by `/auth/user-data`.
 */
class AuthEventsController extends AbstractController
{
    private const TRANSPORT_COOKIE = 'cookie';
    private const MERCURE_AUTH_COOKIE = 'mercureAuthorization';

    /**
     * @param int $mercureSubscriberTtl Lifetime in seconds for the subscriber JWT
     *                                  returned by this endpoint. Short on purpose
     *                                  so leaked tokens age out quickly; the BFF
     *                                  re-mints on every reconnect anyway.
     */
    public function __construct(
        private readonly UserContextService $userContextService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly MercureTopicResolver $topics,
        private readonly string $mercurePublicUrl,
        private readonly string $mercureJwtSecret,
        private readonly int $mercureSubscriberTtl
    ) {
    }

    /**
     * Issue a Mercure subscriber JWT scoped to the current user's ACL topic.
     *
     * Wired to `GET /auth/events` via the `auth_events_stream_v1` row in
     * the `api_routes` table (see `migrations/Version20260425000000.php`).
     *
     * Method name is intentionally `events()` and not `stream()` because
     * `AbstractController` already declares a `stream(string $view, ...)`
     * helper for rendering Twig templates as a `StreamedResponse`, and PHP
     * would (rightly) refuse to override it with an incompatible signature.
     */
    public function events(Request $request): Response
    {
        $user = $this->userContextService->getCurrentUser();
        if (!$user) {
            return $this->responseFormatter->formatError(
                'User not authenticated',
                Response::HTTP_UNAUTHORIZED
            );
        }

        $userId = (int) $user->getId();
        $aclTopic = $this->topics->userAclTopic($userId);
        $impersonationTopic = $this->topics->userImpersonationTopic($userId);
        $useCookieTransport = $this->shouldUseCookieTransport($request);
        $hubUrl = $this->resolvePublicHubUrl($request);

        if ($this->mercureJwtSecret === '') {
            // Misconfiguration. Fail loud so the dev notices instead of
            // silently returning broken tokens.
            return $this->responseFormatter->formatError(
                'Mercure JWT secret is not configured',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $factory = new LcobucciFactory(
            $this->mercureJwtSecret,
            'hmac.sha256',
            $this->mercureSubscriberTtl
        );

        // One subscriber JWT, two topics. Mercure supports multiple
        // `topic=` query params on a single SSE connection, so the BFF
        // opens ONE upstream socket instead of two — half the RAM in the
        // hub, half the file descriptors per logged-in user.
        $token = $factory->create([$aclTopic, $impersonationTopic], null);

        if ($useCookieTransport && !$this->canIssueMercureCookie($hubUrl, $request)) {
            return $this->responseFormatter->formatError(
                'Browser Mercure cookie mode requires MERCURE_PUBLIC_URL to use the same host as the API request host. Put the hub behind the same public host as the app/API.',
                Response::HTTP_CONFLICT
            );
        }

        $response = $this->responseFormatter->formatSuccess(
            [
                'hubUrl' => $hubUrl,
                'topic' => $aclTopic,
                'impersonationTopic' => $impersonationTopic,
                'token' => $useCookieTransport ? null : $token,
                'expiresIn' => $this->mercureSubscriberTtl,
            ],
            'responses/auth/events',
            Response::HTTP_OK
        );

        if ($useCookieTransport) {
            $response->headers->setCookie($this->buildMercureCookie($token, $hubUrl));
        }

        return $response;
    }

    private function shouldUseCookieTransport(Request $request): bool
    {
        return $request->query->getString('transport') === self::TRANSPORT_COOKIE;
    }

    private function resolvePublicHubUrl(Request $request): string
    {
        $parts = parse_url($this->mercurePublicUrl);
        if ($parts === false || !isset($parts['host'])) {
            return $this->mercurePublicUrl;
        }

        $requestHost = $request->getHost();
        if (!$this->isLocalDevHost($parts['host']) || !$this->isLocalDevHost($requestHost)) {
            return $this->mercurePublicUrl;
        }

        $parts['host'] = $requestHost;

        return $this->unparseUrl($parts);
    }

    private function canIssueMercureCookie(string $hubUrl, Request $request): bool
    {
        $hubHost = parse_url($hubUrl, PHP_URL_HOST);

        return is_string($hubHost) && strcasecmp($hubHost, $request->getHost()) === 0;
    }

    private function buildMercureCookie(string $token, string $hubUrl): Cookie
    {
        $scheme = (string) parse_url($hubUrl, PHP_URL_SCHEME);
        $path = (string) (parse_url($hubUrl, PHP_URL_PATH) ?: '/.well-known/mercure');
        $isSecure = strcasecmp($scheme, 'https') === 0;

        return Cookie::create(self::MERCURE_AUTH_COOKIE)
            ->withValue($token)
            ->withPath($path)
            ->withExpires(new \DateTimeImmutable(sprintf('+%d seconds', $this->mercureSubscriberTtl)))
            ->withHttpOnly(true)
            ->withSecure($isSecure)
            ->withSameSite($isSecure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX);
    }

    private function isLocalDevHost(string $host): bool
    {
        $normalized = trim(strtolower($host), '[]');

        return in_array($normalized, ['localhost', '127.0.0.1', '::1', '10.0.2.2'], true);
    }

    /**
     * @param array<string, int|string> $parts
     */
    private function unparseUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? sprintf('%s://', $parts['scheme']) : '';
        $user = (string) ($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? sprintf(':%s', $parts['pass']) : '';
        $auth = $user !== '' ? sprintf('%s%s@', $user, $pass) : '';
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? sprintf(':%s', $parts['port']) : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? sprintf('?%s', $parts['query']) : '';
        $fragment = isset($parts['fragment']) ? sprintf('#%s', $parts['fragment']) : '';

        return sprintf('%s%s%s%s%s%s%s', $scheme, $auth, $host, $port, $path, $query, $fragment);
    }
}
