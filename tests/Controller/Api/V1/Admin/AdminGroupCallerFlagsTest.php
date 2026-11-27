<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rule 4.11 for groups: the list says per group what the caller may do.
 * `can_update` / `can_delete` need the route right (admin.group.update /
 * admin.group.delete) and the matching grant bit on that group, so a view-only
 * grant draws no Edit or Delete control (F239 least-privilege walk).
 */
#[TestGroup('security')]
final class AdminGroupCallerFlagsTest extends QaWebTestCase
{
    private const FULL_EMAIL = 'qa.group.flags.full@selfhelp.test';
    private const READ_ONLY_RIGHTS_EMAIL = 'qa.group.flags.readrights@selfhelp.test';
    private const GROUPS_URI = '/cms-api/v1/admin/groups?pageSize=100&search=qa_group_flags_';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->service(EntityManagerInterface::class);
        $roles = new RoleFactory($em);
        $dataAccess = new RoleDataAccessFactory(
            $em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class)
        );
        $groups = new GroupFactory($em);
        $users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class)
        );

        $viewOnly = $groups->createGroup('qa_group_flags_view');
        $updateOnly = $groups->createGroup('qa_group_flags_update');
        $full = $groups->createGroup('qa_group_flags_full');

        $read = DataAccessSecurityService::PERMISSION_READ;
        $update = $read | DataAccessSecurityService::PERMISSION_UPDATE;
        $delete = $update | DataAccessSecurityService::PERMISSION_DELETE;

        // Holds every group route right and a different grant on each group.
        $fullRole = $roles->createRole('qa_group_flags_full_role');
        foreach (['admin.access', 'admin.group.read', 'admin.group.update', 'admin.group.delete'] as $permission) {
            $roles->grantPermission($fullRole, $permission);
        }
        $dataAccess->grantGroupAccess($fullRole, (int) $viewOnly->getId(), $read);
        $dataAccess->grantGroupAccess($fullRole, (int) $updateOnly->getId(), $update);
        $dataAccess->grantGroupAccess($fullRole, (int) $full->getId(), $delete);
        $users->createUser(self::FULL_EMAIL, 'QA Group Flags Full', roles: [$fullRole]);

        // Holds the delete grant bits but only the read route right: the route right is missing.
        $readRole = $roles->createRole('qa_group_flags_read_role');
        foreach (['admin.access', 'admin.group.read'] as $permission) {
            $roles->grantPermission($readRole, $permission);
        }
        $dataAccess->grantGroupAccess($readRole, (int) $full->getId(), $delete);
        $users->createUser(self::READ_ONLY_RIGHTS_EMAIL, 'QA Group Flags Read', roles: [$readRole]);
    }

    public function testFlagsFollowTheGrantBitsOfEachGroup(): void
    {
        $flags = $this->flagsByName($this->loginAs(self::FULL_EMAIL));

        self::assertSame(
            [
                'qa_group_flags_full' => [true, true],
                'qa_group_flags_update' => [true, false],
                'qa_group_flags_view' => [false, false],
            ],
            $flags,
            'can_update / can_delete must follow the grant bits on each group'
        );
    }

    public function testNoFlagWithoutTheRouteRight(): void
    {
        $flags = $this->flagsByName($this->loginAs(self::READ_ONLY_RIGHTS_EMAIL));

        self::assertSame(
            ['qa_group_flags_full' => [false, false]],
            $flags,
            'Grant bits alone do not enable the controls: admin.group.update / admin.group.delete are needed too'
        );
    }

    public function testSuperuserHoldsEveryFlag(): void
    {
        $envelope = $this->jsonRequest('GET', self::GROUPS_URI, null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);
        $groups = $data['groups'] ?? null;
        self::assertIsArray($groups);
        self::assertNotSame([], $groups);
        foreach ($groups as $group) {
            self::assertIsArray($group);
            self::assertTrue($group['can_update'] ?? null);
            self::assertTrue($group['can_delete'] ?? null);
        }
    }

    /**
     * @return array<string, array{0: bool, 1: bool}> group name => [can_update, can_delete]
     */
    private function flagsByName(string $token): array
    {
        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::GROUPS_URI, null, $token));
        $groups = $data['groups'] ?? null;
        self::assertIsArray($groups);

        $flags = [];
        foreach ($groups as $group) {
            self::assertIsArray($group);
            self::assertIsString($group['name'] ?? null);
            self::assertIsBool($group['can_update'] ?? null);
            self::assertIsBool($group['can_delete'] ?? null);
            $flags[$group['name']] = [$group['can_update'], $group['can_delete']];
        }
        ksort($flags);

        return $flags;
    }
}
