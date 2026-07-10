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
use App\Repository\RoleDataAccessRepository;
use App\Repository\UserRepository;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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

    /**
     * The shared "may this user delete this form/entry-table record?" rule.
     * It is the single source of truth used by both the delete endpoint
     * ({@see \App\Controller\Api\V1\Frontend\FormController::deleteForm})
     * and the entry-table hydration's per-row `_can_delete` flag
     * ({@see \App\Service\CMS\Common\SectionUtilityService}), so the two can
     * never drift: own_entries_only sections are always deletable; otherwise a
     * user may delete their own record, or any record with table DELETE rights.
     */
    #[DataProvider('deletableRecordCases')]
    public function testCanDeleteOwnedRecordRule(
        bool $ownEntriesOnly,
        bool $isOwnRecord,
        bool $hasDeletePermission,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->service->canDeleteOwnedRecord($ownEntriesOnly, $isOwnRecord, $hasDeletePermission),
        );
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool}>
     */
    public static function deletableRecordCases(): iterable
    {
        // own_entries_only: every visible row is the user's own -> always deletable.
        yield 'own_entries_only always deletable' => [true, false, false, true];
        yield 'own_entries_only ignores missing perm' => [true, false, false, true];
        // showing everyone's records: own row is always deletable.
        yield 'own record deletable when showing all' => [false, true, false, true];
        // someone else's row: needs the data-table DELETE grant.
        yield 'other record deletable with table delete perm' => [false, false, true, true];
        yield 'other record blocked without perm' => [false, false, false, false];
    }

    /**
     * The mirror rule for record edit mode (issue #30): "may this user update
     * this form record?" — the single source of truth shared by the update
     * endpoint ({@see \App\Controller\Api\V1\Frontend\FormController::updateForm}),
     * the form-record prefill loader and the entry-table `_can_edit` flag.
     * The matrix covers all three ownership modes: own-entries diary
     * (own_entries_only), shared editing (table UPDATE grant), admin edit-any.
     */
    #[DataProvider('updatableRecordCases')]
    public function testCanUpdateOwnedRecordRule(
        bool $ownEntriesOnly,
        bool $isOwnRecord,
        bool $hasUpdatePermission,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->service->canUpdateOwnedRecord($ownEntriesOnly, $isOwnRecord, $hasUpdatePermission),
        );
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool}>
     */
    public static function updatableRecordCases(): iterable
    {
        // Ownership mode 1 — diary: the form only loads the user's own records.
        yield 'own_entries_only always editable' => [true, false, false, true];
        // Ownership mode 2 — shared table: own row always editable...
        yield 'own record editable when showing all' => [false, true, false, true];
        // ...someone else's row needs the data-table UPDATE grant (admins get
        // it via the role override in hasPermission()).
        yield 'other record editable with table update perm' => [false, false, true, true];
        yield 'other record blocked without update perm' => [false, false, false, false];
    }

    public function testPermissionCheckWritesAnAuditRowWithNonNullLookups(): void
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

        // The persisted row must carry valid, non-null resource-type / action /
        // result FKs. Regression for the swallowed "Column 'id_resource_types'
        // cannot be null" integrity error: a granted READ on a real data table
        // must produce a complete audit row.
        $audit = $this->latestAuditForUser($this->qaUserId);
        self::assertInstanceOf(DataAccessAudit::class, $audit, 'A data-access audit row must be persisted.');
        self::assertSame(
            LookupService::RESOURCE_TYPES_DATA_TABLE,
            $audit->getResourceType()->getLookupCode(),
            'The audit row must reference the data_table resource-type lookup (non-null FK).',
        );
        self::assertSame($this->tableId, $audit->getResourceId());
        self::assertSame(LookupService::AUDIT_ACTIONS_READ, $audit->getAction()->getLookupCode());
        self::assertSame(LookupService::PERMISSION_RESULTS_GRANTED, $audit->getPermissionResult()->getLookupCode());
    }

    public function testInvalidResourceTypeCheckDoesNotSwallowAnAuditError(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $service = $this->buildServiceWithLogger($logger);

        $allowed = $service->hasStoredPermission(
            $this->qaUserId,
            'qa_not_a_real_resource_type',
            $this->tableId,
            DataAccessSecurityService::PERMISSION_READ,
        );

        self::assertFalse($allowed, 'An unknown resource type must be denied.');

        // Before the fix this path persisted an audit row with a NULL
        // id_resource_types, raising an integrity error that was caught and
        // logged as "Failed to log data access audit" — an invisible swallow.
        $swallowed = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => str_contains($r['message'], 'Failed to log data access audit'),
        ));
        self::assertSame([], $swallowed, 'A denial on an unknown resource type must not raise a swallowed NULL-FK audit error.');

        // The skipped audit is now surfaced as a visible warning instead.
        $skips = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => str_contains($r['message'], 'Skipping data access audit'),
        ));
        self::assertNotSame([], $skips, 'A skipped audit must be logged as a visible warning, not silently dropped.');
    }

    private function buildServiceWithLogger(LoggerInterface $logger): DataAccessSecurityService
    {
        return new DataAccessSecurityService(
            $this->service(RoleDataAccessRepository::class),
            $this->service(UserRepository::class),
            $this->service(LookupService::class),
            $this->em,
            $this->service(CacheService::class),
            $this->service(RequestStack::class),
            $logger,
        );
    }

    private function auditCountForUser(int $userId): int
    {
        $user = $this->em->getReference(User::class, $userId);

        return (int) $this->em->getRepository(DataAccessAudit::class)->count(['user' => $user]);
    }

    private function latestAuditForUser(int $userId): ?DataAccessAudit
    {
        $user = $this->em->getReference(User::class, $userId);

        return $this->em->getRepository(DataAccessAudit::class)->findOneBy(['user' => $user], ['id' => 'DESC']);
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, "{$email} must be seeded. Run: composer test:reset-db");

        return (int) $user->getId();
    }
}
