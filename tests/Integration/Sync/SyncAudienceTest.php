<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Sync;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Group;
use App\Entity\Page;
use App\Entity\Role;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\PageVersionService;
use App\Service\Core\LookupService;
use App\Service\Mercure\MercureTopicResolver;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Sync\ChangeNoticeService;
use App\Service\Sync\ChangeNotifier;
use App\Service\Sync\PageTableReadIndex;
use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Who learns about a change (live-sync contract C3). A notice carries tags, never
 * content, but WHO gets it is an access decision: a group sees pages it has
 * `select` on, roles edit what they hold a grant on, an owner hears about their
 * own records, and nobody hears about something they could not read. Each case
 * checks the topics of the merged notices a change queues, and that no other
 * topic is among them.
 */
#[PhpUnitGroup('security')]
final class SyncAudienceTest extends QaKernelTestCase
{
    private ChangeNoticeService $notices;
    private ChangeNotifier $notifier;
    private MercureTopicResolver $topics;
    private PageSectionFactory $pages;
    private DataGridFactory $grid;
    private GroupFactory $groups;
    private RoleDataAccessFactory $roles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notices = $this->service(ChangeNoticeService::class);
        $this->notifier = $this->service(ChangeNotifier::class);
        $this->topics = $this->service(MercureTopicResolver::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $this->grid = new DataGridFactory($this->em->getConnection());
        $this->groups = new GroupFactory($this->em);
        $this->roles = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );

