<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Golden user-management lifecycle:
 *
 *   create a fresh group + an active member user -> the user logs in and
 *   bootstraps its identity through /auth/user-data (groups visible) -> the
 *   user reads a page its group is ACL-granted on, and is 403 on a page it is
 *   not -> admin blocks the user (state persisted) -> admin deletes the user
 *   (gone).
 *
 * Unlike {@see \App\Tests\Controller\Api\V1\AdminUserControllerTest} (which
 * checks each admin CRUD route in isolation), this asserts the cross-cutting
 * chain — real login of a non-persona factory user, the identity/group payload
 * the frontend bootstraps with, group-driven ACL gating, and the admin
 * block/delete lifecycle — as a single workflow (plan §5/§16). All data is
 * qa-prefixed and rolled back by the DAMA transaction.
 */
#[TestGroup('golden')]
final class UserManagementLifecycleTest extends QaWebTestCase
{
    private const USER_EMAIL = 'qa.lifecycle.user@selfhelp.test';
    private const PAGE_KEYWORD = 'qa_user_lifecycle_page';

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;
    private UserFactory $users;
    private GroupFactory $groups;

    protected function setUp(): void
    {
        parent::setUp();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $this->groups = new GroupFactory($this->em);
    }

    public function testUserLifecycleFromCreationThroughAclGrantToDeletion(): void
    {
        // 1. A fresh group and an active member user (not one of the personas).
        $group = $this->groups->createGroup('qa_lifecycle_group');
        $user = $this->users->createUser(self::USER_EMAIL, 'QA Lifecycle User', groups: [$group]);
        $userId = (int) $user->getId();
        self::assertGreaterThan(0, $userId, 'Factory user must persist with an id.');

        // 2. Two non-open pages: one the group is granted ACL select on, one it is
        //    not. All EM mutations happen before any HTTP request (the kernel
        //    clears the shared EntityManager mid-request).
        $grantedPage = $this->pages->createPage(self::PAGE_KEYWORD . '_granted', openAccess: false);
        $this->pages->linkSectionToPage($grantedPage, $this->pages->createSection('qa_lifecycle_granted_s'), 10);
        $grantedId = (int) $grantedPage->getId();
        $this->pages->grantGroupAcl($grantedPage, $group, select: true, insert: false, update: false, delete: false);

        $deniedPage = $this->pages->createPage(self::PAGE_KEYWORD . '_denied', openAccess: false);
        $this->pages->linkSectionToPage($deniedPage, $this->pages->createSection('qa_lifecycle_denied_s'), 10);
        $deniedId = (int) $deniedPage->getId();

        // 3. The user can authenticate (active status + hashed QA password) and
        //    bootstrap its identity exactly as the frontend BFF does after login.
        $userToken = $this->loginAs(self::USER_EMAIL);
        $identity = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/auth/user-data', null, $userToken)
        );
        self::assertSame($userId, $identity['id'] ?? null, 'user-data must resolve the logged-in factory user.');
        self::assertSame(self::USER_EMAIL, $identity['email'] ?? null);
        $groupNames = array_column($this->asList($identity['groups'] ?? []), 'name');
        self::assertContains('qa_lifecycle_group', $groupNames, 'Identity payload must reflect the membership.');

        // 4. ACL gating through the public API: granted page reads, ungranted 403.
        $allowed = $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . self::PAGE_KEYWORD . '_granted', null, $userToken);
        $pageData = $this->assertEnvelopeSuccess($allowed);
        self::assertSame($grantedId, $this->asArray($pageData['page'] ?? [])['id'] ?? null, 'Granted user must read the page.');

        $denied = $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . self::PAGE_KEYWORD . '_denied', null, $userToken);
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $denied['status'] ?? null,
            'A group with no ACL grant must be denied the page.'
        );

        // 5. Admin blocks the user; the new state is persisted and observable.
        $admin = $this->loginAsQaAdmin();
        $blockResponse = $this->jsonRequest(
            'PATCH',
            '/cms-api/v1/admin/users/' . $userId . '/block',
            ['blocked' => true],
            $admin,
        );
        $blockData = $this->assertEnvelopeSuccess($blockResponse);
        self::assertTrue($blockData['blocked'] ?? null, 'Block response must report blocked=true.');

        $this->em->clear();
        $persisted = $this->em->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $persisted);
        self::assertTrue($persisted->isBlocked(), 'Block must be persisted to the database.');

        // 6. Admin deletes the user; a follow-up read is 404.
        $deleted = $this->jsonRequest('DELETE', '/cms-api/v1/admin/users/' . $userId, null, $admin);
        $deletedData = $this->assertEnvelopeSuccess($deleted);
        self::assertTrue($deletedData['deleted'] ?? null, 'Delete response must report deleted=true.');

        $afterDelete = $this->jsonRequest('GET', '/cms-api/v1/admin/users/' . $userId, null, $admin);
        $this->assertEnvelope404($afterDelete);
    }
}
