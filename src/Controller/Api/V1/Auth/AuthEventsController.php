<?php

declare(strict_types=1);

namespace App\Controller\Api\V1\Auth;

use App\Service\Auth\UserContextService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Mercure\MercureTopicResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 *   "data": { "hubUrl": "...", "topic": "...", "token": "...", "expiresIn": 3600 }
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
    public function events(): Response
    {
        $user = $this->userContextService->getCurrentUser();
        if (!$user) {
            return $this->responseFormatter->formatError(
                'User not authenticated',
                Response::HTTP_UNAUTHORIZED
            );
        }

        $userId = (int) $user->getId();
        $topic = $this->topics->userAclTopic($userId);

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
        $token = $factory->create([$topic], null);

        return $this->responseFormatter->formatSuccess(
            [
                'hubUrl' => $this->mercurePublicUrl,
                'topic' => $topic,
                'token' => $token,
                'expiresIn' => $this->mercureSubscriberTtl,
            ],
            'responses/auth/events',
            Response::HTTP_OK
        );
    }
}
