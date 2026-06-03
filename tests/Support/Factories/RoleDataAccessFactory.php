<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Factories;

use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds `qa_`-prefixed roles and {@see RoleDataAccess} grants so tests can
 * exercise the deny-by-default data-access model with a NON-admin user that has
 * a specific table/page/group grant — the "editor with grant vs without grant"
 * matrix the plan requires.
 *
 * The production grant model is: a {@see Role} is linked to a resource
 * (data_table / pages / group) with a CRUD bitmask in `role_data_access`; a user
 * inherits the grant by holding that role. {@see DataAccessSecurityService}
 * reads it through {@see \App\Repository\RoleDataAccessRepository}.
 *
 * Everything is created through the real EntityManager inside the DAMA
 * transaction and rolled back at tearDown. The qa.admin persona is untouched.
 */
final class RoleDataAccessFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LookupService $lookupService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
    ) {
    }

    /**
     * Create (or reuse) a `qa_`-named role with no route permissions. It is a
     * pure data-access carrier, deliberately distinct from the seeded `admin`
     * role so it never grants admin-role overrides.
     */
    public function createRole(string $name = 'qa_data_role'): Role
    {
        $existing = $this->em->getRepository(Role::class)->findOneBy(['name' => $name]);
        if ($existing instanceof Role) {
            return $existing;
        }

        $role = new Role();
        $role->setName($name);
        $role->setDescription('QA data-access role');
        $this->em->persist($role);
        $this->em->flush();

        return $role;
    }

    /**
     * Attach a role to a user and clear that user's permission caches so the
     * grant is observed immediately (entity-scope generation bump, O(1)).
     */
    public function assignRoleToUser(User $user, Role $role): void
    {
        $user->addRole($role);
        $this->em->flush();

        $userId = $user->getId();
        if ($userId !== null) {
            $this->dataAccessSecurityService->invalidateUserPermissions($userId);
        }
    }

    /**
     * Grant a role CRUD permissions on a specific data table.
     *
     * @param int $crud bitmask of {@see DataAccessSecurityService}::PERMISSION_* flags
     */
    public function grantDataTableAccess(Role $role, int $dataTableId, int $crud = DataAccessSecurityService::PERMISSION_READ): RoleDataAccess
    {
        return $this->grant($role, LookupService::RESOURCE_TYPES_DATA_TABLE, $dataTableId, $crud);
    }

    /**
     * Grant a role CRUD permissions on a specific page.
     */
    public function grantPageAccess(Role $role, int $pageId, int $crud = DataAccessSecurityService::PERMISSION_READ): RoleDataAccess
    {
        return $this->grant($role, LookupService::RESOURCE_TYPES_PAGES, $pageId, $crud);
    }

    /**
     * Grant a role CRUD permissions on a specific group.
     */
    public function grantGroupAccess(Role $role, int $groupId, int $crud = DataAccessSecurityService::PERMISSION_READ): RoleDataAccess
    {
        return $this->grant($role, LookupService::RESOURCE_TYPES_GROUP, $groupId, $crud);
    }

    private function grant(Role $role, string $resourceTypeCode, int $resourceId, int $crud): RoleDataAccess
    {
        $resourceType = $this->lookupService->findByTypeAndCode(LookupService::RESOURCE_TYPES, $resourceTypeCode);
        if (!$resourceType instanceof Lookup) {
            throw new \RuntimeException(sprintf(
                'Missing resourceTypes lookup "%s". Run: composer test:reset-db',
                $resourceTypeCode
            ));
        }

        $grant = new RoleDataAccess();
        $grant->setRole($role)
            ->setResourceType($resourceType)
            ->setResourceId($resourceId)
            ->setCrudPermissions($crud);
        $this->em->persist($grant);
        $this->em->flush();

        $roleId = $role->getId();
        if ($roleId !== null) {
            $this->dataAccessSecurityService->invalidateRolePermissions($roleId);
        }

        return $grant;
    }
}
