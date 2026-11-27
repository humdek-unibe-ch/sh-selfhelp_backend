<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Plugin\Users\PluginUserScope;
use App\Plugin\Users\PluginUserScopeInterface;
use App\Service\CMS\Admin\AdminUserService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * C20: the users a caller manages. It is the data-access rule for groups the admin screens already
 * use (`AdminUserService::canAccessUser`), offered to plugins: the superuser, the user themself, or a
 * role that holds the bit on a group the user belongs to. Reading a group is not managing it.
 *
 * World of the tests (all `qa_p12_` / `qa.p12.`):
 *
 * | Group | Members |
 * | --- | --- |
 * | granted `G` | participants 1, 2 and 4 |
 * | other `H` | participants 3 and 4 |
 * | none | the loner |
 *
 * | Caller | Roles and grants |
 * | --- | --- |
 * | therapist | one role: update on `G` |
 * | viewer | one role: read on `G` |
 * | clerk | one role: create, read and delete on `G`, no update |
 * | split | two roles: read on `G`, and update on `G` |
 * | other | no role |
 * | admin | the seeded QA superuser |
 */
#[PhpUnitGroup('security')]
final class PluginUserScopeTest extends QaKernelTestCase
{
    private PluginUserScopeInterface $scope;
    private AdminUserService $adminUsers;
    private int $adminId;

    private int $granted;
    private int $other;

    /** @var array<string, int> the callers by name */
    private array $callers = [];

    /** @var array<string, int> the people the callers are asked about, by name */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = $this->service(PluginUserScopeInterface::class);
        $this->adminUsers = $this->service(AdminUserService::class);

