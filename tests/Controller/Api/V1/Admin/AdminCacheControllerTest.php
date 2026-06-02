<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\Cache\Core\CacheService;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Coverage for the admin cache-management API (plan Phase 6 — cache).
 *
 * Read endpoints require `admin.cache.read`, clear endpoints `admin.cache.clear`
 * — both admin-only. These tests assert the stats/health read contract, the
 * clear-all and clear-category side effects, invalid-category rejection, and the
 * negative-permission matrix. Cache in the test env is a per-process adapter, so
 * clearing it has no cross-test impact.
 */
final class AdminCacheControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    public function testGetCacheStatsReturnsEnvelope(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/cache/stats', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        foreach (['cache_stats', 'cache_categories', 'top_performing_categories', 'timestamp'] as $key) {
            self::assertArrayHasKey($key, $data, "Cache stats must expose '{$key}'");
        }
    }

    public function testGetCacheHealthReturnsEnvelope(): void
    {
        $envelope = $this->jsonRequest('GET', '/cms-api/v1/admin/cache/health', null, $this->loginAsQaAdmin());

        $this->assertEnvelopeSuccess($envelope);
    }

    public function testClearAllCachesSucceeds(): void
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/cache/clear/all', [], $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertTrue($data['cleared'] ?? null, 'clear/all must report cleared=true');
    }

    public function testClearCacheCategorySucceeds(): void
    {
        $envelope = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/cache/clear/category',
            ['category' => CacheService::CATEGORY_PAGES],
            $this->loginAsQaAdmin()
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertSame(CacheService::CATEGORY_PAGES, $data['category'] ?? null);
        self::assertTrue($data['cleared'] ?? null, 'clear/category must report cleared=true');
    }

    public function testClearCacheCategoryRejectsInvalidCategory(): void
    {
        $envelope = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/cache/clear/category',
            ['category' => 'qa_not_a_real_category'],
            $this->loginAsQaAdmin()
        );

        $this->assertEnvelope400($envelope);
    }

    public function testResetCacheStatsSucceeds(): void
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/cache/stats/reset', [], $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertTrue($data['reset'] ?? null, 'stats/reset must report reset=true');
    }

    public function testClearApiRoutesCacheSucceeds(): void
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/cache/api-routes/clear', [], $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertTrue($data['cleared'] ?? null, 'api-routes/clear must report cleared=true');
        self::assertSame('api_routes', $data['cache_type'] ?? null);
    }

    public function testClearUserCacheSucceedsForAPositiveUserId(): void
    {
        $userId = $this->qaUserId();

        $envelope = $this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/cache/clear/user',
            ['user_id' => $userId],
            $this->loginAsQaAdmin()
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertSame($userId, $data['user_id'] ?? null);
        self::assertTrue($data['cleared'] ?? null, 'clear/user must report cleared=true');
    }

    public function testClearUserCacheRejectsMissingUserId(): void
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/cache/clear/user', [], $this->loginAsQaAdmin());

        $this->assertEnvelope400($envelope);
    }

    #[Group('security')]
    public function testClearApiRoutesCacheForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/cache/api-routes/clear', []);
    }

    private function qaUserId(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = $em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return (int) $user->getId();
    }

    #[Group('security')]
    public function testCacheStatsEnforceAdminOnlyMatrix(): void
    {
        $this->assertAdminOnlyMatrix('GET', '/cms-api/v1/admin/cache/stats');
    }

    #[Group('security')]
    public function testClearAllCacheForbiddenForNonAdmins(): void
    {
        $this->assertForbiddenForNonAdmins('POST', '/cms-api/v1/admin/cache/clear/all', []);
    }
}
