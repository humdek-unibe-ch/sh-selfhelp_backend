<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Group;
use App\Entity\Page;
use App\Entity\PageAclGroup;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rule 4.6g: changing which pages a group sees needs update on the group and
 * on every page whose visibility is added or removed; reading a group needs
 * read on that group. Unchanged ACL rows need nothing.
 *
 * Setup: caller holds admin.group.read/acl (+ admin.access), update on group G
 * and page P1 only — no grant on page P2, and a second group H with no grants.
 */
#[TestGroup('security')]
final class AdminGroupAclScopeTest extends QaWebTestCase
{
    private const CALLER_EMAIL = 'qa.group.acl.scoped@selfhelp.test';
    private const GROUPS_URI = '/cms-api/v1/admin/groups';

    private int $groupGId;
    private int $groupHId;
    private int $pageP1Id;
    private int $pageP2Id;

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
        $groups = new GroupFactory($em);
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

        $groupG = $groups->createGroup('qa_group_acl_g');
        $groupH = $groups->createGroup('qa_group_acl_h');
        $pageP1 = $pages->createPage('qa_group_acl_p1', openAccess: false);
        $pageP2 = $pages->createPage('qa_group_acl_p2', openAccess: false);

        $callerRole = $roles->createRole('qa_group_acl_scoped_role');
        foreach (['admin.access', 'admin.group.read', 'admin.group.acl'] as $permission) {
            $roles->grantPermission($callerRole, $permission);
        }

        $groupUpdate = DataAccessSecurityService::PERMISSION_READ
            | DataAccessSecurityService::PERMISSION_UPDATE;
        $pageUpdate = DataAccessSecurityService::PERMISSION_READ
            | DataAccessSecurityService::PERMISSION_UPDATE;
        $dataAccess->grantGroupAccess($callerRole, (int) $groupG->getId(), $groupUpdate);
        $dataAccess->grantPageAccess($callerRole, (int) $pageP1->getId(), $pageUpdate);

        $users->createUser(self::CALLER_EMAIL, 'QA Group ACL Scoped', roles: [$callerRole]);

