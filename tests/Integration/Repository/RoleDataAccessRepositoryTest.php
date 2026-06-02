<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\RoleDataAccess;
use App\Entity\User;
use App\Repository\RoleDataAccessRepository;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for {@see RoleDataAccessRepository} — the deny-by-default
 * data-access query layer behind {@see DataAccessSecurityService} (plan Phase 9:
 * repository integration tests). Grants are built through the QA factory so the
 * "user inherits role grant" join is exercised against the real schema.
 */
final class RoleDataAccessRepositoryTest extends QaKernelTestCase
{
    private RoleDataAccessRepository $repository;
    private RoleDataAccessFactory $grants;
    private DataTableFactory $tables;
    private int $dataTableTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->service(RoleDataAccessRepository::class);
        $lookups = $this->service(LookupService::class);
        $this->grants = new RoleDataAccessFactory($this->em, $lookups, $this->service(DataAccessSecurityService::class));
        $this->tables = new DataTableFactory($this->em, $this->service(DataService::class));

        $typeId = $lookups->getLookupIdByCode(LookupService::RESOURCE_TYPES, LookupService::RESOURCE_TYPES_DATA_TABLE);
        self::assertNotNull($typeId, 'The data_table resource-type lookup must be seeded.');
        $this->dataTableTypeId = $typeId;
    }

    public function testUserPermissionsAggregateGrantedBits(): void
    {
        $user = $this->qaUser();
        $table = $this->tables->createTable('qa_role_access_table');
        $role = $this->grants->createRole('qa_role_access_role');
        $this->grants->assignRoleToUser($user, $role);
        $crud = DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE;
        $this->grants->grantDataTableAccess($role, (int) $table->getId(), $crud);

        $bits = $this->repository->getUserPermissionsForResource(
            (int) $user->getId(),
            $this->dataTableTypeId,
            (int) $table->getId(),
        );

        self::assertSame($crud, $bits, 'The repository must aggregate the granted CRUD bitmask.');
    }

    public function testUserWithoutGrantGetsNull(): void
    {
        $user = $this->qaUser();
        $table = $this->tables->createTable('qa_role_access_ungranted_table');

        self::assertNull(
            $this->repository->getUserPermissionsForResource((int) $user->getId(), $this->dataTableTypeId, (int) $table->getId()),
            'A resource the user has no grant for must resolve to null (deny-by-default).',
        );
    }

    public function testFindPermissionResolvesGrantAndNullForUnknown(): void
    {
        $table = $this->tables->createTable('qa_role_access_find_table');
        $role = $this->grants->createRole('qa_role_access_find_role');
        $this->grants->grantDataTableAccess($role, (int) $table->getId(), DataAccessSecurityService::PERMISSION_READ);

        $found = $this->repository->findPermission((int) $role->getId(), $this->dataTableTypeId, (int) $table->getId());
        self::assertInstanceOf(RoleDataAccess::class, $found);
        self::assertSame(DataAccessSecurityService::PERMISSION_READ, $found->getCrudPermissions());

        self::assertNull(
            $this->repository->findPermission((int) $role->getId(), $this->dataTableTypeId, 99999999),
            'An unknown resource id must resolve to null.',
        );
    }

    public function testGetRolePermissionsIncludesTheGrant(): void
    {
        $table = $this->tables->createTable('qa_role_access_list_table');
        $role = $this->grants->createRole('qa_role_access_list_role');
        $this->grants->grantDataTableAccess($role, (int) $table->getId(), DataAccessSecurityService::PERMISSION_READ);

        $permissions = $this->repository->getRolePermissions((int) $role->getId());

        self::assertNotEmpty($permissions);
        $resourceIds = array_map(static fn (RoleDataAccess $p): int => (int) $p->getResourceId(), $permissions);
        self::assertContains((int) $table->getId(), $resourceIds);
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return $user;
    }
}
