<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Plugin\RateLimit\PluginRateLimiterInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * C15: what a plugin route does that spends a rate limit. The QA users plugin
 * ({@see QaUsersPluginFixture}) limits `qa_burst` to 2 per minute per key; this
 * route consumes one token for the `key` query parameter and answers `ok`. The
 * third call with the same key throws {@see \App\Plugin\RateLimit\PluginRateLimitedException},
 * which the API exception listener answers with 429 `rate_limited`.
 *
 * It is reached through the test-only route in `config/routes/test/plugin_users_routes.yaml`.
 */
#[AsController]
final class QaRateLimitedController
{
    public function __construct(private readonly PluginRateLimiterInterface $limiter)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->limiter->consume(QaUsersPluginFixture::PLUGIN_ID, QaUsersPluginFixture::LIMIT_NAME, $request->query->getString('key'));

        return new JsonResponse(['ok' => true]);
    }
}
