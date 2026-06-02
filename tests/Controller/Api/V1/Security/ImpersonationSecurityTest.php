<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Security;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Transaction;
use App\Entity\User;
use App\Service\Auth\JWTService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration coverage for {@see \App\EventListener\ApiSecurityListener}'s
 * impersonation guards (plan Phase 7: impersonated mutation audit + high-risk
 * route block).
 *
 * A real impersonation JWT (qa.admin acting as qa.user) is minted via
 * {@see JWTService::createImpersonationToken()} and driven through the live
 * firewall:
 *   - high-risk routes (delete user, restart impersonation) are hard-blocked;
 *   - any other mutation is audited against the *original admin* even when the
 *     impersonated user ultimately lacks permission for it.
 */
#[Group('security')]
final class ImpersonationSecurityTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private string $impersonationToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->service(EntityManagerInterface::class);
        $jwt = $this->service(JWTService::class);

        $admin = $this->user(QaBaselineFixture::QA_ADMIN_EMAIL);
        $target = $this->user(QaBaselineFixture::QA_USER_EMAIL);

        $minted = $jwt->createImpersonationToken($target, (int) $admin->getId());
        $this->impersonationToken = $this->asString($minted['access_token']);
    }

    public function testImpersonationTokenCannotDeleteUsers(): void
    {
        $envelope = $this->jsonRequest('DELETE', '/cms-api/v1/admin/users/2147483600', null, $this->impersonationToken);

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $envelope['status'] ?? null,
            'Deleting users must be blocked under an impersonation token.',
        );
    }

    public function testImpersonationTokenCannotRestartImpersonation(): void
    {
        $envelope = $this->jsonRequest('POST', '/cms-api/v1/admin/users/2147483600/impersonate', null, $this->impersonationToken);

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $envelope['status'] ?? null,
            'Restarting impersonation must be blocked under an impersonation token.',
        );
    }

    public function testImpersonatedMutationIsAuditedAgainstTheAdmin(): void
    {
        $before = $this->impersonationAuditCount();

        // A non-forbidden unsafe route. qa.user lacks admin.cache.clear so the
        // request is ultimately denied (403), but the impersonation audit runs
        // BEFORE the permission check and must record the attempt.
        $this->jsonRequest('POST', '/cms-api/v1/admin/cache/clear/all', [], $this->impersonationToken);

        self::assertGreaterThan(
            $before,
            $this->impersonationAuditCount(),
            'Every mutation under an impersonation token must be audited against the original admin.',
        );
    }

    private function impersonationAuditCount(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Transaction::class, 't')
            ->where('t.transactionLog LIKE :needle')
            ->setParameter('needle', '%Impersonated mutation%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function user(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, "{$email} must be seeded. Run: composer test:reset-db");

        return $user;
    }
}
