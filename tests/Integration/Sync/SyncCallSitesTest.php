<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Sync;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Service\Mercure\MercureTopicResolver;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Sync\ChangeNotifier;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\MercureTestRecorder;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * The call sites of the change notices (live-sync contract C3): the real admin
 * routes and services send the right notice to the right topic, once, and no
 * longer rewrite `users.acl_version` for every member or holder.
 * {@see SyncAudienceTest} proves who is meant to hear a notice; this proves the
 * writes actually say so.
 */
#[PhpUnitGroup('security')]
final class SyncCallSitesTest extends QaWebTestCase
{
    private EntityManagerInterface $em;
    private GroupFactory $groups;
    private RoleDataAccessFactory $roles;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->service(EntityManagerInterface::class);
        $this->groups = new GroupFactory($this->em);
        $this->roles = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testAGrantOnAPageReachesTheRoleTopicOnceAndNoHolder(): void
    {
        $holder = $this->qaUser();
        $role = $this->roles->createRole('qa_callsite_role_grant');
        $this->roles->assignRoleToUser($holder, $role);
        $page = $this->pages->createPage('qa_callsite_role_grant_page', false);
        $holderVersion = $this->userVersion($holder);
        $admin = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/data-access/roles/' . $role->getId() . '/permissions',
            ['permissions' => [[
                'resource_type_id' => (int) $this->service(LookupService::class)->getLookupIdByCode(LookupService::RESOURCE_TYPES, LookupService::RESOURCE_TYPES_PAGES),
                'resource_id' => (int) $page->getId(),
                'crud_permissions' => DataAccessSecurityService::PERMISSION_UPDATE,
            ]]],
            $admin,
        ));

        $sent = $this->sent();
        self::assertSame(['acl'], $sent[$this->topics()->syncRoleTopic((int) $role->getId())] ?? null, 'The role is told once.');
        self::assertArrayNotHasKey($this->topics()->syncUserTopic((int) $holder->getId()), $sent, 'The holder is not told by name.');
        self::assertSame($holderVersion, $this->userVersion($holder), 'No write per holder (F250).');
        self::assertNotNull($this->roleVersion((int) $role->getId()), 'The role carries its own version.');
    }

    public function testAGroupPageAclChangeReachesTheGroupTopicAndRewritesNoMember(): void
    {
        $member = $this->qaUser();
        $group = $this->groups->createGroup('qa_callsite_group_acl');
        $this->em->getConnection()->executeStatement(
            'INSERT INTO rel_groups_users (id_users, id_groups) VALUES (?, ?)',
            [$member->getId(), $group->getId()],
        );
        $page = $this->pages->createPage('qa_callsite_group_acl_page', false);
        $memberVersion = $this->userVersion($member);
        $admin = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'PUT',
            '/cms-api/v1/admin/groups/' . $group->getId() . '/acls',
            ['acls' => [['page_id' => (int) $page->getId(), 'acl_select' => true]]],
            $admin,
        ));

        $sent = $this->sent();
        self::assertSame(
            ['acl', 'navigation', 'page:' . $page->getId()],
            $sent[$this->topics()->syncGroupTopic((int) $group->getId())] ?? null,
        );
        self::assertArrayNotHasKey($this->topics()->syncUserTopic((int) $member->getId()), $sent);
        self::assertSame($memberVersion, $this->userVersion($member), 'A group change does not rewrite its members (C3).');
        self::assertNotNull($this->groupVersion((int) $group->getId()), 'The group carries its own version.');
    }

    public function testARolePermissionChangeReachesTheRoleTopicOnce(): void
    {
        $holder = $this->qaUser();
        $role = $this->roles->createRole('qa_callsite_role_permission');
        $this->roles->assignRoleToUser($holder, $role);
        $permissionId = $this->coerceInt($this->em->getConnection()->fetchOne("SELECT id FROM permissions WHERE name = 'admin.group.read'"));
        $holderVersion = $this->userVersion($holder);
        $admin = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/roles/' . $role->getId() . '/permissions',
            ['permission_ids' => [$permissionId]],
            $admin,
        ));

        $sent = $this->sent();
        self::assertSame(['acl'], $sent[$this->topics()->syncRoleTopic((int) $role->getId())] ?? null);
        self::assertArrayNotHasKey($this->topics()->syncUserTopic((int) $holder->getId()), $sent);
        self::assertSame($holderVersion, $this->userVersion($holder), 'No write per holder.');
    }

    public function testAUsersGroupChangeReachesThatUsersSyncTopicAndKeepsTheOldEvent(): void
    {
        $user = $this->qaUser();
        $group = $this->groups->createGroup('qa_callsite_user_group');
        $admin = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            '/cms-api/v1/admin/users/' . $user->getId() . '/groups',
            ['group_ids' => [(int) $group->getId()]],
            $admin,
        ));

        $sent = $this->sent();
        self::assertSame(['acl'], $sent[$this->topics()->syncUserTopic((int) $user->getId())] ?? null);
        self::assertSame(
            [$this->topics()->syncUserTopic((int) $user->getId())],
            array_keys($sent),
            'Nobody else is told about one user.',
        );
        self::assertTrue($this->aclChangedPublished((int) $user->getId()), 'The old acl-changed event is still published.');
    }

    public function testPublishingAPageReachesItsViewersAndNotOthers(): void
    {
        $page = $this->pages->createPage('qa_callsite_publish', false);
        $viewers = $this->groups->createGroup('qa_callsite_publish_viewers');
        $blind = $this->groups->createGroup('qa_callsite_publish_blind');
        $this->pages->grantGroupAcl($page, $viewers);
        $admin = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/versions/publish', (int) $page->getId()),
            ['version_name' => 'qa_callsite_v1'],
            $admin,
        ), 201);

        $sent = $this->sent();
        self::assertSame(['navigation', 'page:' . $page->getId()], $sent[$this->topics()->syncGroupTopic((int) $viewers->getId())] ?? null);
        self::assertArrayNotHasKey($this->topics()->syncGroupTopic((int) $blind->getId()), $sent, 'A group without select is not told.');
        self::assertArrayNotHasKey($this->topics()->syncPublicTopic(), $sent, 'A closed page is not public news.');
    }

    public function testPublishingAnOpenAccessPageIsPublicNews(): void
    {
        $page = $this->pages->createPage('qa_callsite_publish_open', true);
        $admin = $this->loginAsQaAdmin();

        $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/pages/%d/versions/publish', (int) $page->getId()),
            ['version_name' => 'qa_callsite_open_v1'],
            $admin,
        ), 201);

        self::assertSame(['navigation', 'page:' . $page->getId()], $this->sent()[$this->topics()->syncPublicTopic()] ?? null);
    }

    public function testASavedRecordIsQueuedForItsOwnerAfterTheService(): void
    {
        $owner = $this->qaUser();
        $dataTables = new DataTableFactory($this->em, $this->service(\App\Service\CMS\DataService::class));
        $notifier = $this->service(ChangeNotifier::class);
        $notifier->reset();

        [$table] = $dataTables->createTableWithRow('qa_callsite_record', (int) $owner->getId());

        $pending = $notifier->pending();
        self::assertSame(['table:' . $table->getId()], $pending[$this->topics()->syncUserTopic((int) $owner->getId())] ?? null);
        $notifier->reset();
    }

    // -- helpers --------------------------------------------------------------

    /**
     * What the last request sent: topic => tags of its `changed` messages.
     *
     * @return array<string, list<string>>
     */
    private function sent(): array
    {
        $out = [];
        foreach ($this->recorder()->getUpdates() as $update) {
            if ($update->getType() !== ChangeNotifier::EVENT_NAME) {
                continue;
            }
            $decoded = json_decode($update->getData(), true);
            self::assertIsArray($decoded);
            $tags = $decoded['tags'] ?? null;
            self::assertIsArray($tags);
            foreach ($update->getTopics() as $topic) {
                self::assertIsString($topic);
                self::assertArrayNotHasKey($topic, $out, 'One message per topic.');
                $out[$topic] = array_values(array_map(static fn (mixed $tag): string => is_string($tag) ? $tag : '', $tags));
            }
        }
        ksort($out);

        return $out;
    }

    private function aclChangedPublished(int $userId): bool
    {
        foreach ($this->recorder()->getUpdates() as $update) {
            if ($update->getType() !== 'acl-changed') {
                continue;
            }
            foreach ($update->getTopics() as $topic) {
                if (is_string($topic) && str_contains($topic, '/users/' . $userId . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function recorder(): MercureTestRecorder
    {
        $recorder = self::getContainer()->get(MercureTestRecorder::class);
        self::assertInstanceOf(MercureTestRecorder::class, $recorder);

        return $recorder;
    }

    private function topics(): MercureTopicResolver
    {
        return $this->service(MercureTopicResolver::class);
    }

    private function qaUser(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function userVersion(User $user): ?string
    {
        return $this->versionOf('users', (int) $user->getId());
    }

    private function roleVersion(int $id): ?string
    {
        return $this->versionOf('roles', $id);
    }

    private function groupVersion(int $id): ?string
    {
        return $this->versionOf('`groups`', $id);
    }

    private function versionOf(string $table, int $id): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT acl_version FROM ' . $table . ' WHERE id = ?', [$id]);

        return is_string($value) ? $value : null;
    }
}
