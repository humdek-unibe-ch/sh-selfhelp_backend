<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataAccessAudit;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioural coverage for {@see DataAccessSecurityService} — the deny-by-default
 * CRUD bit-flag gate behind admin data access (plan Phase 7: bit-flag checks,
 * deny-by-default, admin-role override, audit metadata).
 *
 * A non-admin qa user is given a `qa_` role with a single-table grant via
 * {@see RoleDataAccessFactory}; the seeded admin persona exercises the override.
 */
#[Group('security')]
final class DataAccessSecurityServiceTest extends QaKernelTestCase
{
    private DataAccessSecurityService $service;
    private RoleDataAccessFactory $grants;
    private int $tableId;
    private int $qaUserId;
    private int $adminUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->service(DataAccessSecurityService::class);
        $this->grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service,
        );

        $this->tableId = (int) (new ActionFactory($this->em))
            ->createDataTable('qa_data_access_table')
            ->getId();

        $this->qaUserId = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $this->adminUserId = $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL);

        // qa.user starts with no role; give it a pure data-access role granting
        // READ (only) on the qa table.
        $qaUser = $this->em->getRepository(User::class)->find($this->qaUserId);
        self::assertInstanceOf(User::class, $qaUser);
        $role = $this->grants->createRole('qa_data_access_role');
        $this->grants->assignRoleToUser($qaUser, $role);
        $this->grants->grantDataTableAccess($role, $this->tableId, DataAccessSecurityService::PERMISSION_READ);
    }

    public function testGrantedBitIsAllowed(): void
    {
        self::assertTrue(
            $this->service->hasStoredPermission(
                $this->qaUserId,
                LookupService::RESOURCE_TYPES_DATA_TABLE,
                $this->tableId,
                DataAccessSecurityService::PERMISSION_READ,
            ),
            'READ was granted on the table and must be allowed.',
        );
    }

    public function testNonGrantedBitIsDenied(): void
    {
        self::assertFalse(
            $this->service->hasStoredPermission(
                $this->qaUserId,
                LookupService::RESOURCE_TYPES_DATA_TABLE,
                $this->tableId,
                DataAccessSecurityService::PERMISSION_DELETE,
            ),
            'Only READ was granted; DELETE must be denied (bit-flag check).',
        );
    }

    public function testUngrantedResourceIsDeniedByDefault(): void
    {
        self::assertFalse(
            $this->service->hasStoredPermission(
                $this->qaUserId,
                LookupService::RESOURCE_TYPES_DATA_TABLE,
                $this->tableId + 987654,
                DataAccessSecurityService::PERMISSION_READ,
            ),
            'A resource with no grant must be denied by default.',
        );
    }

    public function testInvalidResourceTypeIsDenied(): void
    {
        self::assertFalse(
            $this->service->hasStoredPermission(
                $this->qaUserId,
                'qa_not_a_real_resource_type',
                $this->tableId,
                DataAccessSecurityService::PERMISSION_READ,
            ),
            'An unknown resource type must be denied.',
        );
    }

    public function testAdminRoleOverridesStoredPermissions(): void
    {
        // qa.admin holds the admin role and has NO grant on this table, yet
        // hasPermission() must allow every CRUD op via the admin override.
        self::assertTrue(
            $this->service->hasPermission(
                $this->adminUserId,
                LookupService::RESOURCE_TYPES_DATA_TABLE,
                $this->tableId,
                DataAccessSecurityService::PERMISSION_DELETE,
            ),
            'Admin role must override stored data-access permissions.',
        );
    }

    public function testPermissionCheckWritesAnAuditRow(): void
    {
        $before = $this->auditCountForUser($this->qaUserId);

        $this->service->hasStoredPermission(
            $this->qaUserId,
            LookupService::RESOURCE_TYPES_DATA_TABLE,
            $this->tableId,
            DataAccessSecurityService::PERMISSION_READ,
        );

        self::assertGreaterThan(
            $before,
            $this->auditCountForUser($this->qaUserId),
            'Every permission check must leave an audit trail row (plan Phase 7: audit metadata).',
        );
    }

    private function auditCountForUser(int $userId): int
    {
        $user = $this->em->getReference(User::class, $userId);

        return (int) $this->em->getRepository(DataAccessAudit::class)->count(['user' => $user]);
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, "{$email} must be seeded. Run: composer test:reset-db");

        return (int) $user->getId();
    }
}