        $this->groupGId = (int) $groupG->getId();
        $this->groupHId = (int) $groupH->getId();
        $this->pageP1Id = (int) $pageP1->getId();
        $this->pageP2Id = (int) $pageP2->getId();
    }

    // -- P3-A1: page visibility changes -------------------------------------

    public function testScopedCallerMayAddPageWithUpdateGrantAndIsRefusedWithout(): void
    {
        $token = $this->loginAs(self::CALLER_EMAIL);

        $ok = $this->jsonRequest(
            'PUT',
            $this->aclsUri($this->groupGId),
            ['acls' => [['page_id' => $this->pageP1Id, 'acl_select' => true]]],
            $token
        );
        $this->assertEnvelopeSuccess($ok);
        self::assertSame(
            [$this->pageP1Id],
            $this->selectPageIdsFromEnvelope($ok),
            'Adding P1 (update granted) must succeed'
        );

        $denied = $this->jsonRequest(
            'PUT',
            $this->aclsUri($this->groupGId),
            ['acls' => [
                ['page_id' => $this->pageP1Id, 'acl_select' => true],
                ['page_id' => $this->pageP2Id, 'acl_select' => true],
            ]],
            $token
        );
        $this->assertEnvelope403($denied);
        self::assertIsString($denied['error']);
        self::assertStringContainsString((string) $this->pageP2Id, (string) $denied['error']);
        self::assertStringContainsString('update on page', (string) $denied['error']);
        self::assertSame(
            [$this->pageP1Id],
            $this->selectPageIdsOfGroup($this->groupGId),
            'A refused ACL write must leave the group unchanged'
        );
    }

    public function testUnchangedPageWithoutGrantDoesNotBlockAddingAnother(): void
    {
        $this->seedGroupSelect($this->groupGId, $this->pageP2Id);

        $token = $this->loginAs(self::CALLER_EMAIL);
        $ok = $this->jsonRequest(
            'PUT',
            $this->aclsUri($this->groupGId),
            ['acls' => [
                ['page_id' => $this->pageP1Id, 'acl_select' => true],
                ['page_id' => $this->pageP2Id, 'acl_select' => true],
            ]],
            $token
        );
        $this->assertEnvelopeSuccess($ok);
        self::assertEqualsCanonicalizing(
            [$this->pageP1Id, $this->pageP2Id],
            $this->selectPageIdsFromEnvelope($ok),
            'Keeping P2 unchanged while adding P1 needs no grant on P2'
        );
    }

    // -- P3-A2: update on the group -----------------------------------------

    public function testScopedCallerWithoutUpdateOnGroupIsRefusedForAnyAclChange(): void
    {
        $token = $this->loginAs(self::CALLER_EMAIL);

        $denied = $this->jsonRequest(
            'PUT',
            $this->aclsUri($this->groupHId),
            ['acls' => [['page_id' => $this->pageP1Id, 'acl_select' => true]]],
            $token
        );
        $this->assertEnvelope403($denied);
        self::assertIsString($denied['error']);
        self::assertStringContainsString(
            'update on group ' . $this->groupHId,
            (string) $denied['error']
        );
        self::assertSame([], $this->selectPageIdsOfGroup($this->groupHId));
    }

    // -- P3-A3: read grant on get group / acls ------------------------------

    public function testGetGroupAndAclsNeedReadGrant(): void
    {
        $token = $this->loginAs(self::CALLER_EMAIL);

        $deniedGroup = $this->jsonRequest('GET', self::GROUPS_URI . '/' . $this->groupHId, null, $token);
        $this->assertEnvelope403($deniedGroup);
        self::assertIsString($deniedGroup['error']);
        self::assertStringContainsString(
            'you cannot read group ' . $this->groupHId,
            (string) $deniedGroup['error']
        );

        $deniedAcls = $this->jsonRequest('GET', $this->aclsUri($this->groupHId), null, $token);
        $this->assertEnvelope403($deniedAcls);
        self::assertIsString($deniedAcls['error']);
        self::assertStringContainsString(
            'you cannot read group ' . $this->groupHId,
            (string) $deniedAcls['error']
        );

        $okGroup = $this->jsonRequest('GET', self::GROUPS_URI . '/' . $this->groupGId, null, $token);
        $this->assertEnvelopeSuccess($okGroup);

        $okAcls = $this->jsonRequest('GET', $this->aclsUri($this->groupGId), null, $token);
        $this->assertEnvelopeSuccess($okAcls);
    }

    public function testSuperuserMayReadAnyGroupAndChangeAnyAcl(): void
    {
        $token = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::GROUPS_URI . '/' . $this->groupHId, null, $token)
        );
        $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', $this->aclsUri($this->groupHId), null, $token)
        );

        $ok = $this->jsonRequest(
            'PUT',
            $this->aclsUri($this->groupHId),
            ['acls' => [
                ['page_id' => $this->pageP1Id, 'acl_select' => true],
                ['page_id' => $this->pageP2Id, 'acl_select' => true],
            ]],
            $token
        );
        $this->assertEnvelopeSuccess($ok);
        self::assertEqualsCanonicalizing(
            [$this->pageP1Id, $this->pageP2Id],
            $this->selectPageIdsFromEnvelope($ok)
        );
    }

    // -- helpers ------------------------------------------------------------

    private function aclsUri(int $groupId): string
    {
        return self::GROUPS_URI . '/' . $groupId . '/acls';
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    private function seedGroupSelect(int $groupId, int $pageId): void
    {
        $em = $this->entityManager();
        $group = $em->getRepository(Group::class)->find($groupId);
        $page = $em->getRepository(Page::class)->find($pageId);
        self::assertInstanceOf(Group::class, $group);
        self::assertInstanceOf(Page::class, $page);

        $acl = new PageAclGroup();
        $acl->setGroup($group);
        $acl->setPage($page);
        $acl->setAclSelect(true);
        $em->persist($acl);
        $em->flush();
    }

    /**
     * @return list<int>
     */
    private function selectPageIdsOfGroup(int $groupId): array
    {
        $em = $this->entityManager();
        $em->clear();
        $group = $em->getRepository(Group::class)->find($groupId);
        self::assertInstanceOf(Group::class, $group);

        /** @var list<PageAclGroup> $acls */
        $acls = $em->getRepository(PageAclGroup::class)->findBy(['group' => $group]);
        $ids = [];
        foreach ($acls as $acl) {
            if (!$acl->getAclSelect()) {
                continue;
            }
            $page = $acl->getPage();
            if ($page?->getId() !== null) {
                $ids[] = (int) $page->getId();
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $envelope
     * @return list<int>
     */
    private function selectPageIdsFromEnvelope(array $envelope): array
    {
        $data = $envelope['data'] ?? null;
        self::assertIsArray($data);
        $acls = $data['acls'] ?? null;
        self::assertIsArray($acls);

        $ids = [];
        foreach ($acls as $acl) {
            self::assertIsArray($acl);
            if (!(bool) ($acl['acl_select'] ?? false)) {
                continue;
            }
            $ids[] = self::coerceInt($acl['page_id'] ?? null, 'ACL page_id must be numeric');
        }
        sort($ids);

        return $ids;
    }
}
