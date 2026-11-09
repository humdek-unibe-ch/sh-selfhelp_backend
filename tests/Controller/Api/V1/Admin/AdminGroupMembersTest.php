<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\User;
use App\Entity\UsersGroup;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use App\Tests\Support\Security\PermissionMatrixProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * GET /admin/groups/{id}/users — the "View members" modal on the Groups page.
 *
 * Test impact analysis: the modal renders id/email/name/user_name/status/blocked
 * for a group's members. The behaviours that can silently mislead an admin are
 * (a) a missing group reading as "no members" rather than 404, and (b) the
 * member list disagreeing with the Users page about who exists (visibility
 * scoping). Both are covered.
 */
#[TestGroup('security')]
final class AdminGroupMembersTest extends QaWebTestCase
{
    use PermissionMatrixProvider;

    private const BASE = '/cms-api/v1/admin/groups';

    protected function setUp(): void
    {
        parent::setUp();

        // DAMA rolls the DB back per test, but the cache pool is filesystem and
        // survives — drop the group caches so a member list cached by an
        // earlier test cannot outlive the rows it described.
        $this->service(CacheService::class)
            ->withCategory(CacheService::CATEGORY_GROUPS)
            ->invalidateAllListsInCategory();
    }

    public function testReturnsMembersOfTheGroupWithTheContractFields(): void
    {
        $token = $this->loginAsQaAdmin();
        $groupId = $this->groupIdByName('therapist');
        $userId = $this->createQaUserInGroup('qa.member.one@selfhelp.test', $groupId, LookupService::USER_STATUS_ACTIVE);

        $members = $this->assertEnvelopeSuccessList(
            $this->jsonRequest('GET', self::BASE . '/' . $groupId . '/users', null, $token)
        );

        $mine = null;
        foreach ($members as $member) {
            // Every contract field is present on every row.
            foreach (['id', 'email', 'name', 'user_name', 'status', 'blocked'] as $field) {
                self::assertArrayHasKey($field, $member, sprintf('Member row must expose "%s"', $field));
            }
            if (($member['email'] ?? null) === 'qa.member.one@selfhelp.test') {
                $mine = $member;
            }
        }

        self::assertNotNull($mine, 'The seeded member must appear in the list');
        self::assertSame($userId, $mine['id']);
        self::assertSame('active', $mine['status'], 'status is the userStatus lookup code');
        self::assertFalse($mine['blocked']);
    }

    public function testEmptyGroupReturnsEmptyArrayNotError(): void
    {
        $token = $this->loginAsQaAdmin();
        // A fresh throwaway group with no members.
        $groupId = $this->createQaGroup('qa_members_empty');

        $envelope = $this->jsonRequest('GET', self::BASE . '/' . $groupId . '/users', null, $token);

        self::assertSame(Response::HTTP_OK, $envelope['status'] ?? null);
        self::assertSame([], $envelope['data'], 'A group with no members is [], not a 404');
    }

