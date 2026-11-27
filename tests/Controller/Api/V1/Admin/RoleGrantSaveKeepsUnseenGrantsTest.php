<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * F229 regression: a role grant popup replaces the role's whole grant set, so a
 * manager who sees only some of the role's items must never silently drop the
 * grants on the others. Rule 4.6f refuses the save before anything is written
 * (the role holds a bit the manager does not), so nothing is lost; a caller who
 * sees everything (the admin role) still saves the whole set.
 */
#[Group('security')]
final class RoleGrantSaveKeepsUnseenGrantsTest extends QaWebTestCase
{
    private const MANAGER_EMAIL = 'qa.grant.save.manager@selfhelp.test';
    private const DATA_ACCESS_URI = '/cms-api/v1/admin/data-access/roles';

    private int $targetRoleId;
    private int $pageXId;
    private int $pageYId;
    private int $pagesResourceTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->entityManager();
        $roles = new RoleFactory($em);
        $dataAccess = new RoleDataAccessFactory(
            $em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class)
        );
        $pages = new PageSectionFactory(
            $em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class)
        );

        $pageX = $pages->createPage('qa_grant_save_x', openAccess: false);
        $pageY = $pages->createPage('qa_grant_save_y', openAccess: false);

        // (a) The target role has a view grant on page X and on page Y.
        $target = $roles->createRole('qa_grant_target');
        $dataAccess->grantPageAccess($target, (int) $pageX->getId(), DataAccessSecurityService::PERMISSION_READ);
        $dataAccess->grantPageAccess($target, (int) $pageY->getId(), DataAccessSecurityService::PERMISSION_READ);

        // The manager edits roles and sees page Y only.
        $manager = $roles->createRole('qa_grant_manager');
        foreach (['admin.access', 'admin.role.read', 'admin.role.update'] as $permission) {
            $roles->grantPermission($manager, $permission);
        }
        $dataAccess->grantPageAccess($manager, (int) $pageY->getId(), DataAccessSecurityService::PERMISSION_READ);
        $users->createUser(self::MANAGER_EMAIL, 'QA Grant Save Manager', roles: [$manager]);

        $this->targetRoleId = (int) $target->getId();
        $this->pageXId = (int) $pageX->getId();
        $this->pageYId = (int) $pageY->getId();
        $this->pagesResourceTypeId = (int) $this->service(LookupService::class)->getLookupIdByCode(
            LookupService::RESOURCE_TYPES,
            LookupService::RESOURCE_TYPES_PAGES
        );
    }

    public function testManagerWhoSeesOnlyPageYIsRefusedAndTheUnseenGrantStays(): void
    {
        $this->assertSame([$this->pageXId, $this->pageYId], $this->targetPageGrantIds(), 'Setup: X and Y are granted.');

        $denied = $this->jsonRequest(
            'POST',
            $this->grantUri(),
            $this->onlyPageY(),
            $this->loginAs(self::MANAGER_EMAIL)
        );

        $this->assertEnvelope403($denied);
        self::assertSame(
            'You can only change or delete a role whose rights you all hold.',
            $denied['error'] ?? null
        );
        self::assertSame(
            [$this->pageXId, $this->pageYId],
            $this->targetPageGrantIds(),
            'The grant on page X, which the manager cannot see, must not be dropped.'
        );
    }

    public function testCallerWhoSeesEverythingSavesTheWholeSet(): void
    {
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', $this->grantUri(), $this->onlyPageY(), $this->loginAsQaAdmin())
        );

        self::assertSame(
            [$this->pageYId],
            $this->targetPageGrantIds(),
            'A caller who sees every item replaces the whole set: the grant on page X is removed.'
        );
    }

    private function grantUri(): string
    {
        return self::DATA_ACCESS_URI . '/' . $this->targetRoleId . '/permissions';
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyPageY(): array
    {
        return ['permissions' => [[
            'resource_type_id' => $this->pagesResourceTypeId,
            'resource_id' => $this->pageYId,
            'crud_permissions' => DataAccessSecurityService::PERMISSION_READ,
        ]]];
    }

    /**
     * Page ids the target role is granted on, read back from the database.
     *
     * @return list<int>
     */
    private function targetPageGrantIds(): array
    {
        $em = $this->entityManager();
        $em->clear();
        $role = $em->getRepository(Role::class)->find($this->targetRoleId);
        self::assertInstanceOf(Role::class, $role);

        $ids = [];
        foreach ($em->getRepository(RoleDataAccess::class)->findBy(['role' => $role]) as $grant) {
            if ((int) $grant->getResourceType()->getId() === $this->pagesResourceTypeId) {
                $ids[] = (int) $grant->getResourceId();
            }
        }
        sort($ids);

        return $ids;
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }
}
