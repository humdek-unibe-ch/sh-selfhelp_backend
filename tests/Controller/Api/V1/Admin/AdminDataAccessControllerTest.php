<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataTable;
use App\Entity\Role;
use App\Entity\User;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * P0 coverage for role-based data access
 * ({@see \App\Controller\Api\V1\Admin\AdminDataAccessController} +
 * {@see DataAccessSecurityService}/{@see DataTableService}).
 *
 * Two layers are covered:
 *  - the admin API that lists/sets role grants (HTTP, admin-only matrix), and
 *  - the deny-by-default permission engine: a NON-admin user with a specific
 *    table grant can read but not delete and cannot touch a table outside the
 *    grant; a user with no grant is denied everything; the admin (full grant)
 *    reads every user's rows. These are the "allowed vs denied" / "full-table
 *    vs restricted" properties the plan calls out.
 */
final class AdminDataAccessControllerTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const BASE = '/cms-api/v1/admin/data-access';

    private EntityManagerInterface $em;
    private DataTableFactory $dataTables;
    private RoleDataAccessFactory $grants;
    private DataTableService $dataTableService;
    private DataService $dataService;
    private LookupService $lookups;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $dataService = $container->get(DataService::class);
        self::assertInstanceOf(DataService::class, $dataService);
        $this->dataService = $dataService;

        $dataTableService = $container->get(DataTableService::class);
        self::assertInstanceOf(DataTableService::class, $dataTableService);
        $this->dataTableService = $dataTableService;

        $lookups = $container->get(LookupService::class);
        self::assertInstanceOf(LookupService::class, $lookups);
        $this->lookups = $lookups;

        $dataAccessSecurity = $container->get(DataAccessSecurityService::class);
        self::assertInstanceOf(DataAccessSecurityService::class, $dataAccessSecurity);

        $this->dataTables = new DataTableFactory($this->em, $this->dataService);
        $this->grants = new RoleDataAccessFactory($this->em, $this->lookups, $dataAccessSecurity);
    }

    // -- Admin API ----------------------------------------------------------

    public function testGetRolesWithPermissionsReturnsSeededAdminRole(): void
    {
        $envelope = $this->jsonRequest('GET', self::BASE . '/roles', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertNotEmpty($data, 'At least the seeded admin role must be listed.');
        $roleNames = array_column($data, 'role_name');
        self::assertContains('admin', $roleNames, 'The admin role must appear in the data-access role list.');
    }

    public function testSetRolePermissionsAddsGrantVisibleInEffectivePermissions(): void
    {
        $role = $this->grants->createRole('qa_data_access_role');
        $table = $this->dataTables->createTable('qa_data_access_table');
        $token = $this->loginAsQaAdmin();
        $resourceTypeId = (int) $this->lookups->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_DATA_TABLE,
        );

        $setEnvelope = $this->jsonRequest(
            'POST',
            self::BASE . '/roles/' . $role->getId() . '/permissions',
            ['permissions' => [[
                'resource_type_id' => $resourceTypeId,
                'resource_id' => (int) $table->getId(),
                'crud_permissions' => DataAccessSecurityService::PERMISSION_READ,
            ]]],
            $token,
        );
        $setData = $this->assertEnvelopeSuccess($setEnvelope);
        $changes = $this->asArray($setData['changes'] ?? null);
        self::assertSame(1, $this->coerceInt($changes['added'] ?? -1), 'Setting one new grant must add exactly one.');

        // Side effect visible through the effective-permissions read.
        $effective = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/roles/' . $role->getId() . '/effective-permissions', null, $token)
        );
        self::assertSame((int) $role->getId(), $this->asInt($effective['role_id'] ?? null));
        $resourceIds = array_column($this->asList($effective['effective_permissions'] ?? null), 'resource_id');
        self::assertContains(
            (int) $table->getId(),
            array_map(fn (mixed $v): int => $this->coerceInt($v), $resourceIds),
            'The new grant must show up as an effective permission.'
        );
    }

    public function testSetRolePermissionsRejectsInvalidBody(): void
    {
        $role = $this->grants->createRole('qa_data_access_role_invalid');

        $envelope = $this->jsonRequest(
            'POST',
            self::BASE . '/roles/' . $role->getId() . '/permissions',
            ['permissions' => [['resource_type_id' => 0]]], // missing required fields + minimum violation
            $this->loginAsQaAdmin(),
        );
        $this->assertEnvelope400($envelope);
    }

    #[Group('security')]
    public function testDataAccessRoutesAreAdminOnly(): void
    {
        $adminRoleId = $this->adminRoleId();

        $this->assertAdminOnlyMatrix('GET', self::BASE . '/roles');
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/roles/' . $adminRoleId . '/effective-permissions');
        $this->assertForbiddenForNonAdmins('POST', self::BASE . '/roles/' . $adminRoleId . '/permissions', ['permissions' => []]);
    }

    // -- Permission engine (deny by default) --------------------------------

    public function testUserWithoutGrantIsDeniedTableAccess(): void
    {
        $table = $this->dataTables->createTable('qa_da_denied_table');

        self::assertFalse(
            $this->dataTableService->canAccessDataTable($this->qaUserId(), (int) $table->getId(), DataAccessSecurityService::PERMISSION_READ),
            'Deny-by-default: a user with no grant must not read the table.'
        );
    }

    public function testReadGrantAllowsReadButNotDeleteAndNotOtherTables(): void
    {
        $granted = $this->dataTables->createTable('qa_da_granted_table');
        $other = $this->dataTables->createTable('qa_da_other_table');

        $role = $this->grants->createRole('qa_da_reader_role');
        $this->grants->grantDataTableAccess($role, (int) $granted->getId(), DataAccessSecurityService::PERMISSION_READ);
        $this->grants->assignRoleToUser($this->qaUser(), $role);

        $userId = $this->qaUserId();
        $grantedId = (int) $granted->getId();
        $otherId = (int) $other->getId();

        self::assertTrue(
            $this->dataTableService->canAccessDataTable($userId, $grantedId, DataAccessSecurityService::PERMISSION_READ),
            'A READ grant must allow reading the granted table.'
        );
        self::assertFalse(
            $this->dataTableService->canAccessDataTable($userId, $grantedId, DataAccessSecurityService::PERMISSION_DELETE),
            'A READ-only grant must not confer DELETE (bit-flag enforcement).'
        );
        self::assertFalse(
            $this->dataTableService->canAccessDataTable($userId, $otherId, DataAccessSecurityService::PERMISSION_READ),
            'A grant on one table must not leak to another table.'
        );
    }

    public function testAdminFullTableReadSeesEveryUsersRows(): void
    {
        $tableName = 'qa_da_fulltable';
        $this->dataTables->addRow($tableName, ['qa_field' => 'by-user'], $this->qaUserId());
        $this->dataTables->addRow($tableName, ['qa_field' => 'by-editor'], $this->qaEditorId());

        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => $tableName]);
        self::assertInstanceOf(DataTable::class, $table);

        // Full-table read (ownEntriesOnly = false, userId = -1) returns all owners' rows.
        $rows = $this->dataService->getData((int) $table->getId(), '', false, null, false, true);
        $owners = [];
        foreach ($rows as $rawRow) {
            $row = $this->asArray($rawRow);
            $owners[] = $this->coerceInt($row['id_users'] ?? 0);
        }

        self::assertContains($this->qaUserId(), $owners, 'Full-table read must include qa.user rows.');
        self::assertContains($this->qaEditorId(), $owners, 'Full-table read must include qa.editor rows.');
    }

    // -- helpers ------------------------------------------------------------

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function qaUserId(): int
    {
        return (int) $this->qaUser()->getId();
    }

    private function qaEditorId(): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_EDITOR_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }

    private function adminRoleId(): int
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => 'admin']);
        self::assertInstanceOf(Role::class, $role);

        return (int) $role->getId();
    }
}
