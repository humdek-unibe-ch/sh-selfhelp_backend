<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Security;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration coverage for {@see \App\Security\JWTTokenAuthenticator} via the real
 * firewall (plan Phase 7: 401 behaviour + public-route bypass).
 *
 * A malformed bearer token makes the authenticator fail; its
 * onAuthenticationFailure returns null so the request continues as anonymous.
 * The access_control rules then decide the outcome: 401 on a protected admin
 * route, 200 on a PUBLIC_ACCESS route. This proves the "fail = anonymous, not
 * hard 500" contract that keeps public endpoints reachable with a stale token.
 */
#[Group('security')]
final class JWTTokenAuthenticatorTest extends QaWebTestCase
{
    private const PUBLIC_ROUTE = '/cms-api/v1/lookups';
    private const ADMIN_ROUTE = '/cms-api/v1/admin/cache/stats';

    public function testMalformedBearerOnProtectedRouteReturns401(): void
    {
        $this->requestWithRawBearer('GET', self::ADMIN_ROUTE, 'this.is.not-a-jwt');

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->client->getResponse()->getStatusCode(),
            'A malformed token on a protected route must fall back to anonymous and be rejected with 401.',
        );
    }

    public function testMalformedBearerOnPublicRouteFallsBackToAnonymous(): void
    {
        $this->requestWithRawBearer('GET', self::PUBLIC_ROUTE, 'this.is.not-a-jwt');

        self::assertSame(
            Response::HTTP_OK,
            $this->client->getResponse()->getStatusCode(),
            'A malformed token must not break a public route — it falls back to anonymous access.',
        );
    }

    public function testNoTokenOnProtectedRouteReturns401(): void
    {
        $this->client->request('GET', self::ADMIN_ROUTE, [], [], ['CONTENT_TYPE' => 'application/json']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testValidAdminTokenIsAccepted(): void
    {
        $this->requestWithRawBearer('GET', self::ADMIN_ROUTE, $this->loginAsQaAdmin());

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    private function requestWithRawBearer(string $method, string $uri, string $token): void
    {
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );
    }
}
