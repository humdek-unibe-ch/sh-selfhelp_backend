<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Service\Core\LookupService;
use App\Service\Security\CreatorGrantService;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rule 6b for groups: whoever creates a group can manage it. The caller's roles
 * that hold `admin.group.create` get full data-access on the new group; a
 * companion role without create gets nothing; the admin role is never stored.
 * Before 2026-11-19 the creator could not even see the group it had created.
 */
#[PhpUnitGroup('security')]
final class GroupCreatorGrantTest extends QaWebTestCase
{
    private const CREATOR_EMAIL = 'qa.group.creator@selfhelp.test';

    private EntityManagerInterface $em;
    private int $creatorRoleId;
    private int $readerRoleId;
    private string $creatorToken;

    protected function setUp(): void
    {
        parent::setUp();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $roles = new RoleFactory($this->em);
        $users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );

        $creator = $roles->createRole('qa_group_creator_role');
        foreach (['admin.access', 'admin.group.read', 'admin.group.create'] as $permission) {
            $roles->grantPermission($creator, $permission);
        }
        $reader = $roles->createRole('qa_group_reader_role');
        $roles->grantPermission($reader, 'admin.access');
        $roles->grantPermission($reader, 'admin.group.read');

        $users->createUser(self::CREATOR_EMAIL, 'QA Group Creator', roles: [$creator, $reader]);
        $this->creatorRoleId = (int) $creator->getId();
        $this->readerRoleId = (int) $reader->getId();
        $this->creatorToken = $this->loginAs(self::CREATOR_EMAIL);
    }

    public function testCreatorRoleGetsFullGrantAndSeesTheNewGroup(): void
    {
        $created = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/groups', [
                'name' => 'qa_created_group',
                'description' => 'QA group made by a scoped admin',
            ], $this->creatorToken),
            Response::HTTP_CREATED,
        );
        $groupId = self::asInt($created['id'] ?? null);
        self::assertGreaterThan(0, $groupId);

        self::assertSame(CreatorGrantService::FULL_ACCESS, $this->groupGrantCrud($this->creatorRoleId, $groupId));
        self::assertNull($this->groupGrantCrud($this->readerRoleId, $groupId), 'A role without admin.group.create gets no grant.');
        self::assertNull($this->groupGrantCrud($this->adminRoleId(), $groupId), 'The admin role is never stored.');

        $list = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/groups?pageSize=100&search=qa_created_group', null, $this->creatorToken),
        );
        $groups = $list['groups'] ?? null;
        self::assertIsArray($groups);
        $names = array_map(static fn (mixed $group): mixed => is_array($group) ? ($group['name'] ?? null) : null, $groups);
        self::assertContains('qa_created_group', $names, 'The creator must see the group it created.');
    }

    private function groupGrantCrud(int $roleId, int $groupId): ?int
    {
        $this->em->clear();
        $typeId = $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_GROUP,
        );
        self::assertNotNull($typeId);
        $grant = $this->em->getRepository(RoleDataAccess::class)->findOneBy([
            'idRoles' => $roleId,
            'idResourceTypes' => $typeId,
            'resourceId' => $groupId,
        ]);

        return $grant instanceof RoleDataAccess ? $grant->getCrudPermissions() : null;
    }

    private function adminRoleId(): int
    {
        $admin = $this->em->getRepository(Role::class)->findOneBy(['isSuperuser' => true]);
        self::assertInstanceOf(Role::class, $admin);

        return (int) $admin->getId();
    }
}