    public function testNonExistentGroupReturns404(): void
    {
        $envelope = $this->jsonRequest('GET', self::BASE . '/2147483600/users', null, $this->loginAsQaAdmin());

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $envelope['status'] ?? null,
            'A missing group must 404, distinct from an existing-but-empty group'
        );
    }

    /**
     * The member list must not surface users the admin cannot see on the Users
     * page. An intern user in the group must be filtered out, exactly like the
     * users list (intern = false).
     */
    public function testInternalUsersAreExcludedToMatchTheUsersList(): void
    {
        $token = $this->loginAsQaAdmin();
        $groupId = $this->groupIdByName('subject');

        $visible = $this->createQaUserInGroup('qa.member.visible@selfhelp.test', $groupId, LookupService::USER_STATUS_ACTIVE);
        $intern = $this->createQaUserInGroup('qa.member.intern@selfhelp.test', $groupId, LookupService::USER_STATUS_ACTIVE, intern: true);

        $members = $this->assertEnvelopeSuccessList(
            $this->jsonRequest('GET', self::BASE . '/' . $groupId . '/users', null, $token)
        );

        $ids = [];
        foreach ($members as $member) {
            $id = $member['id'] ?? null;
            self::assertIsInt($id);
            $ids[] = $id;
        }
        self::assertContains($visible, $ids, 'A normal member is listed');
        self::assertNotContains($intern, $ids, 'An intern user must be filtered out, matching the users list');
    }

    public function testBlockedMemberIsReportedAsBlocked(): void
    {
        $token = $this->loginAsQaAdmin();
        $groupId = $this->groupIdByName('therapist');
        $blockedId = $this->createQaUserInGroup('qa.member.blocked@selfhelp.test', $groupId, LookupService::USER_STATUS_INVITED, blocked: true);

        $members = $this->assertEnvelopeSuccessList(
            $this->jsonRequest('GET', self::BASE . '/' . $groupId . '/users', null, $token)
        );

        $blocked = null;
        foreach ($members as $member) {
            if (($member['id'] ?? null) === $blockedId) {
                $blocked = $member;
            }
        }

        self::assertNotNull($blocked);
        self::assertTrue($blocked['blocked']);
        self::assertSame('invited', $blocked['status'], 'status is reported independently of blocked');
    }

    public function testGroupMembersEnforcesAdminOnlyMatrix(): void
    {
        // groupId 1 is the seeded `admin` group; a read route, so the full matrix.
        $this->assertAdminOnlyMatrix('GET', self::BASE . '/1/users');
    }

    // -- Helpers ------------------------------------------------------------

    private function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    /**
     * Decode a success envelope whose `data` is a flat list.
     *
     * @param array<string, mixed> $envelope
     * @return list<array<string, mixed>>
     */
    private function assertEnvelopeSuccessList(array $envelope): array
    {
        self::assertSame(Response::HTTP_OK, $envelope['status'] ?? null, 'Expected a 200 success envelope');
        $data = $envelope['data'] ?? null;
        self::assertIsArray($data);

        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    private function groupIdByName(string $name): int
    {
        $group = $this->entityManager()->getRepository(Group::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Group::class, $group);

        return (int) $group->getId();
    }

    private function createQaGroup(string $name): int
    {
        $group = new Group();
        $group->setName($name);
        $group->setDescription('qa members test group');

        $this->entityManager()->persist($group);
        $this->entityManager()->flush();

        return (int) $group->getId();
    }

    /**
     * Seed a QA user and add it to a group through the production entity model
     * (Testing Rule 8: no raw SQL, real lookups + rel_groups_users).
     */
    private function createQaUserInGroup(
        string $email,
        int $groupId,
        string $statusCode,
        bool $blocked = false,
        bool $intern = false,
    ): int {
        $em = $this->entityManager();

        $status = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_STATUS,
            'lookupCode' => $statusCode,
        ]);
        $userType = $em->getRepository(Lookup::class)->findOneBy([
            'typeCode' => LookupService::USER_TYPES,
            'lookupCode' => LookupService::USER_TYPES_USER,
        ]);
        self::assertInstanceOf(Lookup::class, $status);
        self::assertInstanceOf(Lookup::class, $userType);

        $user = new User();
        $user->setEmail($email);
        $user->setName('QA ' . $email);
        $user->setUserName(str_replace(['@', '.'], '_', $email));
        $user->setStatus($status);
        $user->setUserType($userType);
        $user->setBlocked($blocked);
        $user->setIntern($intern);
        $user->setPassword(
            $this->service(UserPasswordHasherInterface::class)->hashPassword($user, QaBaselineFixture::QA_PASSWORD)
        );
        $em->persist($user);

        $group = $em->getReference(Group::class, $groupId);
        $membership = new UsersGroup();
        $membership->setUser($user);
        $membership->setGroup($group);
        $em->persist($membership);

        $em->flush();

        return (int) $user->getId();
    }
}