        // The index lives in the cache, which outlives the database rollback and reuses ids.
        $this->service(PageTableReadIndex::class)->invalidate();
        $this->notifier->reset();
    }

    protected function tearDown(): void
    {
        $this->notifier->reset();
        $this->service(PageTableReadIndex::class)->invalidate();
        parent::tearDown();
    }

    public function testAUsersOwnChangeGoesToThatUserOnly(): void
    {
        $this->notices->userAclChanged(4242);

        self::assertSame([$this->topics->syncUserTopic(4242) => ['acl']], $this->notifier->pending());
    }

    public function testAGroupPageAclChangeGoesToThatGroupOnly(): void
    {
        $group = $this->groups->createGroup('qa_sync_group_acl');
        $other = $this->groups->createGroup('qa_sync_group_other');
        $page = $this->pages->createPage('qa_sync_group_acl_page', false);

        $this->notifier->reset(); // fixtures queue notices of their own
        $this->notices->groupAclChanged((int) $group->getId(), [(int) $page->getId()]);

        $pending = $this->notifier->pending();
        self::assertSame([$this->topics->syncGroupTopic((int) $group->getId())], array_keys($pending));
        self::assertSame(['acl', 'navigation', 'page:' . $page->getId()], $pending[$this->topics->syncGroupTopic((int) $group->getId())]);
        self::assertArrayNotHasKey($this->topics->syncGroupTopic((int) $other->getId()), $pending);
    }

    public function testARoleChangeGoesToThatRoleOnly(): void
    {
        $role = $this->roles->createRole('qa_sync_role_acl');
        $other = $this->roles->createRole('qa_sync_role_other');

        $this->notifier->reset(); // fixtures queue notices of their own
        $this->notices->roleAclChanged((int) $role->getId());

        self::assertSame([$this->topics->syncRoleTopic((int) $role->getId()) => ['acl']], $this->notifier->pending());
        self::assertArrayNotHasKey($this->topics->syncRoleTopic((int) $other->getId()), $this->notifier->pending());
    }

    public function testAPublishedPageReachesItsViewersAndItsEditorsAndNobodyElse(): void
    {
        $page = $this->pages->createPage('qa_sync_publish', true);
        $pageId = (int) $page->getId();
        $viewers = $this->groups->createGroup('qa_sync_viewers');
        $blind = $this->groups->createGroup('qa_sync_blind');
        $this->pages->grantGroupAcl($page, $viewers);
        $this->pages->grantGroupAcl($page, $blind, [], false);
        $editor = $this->roles->createRole('qa_sync_editor');
        $outsider = $this->roles->createRole('qa_sync_outsider');
        $this->roles->grantPageAccess($editor, $pageId, DataAccessSecurityService::PERMISSION_UPDATE);
        $otherPage = $this->pages->createPage('qa_sync_publish_other', false);
        $this->roles->grantPageAccess($outsider, (int) $otherPage->getId(), DataAccessSecurityService::PERMISSION_UPDATE);

        $this->notifier->reset(); // fixtures queue notices of their own
        $this->notices->pagePublishedChanged($pageId);

        $expected = array_merge(
            [$this->topics->syncGroupTopic((int) $viewers->getId()), $this->topics->syncPublicTopic(), $this->topics->syncRoleTopic((int) $editor->getId())],
            $this->superuserTopics(),
        );
        $pending = $this->notifier->pending();
        self::assertEqualsCanonicalizing($expected, array_keys($pending));
        foreach ($pending as $tags) {
            self::assertSame(['navigation', 'page:' . $pageId], $tags);
        }
    }

    public function testAClosedPageIsNotAnnouncedToThePublic(): void
    {
        $page = $this->pages->createPage('qa_sync_closed', false);

        $this->notifier->reset(); // fixtures queue notices of their own
        $this->notices->pagePublishedChanged((int) $page->getId());

        self::assertNotContains($this->topics->syncPublicTopic(), array_keys($this->notifier->pending()), 'A page that is not open access is never announced on the public topic.');
    }

    public function testADraftWriteReachesOnlyTheEditors(): void
    {
        $page = $this->pages->createPage('qa_sync_draft', true);
        $pageId = (int) $page->getId();
        $viewers = $this->groups->createGroup('qa_sync_draft_viewers');
        $this->pages->grantGroupAcl($page, $viewers);
        $editor = $this->roles->createRole('qa_sync_draft_editor');
        $this->roles->grantPageAccess($editor, $pageId, DataAccessSecurityService::PERMISSION_UPDATE);

        $this->notifier->reset(); // fixtures queue notices of their own
        $this->notices->pageDraftChanged($pageId);

        $pending = $this->notifier->pending();
        self::assertEqualsCanonicalizing(
            array_merge([$this->topics->syncRoleTopic((int) $editor->getId())], $this->superuserTopics()),
            array_keys($pending),
            'A draft is for editors: no group and no public topic hears about it.',
        );
        foreach ($pending as $tags) {
            self::assertSame(['page_draft:' . $pageId], $tags);
        }
    }

    public function testASectionSaveReachesTheEditorsOfEachPageThatHoldsItWithItsOwnDraftTag(): void
    {
        $pageA = $this->pages->createPage('qa_sync_section_a', false);
        $pageB = $this->pages->createPage('qa_sync_section_b', false);
        $editorA = $this->roles->createRole('qa_sync_section_editor_a');
        $editorB = $this->roles->createRole('qa_sync_section_editor_b');
        $this->roles->grantPageAccess($editorA, (int) $pageA->getId(), DataAccessSecurityService::PERMISSION_UPDATE);
        $this->roles->grantPageAccess($editorB, (int) $pageB->getId(), DataAccessSecurityService::PERMISSION_UPDATE);

        $this->notifier->reset(); // fixtures queue notices of their own
        $this->notices->sectionSaved(77, [(int) $pageA->getId(), (int) $pageB->getId()]);

        $pending = $this->notifier->pending();
        self::assertSame(['page_draft:' . $pageA->getId(), 'section:77'], $pending[$this->topics->syncRoleTopic((int) $editorA->getId())]);
        self::assertSame(['page_draft:' . $pageB->getId(), 'section:77'], $pending[$this->topics->syncRoleTopic((int) $editorB->getId())]);
        foreach ($pending as $topic => $tags) {
            self::assertNotContains($topic, [$this->topics->syncPublicTopic()], 'Sections of a draft are not public news.');
        }
    }

    public function testATableChangeReachesTheOwnerTheEditorsAndTheViewersOfPagesThatReadItForEveryone(): void
    {
        $owner = $this->user(QaBaselineFixture::QA_USER_EMAIL);
        $stranger = $this->user(QaBaselineFixture::QA_EDITOR_EMAIL);
        $tableId = $this->grid->table('qa_sync_table', ['title' => 'text']);

        $shared = $this->readerPage('qa_sync_reader_all', $tableId, null, true);
        $closed = $this->readerPage('qa_sync_reader_closed', $tableId, null, false);
        $ownOnly = $this->readerPage('qa_sync_reader_own', $tableId, '1', true);
        $unrelated = $this->readerPage('qa_sync_reader_unrelated', $this->grid->table('qa_sync_table_other', ['title' => 'text']), null, true);

        $sharedGroup = $this->groups->createGroup('qa_sync_table_shared_group');
        $closedGroup = $this->groups->createGroup('qa_sync_table_closed_group');
        $ownGroup = $this->groups->createGroup('qa_sync_table_own_group');
        $unrelatedGroup = $this->groups->createGroup('qa_sync_table_unrelated_group');
        $this->pages->grantGroupAcl($shared, $sharedGroup);
        $this->pages->grantGroupAcl($closed, $closedGroup);
        $this->pages->grantGroupAcl($ownOnly, $ownGroup);
        $this->pages->grantGroupAcl($unrelated, $unrelatedGroup);

        $editor = $this->roles->createRole('qa_sync_table_editor');
        $this->roles->grantDataTableAccess($editor, $tableId, DataAccessSecurityService::PERMISSION_UPDATE);
        $outsider = $this->roles->createRole('qa_sync_table_outsider');

        $this->service(PageTableReadIndex::class)->invalidate();
        $this->notifier->reset();
        $this->notices->tableChanged($tableId, (int) $owner->getId());

        $pending = $this->notifier->pending();
        $expected = array_merge(
            [
                $this->topics->syncUserTopic((int) $owner->getId()),
                $this->topics->syncRoleTopic((int) $editor->getId()),
                $this->topics->syncGroupTopic((int) $sharedGroup->getId()),
                $this->topics->syncGroupTopic((int) $closedGroup->getId()),
                $this->topics->syncPublicTopic(),
            ],
            $this->superuserTopics(),
        );
        self::assertEqualsCanonicalizing($expected, array_keys($pending));
        foreach ($pending as $tags) {
            self::assertSame(['table:' . $tableId], $tags);
        }

        // The things that must NOT be told.
        $silent = [
            $this->topics->syncUserTopic((int) $stranger->getId()) => 'another user: it is not their record',
            $this->topics->syncGroupTopic((int) $ownGroup->getId()) => 'a page that shows only the visitor\'s own records',
            $this->topics->syncGroupTopic((int) $unrelatedGroup->getId()) => 'a page that reads another table',
            $this->topics->syncRoleTopic((int) $outsider->getId()) => 'a role with no grant on the table',
        ];
        foreach ($silent as $topic => $why) {
            self::assertArrayNotHasKey($topic, $pending, 'Not told: ' . $why);
        }
    }

    public function testAPublishedReaderIsFoundThroughItsPublishedVersion(): void
    {
        $this->actAsAdmin();
        $tableId = $this->grid->table('qa_sync_published_table', ['title' => 'text']);
        $page = $this->readerPage('qa_sync_published_reader', $tableId, null, false);
        $group = $this->groups->createGroup('qa_sync_published_group');
        $this->pages->grantGroupAcl($page, $group);

        $versions = $this->service(PageVersionService::class);
        $version = $versions->createVersion((int) $page->getId());
        $versions->publishVersion((int) $page->getId(), (int) $version->getId());
        // The author now removes the table from the draft: the published page still reads it.
        $this->em->getConnection()->executeStatement(
            "UPDATE sections_fields_translation t JOIN fields f ON f.id = t.id_fields SET t.content = '' WHERE f.name = 'data_table' AND t.id_sections IN (SELECT id_sections FROM rel_pages_sections WHERE id_pages = ?)",
            [(int) $page->getId()],
        );
        $this->service(PageTableReadIndex::class)->invalidate();
        $this->notifier->reset();

        $this->notices->tableChanged($tableId, null);

        self::assertArrayHasKey($this->topics->syncGroupTopic((int) $group->getId()), $this->notifier->pending(), 'The visitor is served the published version, so it decides who reads the table.');
    }

    public function testNavigationAndGlobalValuesGoToThePublicTopicOnly(): void
    {
        $this->notices->navigationChanged();
        $this->notices->globalValuesChanged();

        self::assertSame([$this->topics->syncPublicTopic() => ['global_values', 'navigation']], $this->notifier->pending());
    }

    public function testAnAssetChangeReachesRolesWithAGrantOnThatAssetOnly(): void
    {
        $manager = $this->roles->createRole('qa_sync_asset_manager');
        $other = $this->roles->createRole('qa_sync_asset_other');
        $this->roles->grantAssetAccess($manager, 901, DataAccessSecurityService::PERMISSION_UPDATE);
        $this->roles->grantAssetAccess($other, 902, DataAccessSecurityService::PERMISSION_UPDATE);

        $this->notifier->reset(); // fixtures queue notices of their own
        $this->notices->assetChanged(901);

        $pending = $this->notifier->pending();
        self::assertEqualsCanonicalizing(
            array_merge([$this->topics->syncRoleTopic((int) $manager->getId())], $this->superuserTopics()),
            array_keys($pending),
        );
        foreach ($pending as $tags) {
            self::assertSame(['asset:901'], $tags);
        }
    }

    public function testNoNoticeNamesAnyContent(): void
    {
        $table = $this->grid->table('qa_sync_content_table', ['title' => 'text']);
        $this->grid->row($table, null, ['title' => 'a private value']);
        $this->notices->tableChanged($table, null);
        $this->notices->navigationChanged();

        foreach ($this->notifier->pending() as $tags) {
            foreach ($tags as $tag) {
                self::assertMatchesRegularExpression('/^(acl|navigation|global_values|(page|page_draft|page_lock|table|section|asset):\d+)$/', $tag);
            }
        }
    }

    /**
     * A page with a data view that reads the table, drawn for everyone unless `own` is set.
     */
    private function readerPage(string $keyword, int $tableId, ?string $own, bool $openAccess): Page
    {
        $page = $this->pages->createPage($keyword, $openAccess);
        $view = $this->pages->createSection($keyword . '_view', 'data-view');
        $this->pages->linkSectionToPage($page, $view, 10);
        $this->pages->setSectionField($view, 'data_table', (string) $tableId);
        if ($own !== null) {
            $this->pages->setSectionField($view, 'own_entries_only', $own);
        }

        return $page;
    }

    /**
     * @return list<string>
     */
    private function superuserTopics(): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM roles WHERE is_superuser = 1');
        self::assertNotSame([], $ids, 'The baseline has a superuser role.');

        return array_map(fn (mixed $id): string => $this->topics->syncRoleTopic($this->asInt($id)), $ids);
    }

    private function user(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function actAsAdmin(): void
    {
        $admin = $this->user(QaBaselineFixture::QA_ADMIN_EMAIL);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }
}
