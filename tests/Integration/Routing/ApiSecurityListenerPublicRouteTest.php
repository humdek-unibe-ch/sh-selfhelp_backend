<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Routing;

use App\Service\Cache\Core\CacheService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * F09: a non-public route with no permissions answers 401 (guest) / 403 (signed in).
 */
#[Group('security')]
final class ApiSecurityListenerPublicRouteTest extends QaWebTestCase
{
    public function testUnguardedNonPublicRouteIsRefused(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);

        $row = $conn->fetchAssociative(
            "SELECT id, path FROM api_routes WHERE route_name = 'health' AND version = 'v1' LIMIT 1"
        );
        self::assertIsArray($row);
        $routeId = self::coerceInt($row['id'] ?? null);
        $path = self::coerceString($row['path'] ?? null);

        $conn->executeStatement(
            'UPDATE api_routes SET is_public = 0 WHERE id = :id',
            ['id' => $routeId]
        );
        $this->invalidateRouteCaches();
        // Rebuild the router collection from DB (ApiRouteLoader loads once per kernel).
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        try {
            $this->client->request('GET', '/cms-api/v1' . $path);
            self::assertSame(
                Response::HTTP_UNAUTHORIZED,
                $this->client->getResponse()->getStatusCode(),
                'Guest must get 401 for a non-public unguarded route'
            );

            $token = $this->loginAsQaEditor();
            $this->client->request(
                'GET',
                '/cms-api/v1' . $path,
                [],
                [],
                ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            self::assertSame(
                Response::HTTP_FORBIDDEN,
                $this->client->getResponse()->getStatusCode(),
                'Signed-in non-admin must get 403 for a non-public unguarded route'
            );
        } finally {
            /** @var Connection $conn */
            $conn = static::getContainer()->get(Connection::class);
            $conn->executeStatement(
                'UPDATE api_routes SET is_public = 1 WHERE id = :id',
                ['id' => $routeId]
            );
            $this->invalidateRouteCaches();
            static::ensureKernelShutdown();
            $this->client = static::createClient();
        }
    }

    public function testPublicRouteAnswersGuestNormally(): void
    {
        $this->client->request('GET', '/cms-api/v1/health');
        $status = $this->client->getResponse()->getStatusCode();
        self::assertTrue(
            $status === Response::HTTP_OK || $status === Response::HTTP_SERVICE_UNAVAILABLE,
            'Public health route must answer a guest (got ' . $status . ')'
        );
    }

    private function invalidateRouteCaches(): void
    {
        /** @var CacheService $cache */
        $cache = static::getContainer()->get(CacheService::class);
        $cache->withCategory(CacheService::CATEGORY_API_ROUTES)->invalidateCategory();
        $cache->withCategory(CacheService::CATEGORY_PERMISSIONS)->invalidateCategory();
    }
}