        $users = new UserFactory($this->em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));
        $groups = new GroupFactory($this->em);
        $access = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );

        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->adminId = (int) $admin->getId();

        $granted = $groups->createGroup('qa_p12_scope_granted');
        $other = $groups->createGroup('qa_p12_scope_other');
        $this->granted = (int) $granted->getId();
        $this->other = (int) $other->getId();

        $therapistRole = $access->createRole('qa_p12_scope_therapist');
        $access->grantGroupAccess($therapistRole, $this->granted, DataAccessSecurityService::PERMISSION_UPDATE);
        $viewerRole = $access->createRole('qa_p12_scope_viewer');
        $access->grantGroupAccess($viewerRole, $this->granted, DataAccessSecurityService::PERMISSION_READ);
        $clerkRole = $access->createRole('qa_p12_scope_clerk');
        $access->grantGroupAccess(
            $clerkRole,
            $this->granted,
            DataAccessSecurityService::PERMISSION_CREATE | DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_DELETE,
        );
        $splitRead = $access->createRole('qa_p12_scope_split_read');
        $access->grantGroupAccess($splitRead, $this->granted, DataAccessSecurityService::PERMISSION_READ);
        $splitUpdate = $access->createRole('qa_p12_scope_split_update');
        $access->grantGroupAccess($splitUpdate, $this->granted, DataAccessSecurityService::PERMISSION_UPDATE);

        $this->callers = [
            'therapist' => (int) $users->createUser('qa.p12.scope.therapist@selfhelp.test', 'QA Therapist', roles: [$therapistRole])->getId(),
            'viewer' => (int) $users->createUser('qa.p12.scope.viewer@selfhelp.test', 'QA Viewer', roles: [$viewerRole])->getId(),
            'clerk' => (int) $users->createUser('qa.p12.scope.clerk@selfhelp.test', 'QA Clerk', roles: [$clerkRole])->getId(),
            'split' => (int) $users->createUser('qa.p12.scope.split@selfhelp.test', 'QA Split', roles: [$splitRead, $splitUpdate])->getId(),
            'other' => (int) $users->createUser('qa.p12.scope.other@selfhelp.test', 'QA Other')->getId(),
        ];

        $this->people = [
            'p1' => (int) $users->createUser('qa.p12.scope.p1@selfhelp.test', 'QA P1', [$granted])->getId(),
            'p2' => (int) $users->createUser('qa.p12.scope.p2@selfhelp.test', 'QA P2', [$granted])->getId(),
            'p3' => (int) $users->createUser('qa.p12.scope.p3@selfhelp.test', 'QA P3', [$other])->getId(),
            'p4' => (int) $users->createUser('qa.p12.scope.p4@selfhelp.test', 'QA P4', [$granted, $other])->getId(),
            'loner' => (int) $users->createUser('qa.p12.scope.loner@selfhelp.test', 'QA Loner')->getId(),
        ];
    }

    // -- mayManage ------------------------------------------------------------------------

    public function testTheSuperuserManagesEveryone(): void
    {
        foreach ($this->people as $name => $id) {
            self::assertTrue($this->scope->mayManage($this->adminId, $id), "the superuser manages $name");
        }
        self::assertTrue($this->scope->mayManage($this->adminId, 2_000_000_000), 'the superuser also answers for an id nobody has');
    }

    public function testEveryoneManagesThemselvesWithoutAnyGrant(): void
    {
        self::assertTrue($this->scope->mayManage($this->callers['other'], $this->callers['other']));
        self::assertTrue($this->scope->mayManage($this->callers['viewer'], $this->callers['viewer'], DataAccessSecurityService::PERMISSION_DELETE));
        self::assertTrue($this->scope->mayManage($this->people['loner'], $this->people['loner']));
    }

    public function testARoleWithUpdateOnAGroupManagesItsMembers(): void
    {
        $therapist = $this->callers['therapist'];

        self::assertTrue($this->scope->mayManage($therapist, $this->people['p1']));
        self::assertTrue($this->scope->mayManage($therapist, $this->people['p2']));
        self::assertTrue($this->scope->mayManage($therapist, $this->people['p4']), 'one granted group is enough, the other group does not matter');
    }

    public function testWithoutThatGrantNobodyIsManaged(): void
    {
        $therapist = $this->callers['therapist'];

        self::assertFalse($this->scope->mayManage($therapist, $this->people['p3']), 'a member of a group the caller holds nothing on');
        self::assertFalse($this->scope->mayManage($therapist, $this->people['loner']), 'a user without any group');
        self::assertFalse($this->scope->mayManage($this->callers['other'], $this->people['p1']), 'a caller without a role');
        self::assertFalse($this->scope->mayManage($therapist, 2_000_000_000), 'an id nobody has');
    }

    public function testViewingAGroupIsNotManagingItsMembers(): void
    {
        $viewer = $this->callers['viewer'];

        self::assertFalse($this->scope->mayManage($viewer, $this->people['p1']), 'read is not update');
        self::assertTrue(
            $this->scope->mayManage($viewer, $this->people['p1'], DataAccessSecurityService::PERMISSION_READ),
            'read is enough when the caller asks for the read bit',
        );
    }

    public function testTheBitDecidesWhichGrantCounts(): void
    {
        $clerk = $this->callers['clerk'];
        $p1 = $this->people['p1'];

        self::assertFalse($this->scope->mayManage($clerk, $p1), 'create, read and delete do not include update');
        self::assertTrue($this->scope->mayManage($clerk, $p1, DataAccessSecurityService::PERMISSION_CREATE));
        self::assertTrue($this->scope->mayManage($clerk, $p1, DataAccessSecurityService::PERMISSION_READ));
        self::assertTrue($this->scope->mayManage($clerk, $p1, DataAccessSecurityService::PERMISSION_DELETE));
    }

    public function testTheGrantsOfSeveralRolesAddUp(): void
    {
        $split = $this->callers['split'];

        self::assertTrue($this->scope->mayManage($split, $this->people['p1']), 'update comes from the second role');
        self::assertTrue($this->scope->mayManage($split, $this->people['p1'], DataAccessSecurityService::PERMISSION_READ), 'read from the first');
        self::assertFalse($this->scope->mayManage($split, $this->people['p3']));
    }

    public function testTheGuestAndTheSystemManageNobody(): void
    {
        self::assertFalse($this->scope->mayManage(0, $this->people['p1']));
        self::assertFalse($this->scope->mayManage(0, 0));
        self::assertFalse($this->scope->mayManage($this->adminId, 0), 'user 0 is the guest, not someone to manage');
        self::assertFalse($this->scope->mayManage(-1, $this->people['p1']));
    }

    public function testAnythingButTheFourPermissionBitsIsAProgrammingError(): void
    {
        foreach ([0, 3, 5, 16, -4] as $bit) {
            try {
                $this->scope->mayManage($this->adminId, $this->people['p1'], $bit);
                self::fail("bit $bit must be refused");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testADeniedCheckLeavesTheSameAuditRowAnAdminScreenWould(): void
    {
        $caller = $this->callers['other'];
        $before = $this->auditRows($caller);

        self::assertFalse($this->scope->mayManage($caller, $this->people['p1']));

        // One group of the user: one refusal, like AdminUserService::canAccessUser would record.
        self::assertSame($before + 1, $this->auditRows($caller));
        self::assertSame(
            1,
            self::coerceInt($this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM data_access_audits a JOIN lookups r ON r.id = a.id_permission_results'
                . ' WHERE a.id_users = :caller AND a.resource_id = :group AND r.lookup_code = :denied',
                ['caller' => $caller, 'group' => $this->granted, 'denied' => LookupService::PERMISSION_RESULTS_DENIED],
            )),
        );
    }

    /**
     * The rule is the one the admin screens use: for every caller, user and bit the answer is the same
     * as `AdminUserService::canAccessUser`. The people are never the callers: `canAccessUser` has no
     * "yourself" case, `mayManage` has one (tested above).
     *
     * `canAccessUser` walks the groups of the managed `User` entity. A user made earlier in this same test does
     * not know its groups in memory (the factory saved the memberships on their own side), a request loads
     * them from the database; so the entities are forgotten first, and the screens' method sees what a request sees.
     */
    public function testItAnswersLikeTheAdminScreensDo(): void
    {
        $this->em->clear();

        $bits = [
            DataAccessSecurityService::PERMISSION_CREATE,
            DataAccessSecurityService::PERMISSION_READ,
            DataAccessSecurityService::PERMISSION_UPDATE,
            DataAccessSecurityService::PERMISSION_DELETE,
        ];

        $checked = 0;
        foreach ($this->callers + ['admin' => $this->adminId] as $callerName => $callerId) {
            foreach ($this->people as $personName => $personId) {
                foreach ($bits as $bit) {
                    self::assertSame(
                        $this->adminUsers->canAccessUser($callerId, $personId, $bit),
                        $this->scope->mayManage($callerId, $personId, $bit),
                        "$callerName about $personName with bit $bit",
                    );
                    ++$checked;
                }
            }
        }

        self::assertSame(6 * 5 * 4, $checked);
    }

    // -- managedUserIds -------------------------------------------------------------------

    public function testManagedUserIdsAreExactlyTheMembersOfTheGrantedGroups(): void
    {
        self::assertSame(
            $this->sorted(['p1', 'p2', 'p4']),
            $this->scope->managedUserIds($this->callers['therapist']),
            'p3 and the loner are not in the granted group',
        );
        self::assertSame($this->sorted(['p1', 'p2', 'p4']), $this->scope->managedUserIds($this->callers['split']), 'the grants of both roles add up');
    }

    public function testNoUpdateGrantMeansNobodyIsListed(): void
    {
        self::assertSame([], $this->scope->managedUserIds($this->callers['viewer']), 'read is not management');
        self::assertSame([], $this->scope->managedUserIds($this->callers['clerk']), 'create, read and delete are not management');
        self::assertSame([], $this->scope->managedUserIds($this->callers['other']));
        self::assertSame([], $this->scope->managedUserIds(0), 'the guest manages nobody');
    }

    public function testManagedUserIdsCanBeNarrowedToGroups(): void
    {
        $therapist = $this->callers['therapist'];

        self::assertSame($this->sorted(['p1', 'p2', 'p4']), $this->scope->managedUserIds($therapist, [$this->granted]));
        self::assertSame([], $this->scope->managedUserIds($therapist, [$this->other]), 'a group the caller holds nothing on lists nobody, p4 included');
        self::assertSame($this->sorted(['p1', 'p2', 'p4']), $this->scope->managedUserIds($therapist, [$this->granted, $this->other]));
        self::assertSame([], $this->scope->managedUserIds($therapist, []), 'an empty list of groups is no group');
    }

    public function testTheSuperuserListsEveryUserOrEveryMemberOfTheNamedGroups(): void
    {
        $all = $this->scope->managedUserIds($this->adminId, null, PluginUserScope::MAX_LIMIT);

        foreach ($this->people as $name => $id) {
            self::assertContains($id, $all, "the superuser manages $name");
        }
        self::assertContains($this->adminId, $all);

        self::assertSame($this->sorted(['p3', 'p4']), $this->scope->managedUserIds($this->adminId, [$this->other]), 'no grant is needed');
        self::assertSame([], $this->scope->managedUserIds($this->adminId, []));
    }

    public function testManagedUserIdsAreAscendingAndCutAtTheLimit(): void
    {
        $ids = $this->scope->managedUserIds($this->callers['therapist']);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids, 'ascending');

        self::assertSame([$ids[0]], $this->scope->managedUserIds($this->callers['therapist'], null, 1), 'the lowest ids come first');
        self::assertSame(array_slice($ids, 0, 2), $this->scope->managedUserIds($this->callers['therapist'], null, 2));
    }

    public function testALimitOutsideOneToTenThousandIsAProgrammingError(): void
    {
        foreach ([0, -1, PluginUserScope::MAX_LIMIT + 1] as $limit) {
            try {
                $this->scope->managedUserIds($this->adminId, null, $limit);
                self::fail("limit $limit must be refused");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * The listing and the single question are one rule: whoever the listing names is managed, and among the people
     * of this world nobody else is (the caller themself is only listed when they are a member of a granted group).
     */
    public function testTheListingAndTheSingleQuestionAgree(): void
    {
        foreach ($this->callers + ['admin' => $this->adminId] as $callerName => $callerId) {
            $listed = $this->scope->managedUserIds($callerId, null, PluginUserScope::MAX_LIMIT);
            foreach ($this->people as $personName => $personId) {
                self::assertSame(
                    $this->scope->mayManage($callerId, $personId),
                    in_array($personId, $listed, true),
                    "$callerName about $personName",
                );
            }
        }
    }

    // -- helpers --------------------------------------------------------------------------

    /**
     * @param list<string> $names people of this world
     * @return list<int> their ids, ascending
     */
    private function sorted(array $names): array
    {
        $ids = array_map(fn (string $name): int => $this->people[$name], $names);
        sort($ids);

        return $ids;
    }

    private function auditRows(int $userId): int
    {
        return self::coerceInt($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM data_access_audits WHERE id_users = :id', ['id' => $userId]));
    }
}
