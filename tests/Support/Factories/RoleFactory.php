<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Factories;

use App\Entity\Permission;
use App\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds `qa_`-prefixed {@see Role} rows and links them to existing (seeded) core
 * {@see Permission} rows so tests can hand a non-admin user a precise route
 * permission and prove the {@see \App\EventListener\ApiSecurityListener} +
 * {@see \App\Service\Auth\UserPermissionService} stack honours it.
 *
 * Roles never carry the seeded `admin` name (that grants the admin override), so
 * a qa role only conveys exactly the permissions explicitly granted here.
 * Everything is created through the real EntityManager inside the DAMA
 * transaction and rolled back at tearDown.
 */
final class RoleFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function createRole(string $name = 'qa_role', string $description = 'QA test role'): Role
    {
        $existing = $this->em->getRepository(Role::class)->findOneBy(['name' => $name]);
        if ($existing instanceof Role) {
            return $existing;
        }

        $role = new Role();
        $role->setName($name);
        $role->setDescription($description);
        $this->em->persist($role);
        $this->em->flush();

        return $role;
    }

    /**
     * Grant the role an existing core permission, looked up by its canonical name
     * (e.g. `admin.user.read`). Throws if the permission is not seeded so a typo
     * fails loudly instead of silently granting nothing.
     */
    public function grantPermission(Role $role, string $permissionName): Role
    {
        $permission = $this->em->getRepository(Permission::class)->findOneBy(['name' => $permissionName]);
        if (!$permission instanceof Permission) {
            throw new \RuntimeException(sprintf(
                'Missing permission "%s". Run: composer test:reset-db',
                $permissionName,
            ));
        }

        $role->addPermission($permission);
        $this->em->flush();

        return $role;
    }
}
