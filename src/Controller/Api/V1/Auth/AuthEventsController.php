<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1\Auth;

use App\Plugin\Event\PluginRealtimePermissionEvent;
use App\Service\Auth\UserContextService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Mercure\MercureTopicResolver;
use App\Service\Sync\AclStampService;
use Psr\EventDispatcher\EventDispatcherInterface;
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
 *     "systemUpdateTopic": "...",     // system-update progress topic (null for guests)
 *     "syncTopics": ["..."],          // live-sync topics: user, each group, each role, public
 *     "token": "...",                 // single JWT scoped to ALL topics
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
 * Lexik JWT firewall runs in lazy mode. Guests are served as well (never 401):
 * they get the public sync topic only; the user, impersonation, system-update and
 * plugin topics are for signed-in callers.
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
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AclStampService $aclStamp,
        private readonly string $mercurePublicUrl,
        private readonly string $mercureJwtSecret,
        private readonly int $mercureSubscriberTtl
    ) {
    }

    /**
     * Issue a Mercure subscriber JWT scoped to the current user's ACL topic.
     *
     * Wired to `GET /auth/events` via the `auth_events_stream_v1` row in
     * the `api_routes` table (see `migrations/Version20260601000300.php`,
     * `seedMissingRoutes()`).
     *
     * Method name is intentionally `events()` and not `stream()` because
     * `AbstractController` already declares a `stream(string $view, ...)`
     * helper for rendering Twig templates as a `StreamedResponse`, and PHP
     * would (rightly) refuse to override it with an incompatible signature.
     */
    public function events(Request $request): Response
    {
        // Guests are served too (live-sync contract C4): the public sync topic carries navigation and
        // global-value notices that every visitor needs. They get no user, impersonation or
        // system-update topic and no plugin topics.
        $user = $this->userContextService->getCurrentUser();

        if ($user) {
            $userId = (int) $user->getId();
            $aclTopic = $this->topics->userAclTopic($userId);
            $impersonationTopic = $this->topics->userImpersonationTopic($userId);
            $systemUpdateTopic = $this->topics->userSystemUpdateTopic($userId);

            // The sync topics the caller may read, and nothing else: their own, one per group, one per
            // role, and the public one. The token below lists exactly these (plus the private topics).
            $memberships = $this->aclStamp->memberships($userId);
            $syncTopics = [$this->topics->syncUserTopic($userId)];
            foreach (array_keys($memberships['groups']) as $groupId) {
                $syncTopics[] = $this->topics->syncGroupTopic($groupId);
            }
            foreach (array_keys($memberships['roles']) as $roleId) {
                $syncTopics[] = $this->topics->syncRoleTopic($roleId);
            }
            $syncTopics[] = $this->topics->syncPublicTopic();
        } else {
            $aclTopic = null;
            $impersonationTopic = null;
            $systemUpdateTopic = null;
            $syncTopics = [$this->topics->syncPublicTopic()];
        }
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

        // Ask plugin listeners for additional topic IRIs this user is
        // allowed to subscribe to. Deny-by-default: no grant ⇒ no
        // subscription. Plugin listeners use `PluginRealtimePublisher`
        // to publish messages; the topic IRIs they grant here must match
        // the IRIs declared in their `plugin.json#realtimeTopics`.
        // The host grants the one-user topics itself (`PluginUserTopicGrant`, audience `user`): the
        // topic with the caller's own id and no other.
        $pluginTopics = [];
        if ($user) {
            $pluginEvent = new PluginRealtimePermissionEvent($user);
            $this->eventDispatcher->dispatch($pluginEvent);
            $pluginTopics = $pluginEvent->getAllowedTopicIris();
        }

        // One subscriber JWT, multiple topics. Mercure supports multiple
        // `topic=` query params on a single SSE connection, so the BFF
        // opens ONE upstream socket instead of N — half the RAM in the
        // hub, half the file descriptors per logged-in user.
        $token = $factory->create(
            array_values(array_unique(array_merge(
                array_values(array_filter([$aclTopic, $impersonationTopic, $systemUpdateTopic], static fn (?string $topic): bool => $topic !== null)),
                $syncTopics,
                $pluginTopics,
            ))),
            null,
        );

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
                'systemUpdateTopic' => $systemUpdateTopic,
                'syncTopics' => $syncTopics,
                'pluginTopics' => $pluginTopics,
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
