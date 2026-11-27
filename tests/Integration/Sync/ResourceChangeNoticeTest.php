<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Sync;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Page;
use App\Entity\Role;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Plugin\Resource\PluginResourceServiceInterface;
use App\Service\ACL\ACLService;
use App\Service\Action\ActionConfig;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\PageVersionService;
use App\Service\Core\LookupService;
use App\Service\Mercure\MercureTopicResolver;
use App\Service\Security\CreatorGrantService;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Sync\ChangeNoticeService;
use App\Service\Sync\ChangeNotifier;
use App\Service\Sync\ResourceReadIndex;
use App\Service\Sync\SyncTag;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * C1, C3, C5: the change notice of a plugin item (`res:<type>:<id>`) and what
 * the host does for the items of a plugin resource type.
 *
 * WHO hears about an item is an access decision, like every notice (rule 4.9):
 * roles edit what they hold a grant on, groups see published pages, and a
 * draft-side change never reaches a visitor. The audience is read from the
 * grants and from where the item is used (`ResourceReadIndex`: the section
 * fields a plugin lists in `styles[].resourceFields`).
 *
 * The QA plugin is {@see QaItemPluginFixture}: type `qa_item`, style
 * `qa-item-block` whose field `qa_item_id` holds the item id.
 */
#[PhpUnitGroup('security')]
final class ResourceChangeNoticeTest extends QaKernelTestCase
{
    private const TYPE = QaItemPluginFixture::TYPE_CODE;
    private const ITEM = 5;
    private const OTHER_ITEM = 6;

    private ChangeNoticeService $notices;
    private PluginResourceServiceInterface $resources;
    private ChangeNotifier $notifier;
    private MercureTopicResolver $topics;
    private ResourceReadIndex $index;
    private PageSectionFactory $pages;
    private GroupFactory $groups;
    private RoleFactory $roles;
    private RoleDataAccessFactory $grants;
    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notices = $this->service(ChangeNoticeService::class);
        $this->resources = $this->service(PluginResourceServiceInterface::class);
        $this->notifier = $this->service(ChangeNotifier::class);
        $this->topics = $this->service(MercureTopicResolver::class);
        $this->index = $this->service(ResourceReadIndex::class);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $this->groups = new GroupFactory($this->em);
        $this->roles = new RoleFactory($this->em);
        $this->grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );

        QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
        // The cache pool outlives the database rollback and the ids are reused: start from nothing,
        // what an earlier run cached for these items and pages must not be seen.
        $this->index->invalidate();
        $this->service(CacheService::class)->withCategory(QaItemPluginFixture::CACHE_CATEGORY)->invalidateCategory();
        $this->notifier->reset();
    }

    protected function tearDown(): void
    {
        $this->notifier->reset();
        $this->index->invalidate();
        $this->service(CacheService::class)->withCategory(CacheService::CATEGORY_PAGES)->invalidateCategory();
        QaItemPluginFixture::forgetCaches($this->service(CacheService::class), $this->service(PluginCacheInvalidator::class));
        parent::tearDown();
    }

    // -- C5: who hears about an item ---------------------------------------------

    public function testADraftSideChangeReachesTheEditorsOfTheItemAndOfPagesThatHoldItInTheirDraftOnly(): void
    {
        $holding = $this->itemPage('qa_res_draft_holding', self::ITEM, false);
        $elsewhere = $this->itemPage('qa_res_draft_elsewhere', self::OTHER_ITEM, false);
        $viewers = $this->groups->createGroup('qa_res_draft_viewers');
        $this->pages->grantGroupAcl($holding, $viewers);

        $itemRole = $this->roles->createRole('qa_res_draft_item_role');
        $this->grants->grantResourceAccess($itemRole, self::TYPE, self::ITEM, DataAccessSecurityService::PERMISSION_READ);
        $pageEditor = $this->roles->createRole('qa_res_draft_page_editor');
        $this->grants->grantPageAccess($pageEditor, (int) $holding->getId(), DataAccessSecurityService::PERMISSION_UPDATE);
        $otherPageEditor = $this->roles->createRole('qa_res_draft_other_page_editor');
        $this->grants->grantPageAccess($otherPageEditor, (int) $elsewhere->getId(), DataAccessSecurityService::PERMISSION_UPDATE);
        $otherItemRole = $this->roles->createRole('qa_res_draft_other_item_role');
        $this->grants->grantResourceAccess($otherItemRole, self::TYPE, self::OTHER_ITEM, DataAccessSecurityService::PERMISSION_READ);
        $outsider = $this->roles->createRole('qa_res_draft_outsider');

        $this->index->invalidate();
        $this->notifier->reset(); // fixtures queue notices of their own
        $this->notices->resourceChanged(self::TYPE, self::ITEM, false);

        $pending = $this->notifier->pending();
        $expected = array_merge(
            [$this->roleTopic($itemRole), $this->roleTopic($pageEditor)],
            $this->superuserTopics(),
        );
        self::assertEqualsCanonicalizing($expected, array_keys($pending));
        foreach ($pending as $tags) {
            self::assertSame([SyncTag::resource(self::TYPE, self::ITEM)], $tags);
        }

        $silent = [
            $this->topics->syncGroupTopic((int) $viewers->getId()) => 'a draft is for editors: the viewers of the page are not told',
            $this->topics->syncPublicTopic() => 'a draft-side change is never public news',
            $this->roleTopic($otherPageEditor) => 'a page that holds another item',
            $this->roleTopic($otherItemRole) => 'a role with a grant on another item',
            $this->roleTopic($outsider) => 'a role with no grant',
        ];
        foreach ($silent as $topic => $why) {
            self::assertArrayNotHasKey($topic, $pending, 'Not told: ' . $why);
        }
    }

    public function testAPublishedChangeAlsoReachesTheViewersOfPagesWhosePublishedVersionHoldsTheItem(): void
    {
        $this->actAs(QaBaselineFixture::QA_ADMIN_EMAIL);

        $closed = $this->itemPage('qa_res_pub_closed', self::ITEM, false);
        $open = $this->itemPage('qa_res_pub_open', self::ITEM, true);
        $blind = $this->itemPage('qa_res_pub_blind', self::ITEM, false);
        $draftOnly = $this->itemPage('qa_res_pub_draft_only', self::ITEM, false);
        $closedGroup = $this->groups->createGroup('qa_res_pub_closed_group');
        $blindGroup = $this->groups->createGroup('qa_res_pub_blind_group');
        $draftOnlyGroup = $this->groups->createGroup('qa_res_pub_draft_only_group');
        $this->pages->grantGroupAcl($closed, $closedGroup);
        $this->pages->grantGroupAcl($blind, $blindGroup, [], false);
        $this->pages->grantGroupAcl($draftOnly, $draftOnlyGroup);

        // The three pages are published with the item in them; the author then takes the item out
        // of the draft: the published version still holds it, and visitors are served that.
        $versions = $this->service(PageVersionService::class);
        foreach ([$closed, $open, $blind] as $page) {
            $version = $versions->createVersion((int) $page->getId());
            $versions->publishVersion((int) $page->getId(), (int) $version->getId());
            $this->em->getConnection()->executeStatement(
                'UPDATE sections_fields_translation t JOIN fields f ON f.id = t.id_fields SET t.content = \'\''
                . ' WHERE f.name = ? AND t.id_sections IN (SELECT id_sections FROM rel_pages_sections WHERE id_pages = ?)',
                [QaItemPluginFixture::FIELD, (int) $page->getId()],
            );
        }
        $itemRole = $this->roles->createRole('qa_res_pub_item_role');
        $this->grants->grantResourceAccess($itemRole, self::TYPE, self::ITEM, DataAccessSecurityService::PERMISSION_READ);
        $draftEditor = $this->roles->createRole('qa_res_pub_draft_editor');
        $this->grants->grantPageAccess($draftEditor, (int) $draftOnly->getId(), DataAccessSecurityService::PERMISSION_UPDATE);

        $this->index->invalidate();
        $this->notifier->reset();
        $this->notices->resourceChanged(self::TYPE, self::ITEM, false);
        $draftSide = $this->notifier->pending();

        self::assertArrayNotHasKey($this->topics->syncGroupTopic((int) $closedGroup->getId()), $draftSide, 'a draft-side change reaches no viewer');
        self::assertArrayNotHasKey($this->topics->syncPublicTopic(), $draftSide, 'a draft-side change reaches no visitor');
        self::assertArrayHasKey($this->roleTopic($draftEditor), $draftSide, 'the editors of the page that holds it in the draft still hear it');

        $this->notifier->reset();
        $this->notices->resourceChanged(self::TYPE, self::ITEM, true);
        $published = $this->notifier->pending();

        $expected = array_merge(
            [
                $this->roleTopic($itemRole),
                $this->roleTopic($draftEditor),
                $this->topics->syncGroupTopic((int) $closedGroup->getId()),
                $this->topics->syncPublicTopic(),
            ],
            $this->superuserTopics(),
        );
        self::assertEqualsCanonicalizing($expected, array_keys($published));
        foreach ($published as $tags) {
            self::assertSame([SyncTag::resource(self::TYPE, self::ITEM)], $tags);
        }
        self::assertArrayNotHasKey($this->topics->syncGroupTopic((int) $blindGroup->getId()), $published, 'a group without `select` does not see the page');
        self::assertArrayNotHasKey($this->topics->syncGroupTopic((int) $draftOnlyGroup->getId()), $published, 'a page that was never published shows nobody the item');
    }

    public function testAnItemNoPageHoldsReachesOnlyItsEditors(): void
    {
        $role = $this->roles->createRole('qa_res_alone_role');
        $this->grants->grantResourceAccess($role, self::TYPE, self::ITEM, DataAccessSecurityService::PERMISSION_UPDATE);

        $this->notifier->reset();
        $this->notices->resourceChanged(self::TYPE, self::ITEM, true);

        self::assertEqualsCanonicalizing(
            array_merge([$this->roleTopic($role)], $this->superuserTopics()),
            array_keys($this->notifier->pending()),
        );
    }

    public function testTheIndexFindsTheActionsWhoseJobConfigHoldsTheItem(): void
    {
        $actions = new ActionFactory($this->em);
        $table = $actions->createDataTable('qa_res_action_table');
        $trigger = LookupService::ACTION_TRIGGER_TYPES_FINISHED;
        $holding = $actions->createAction($table, $trigger, $this->jobConfig(QaItemPluginFixture::JOB_TYPE, self::ITEM), 'qa_res_action_holding');
        $other = $actions->createAction($table, $trigger, $this->jobConfig(QaItemPluginFixture::JOB_TYPE, self::OTHER_ITEM), 'qa_res_action_other');
        // The same property in a job of a type no enabled plugin lists, and a core job: neither holds an item.
        $actions->createAction($table, $trigger, $this->jobConfig('qa_unlisted_job', self::ITEM), 'qa_res_action_unlisted');
        $actions->createAction($table, $trigger, ActionFactory::immediateEmailConfig('qa subject', 'qa body'), 'qa_res_action_core');
        $this->index->invalidate();

        self::assertSame([(int) $holding->getId()], $this->index->actionsOf(self::TYPE, self::ITEM));
        self::assertSame([(int) $other->getId()], $this->index->actionsOf(self::TYPE, self::OTHER_ITEM));
        self::assertSame([], $this->index->actionsOf(self::TYPE, 99), 'an item no action holds');
        self::assertSame([], $this->index->draftPagesOf(self::TYPE, self::ITEM), 'an action is not a page');
        self::assertSame([], $this->index->publishedPagesOf(self::TYPE, self::ITEM), 'an action is not a page');
    }

    public function testATypeNoEnabledPluginRegistersIsRefused(): void
    {
        try {
            $this->notices->resourceChanged('qa_unregistered_type', self::ITEM, true);
            self::fail('An unregistered type must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('qa_unregistered_type', $e->getMessage());
        }
        self::assertSame([], $this->notifier->pending(), 'nothing is sent for a refused call');
    }

    public function testATagIsBuiltOnlyFromAWellFormedTypeAndAPositiveId(): void
    {
        self::assertSame('res:qa_item:5', SyncTag::resource('qa_item', 5));

        foreach ([['Bad Type', 5], ['qa_item', 0], ['', 5], ['9type', 5]] as [$type, $id]) {
            try {
                SyncTag::resource($type, $id);
                self::fail(sprintf('"%s:%d" must be refused.', $type, $id));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheNoticeNamesNoContent(): void
    {
        $role = $this->roles->createRole('qa_res_content_role');
        $this->grants->grantResourceAccess($role, self::TYPE, self::ITEM, DataAccessSecurityService::PERMISSION_READ);
        $this->notifier->reset();

        $this->notices->resourceChanged(self::TYPE, self::ITEM, true);

        foreach ($this->notifier->pending() as $tags) {
            foreach ($tags as $tag) {
                self::assertMatchesRegularExpression('/^res:[a-z][a-z0-9_]{1,63}:\d+$/', $tag);
            }
        }
    }

    // -- C3: what the host does for an item ------------------------------------------

    public function testDeletedLeavesNoGrantInvalidatesEachRoleAndQueuesAPublishedNotice(): void
    {
        $this->actAs(QaBaselineFixture::QA_ADMIN_EMAIL);
        $open = $this->itemPage('qa_res_deleted_open', self::ITEM, true);
        $versions = $this->service(PageVersionService::class);
        $version = $versions->createVersion((int) $open->getId());
        $versions->publishVersion((int) $open->getId(), (int) $version->getId());

        $first = $this->roles->createRole('qa_res_deleted_first');
        $second = $this->roles->createRole('qa_res_deleted_second');
        $bystander = $this->roles->createRole('qa_res_deleted_bystander');
        $this->grants->grantResourceAccess($first, self::TYPE, self::ITEM, DataAccessSecurityService::PERMISSION_READ);
        $this->grants->grantResourceAccess($second, self::TYPE, self::ITEM, DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE);
        $this->grants->grantResourceAccess($first, self::TYPE, self::OTHER_ITEM, DataAccessSecurityService::PERMISSION_READ);
        $this->grants->grantResourceAccess($bystander, self::TYPE, self::OTHER_ITEM, DataAccessSecurityService::PERMISSION_READ);
        $versionsBefore = [
            'first' => $this->aclVersion($first),
            'second' => $this->aclVersion($second),
            'bystander' => $this->aclVersion($bystander),
        ];
        $this->index->invalidate();
        $this->notifier->reset();

        $this->resources->deleted(self::TYPE, self::ITEM);

        self::assertSame(0, $this->grantCount(self::ITEM), 'no grant is left on the deleted item');
        self::assertSame(2, $this->grantCount(self::OTHER_ITEM), 'the grants on another item stay');
        self::assertNotSame($versionsBefore['first'], $this->aclVersion($first), 'each role that lost a grant has a new acl_version');
        self::assertNotSame($versionsBefore['second'], $this->aclVersion($second));
        self::assertSame($versionsBefore['bystander'], $this->aclVersion($bystander), 'a role that lost nothing is left alone');

        $pending = $this->notifier->pending();
        $itemTag = SyncTag::resource(self::TYPE, self::ITEM);
        foreach ([$first, $second] as $role) {
            self::assertSame(['acl', $itemTag], $pending[$this->roleTopic($role)] ?? null, 'the roles that held a grant learn of the item and refresh their rights');
        }
        self::assertContains($itemTag, $pending[$this->topics->syncPublicTopic()] ?? [], 'a deletion is a published change: open pages drop the item');
        self::assertArrayNotHasKey($this->roleTopic($bystander), $pending);
    }

    public function testChangedClearsTheItemsCacheScopeAndSendsTheNotice(): void
    {
        $role = $this->roles->createRole('qa_res_changed_role');
        $this->grants->grantResourceAccess($role, self::TYPE, self::ITEM, DataAccessSecurityService::PERMISSION_READ);
        $loads = [self::ITEM => 0, self::OTHER_ITEM => 0];
        $read = function (int $id) use (&$loads): void {
            $this->service(CacheService::class)
                ->withCategory(QaItemPluginFixture::CACHE_CATEGORY)
                ->withResourceScope(self::TYPE, $id)
                ->getItem('qa_probe_changed', static function () use (&$loads, $id): string {
                    $loads[$id]++;

                    return 'definition';
                });
        };
        $read(self::ITEM);
        $read(self::OTHER_ITEM);
        $read(self::ITEM);
        self::assertSame([self::ITEM => 1, self::OTHER_ITEM => 1], $loads, 'both are cached');
        $this->notifier->reset();

        $this->resources->changed(self::TYPE, self::ITEM, false);

        $read(self::ITEM);
        $read(self::OTHER_ITEM);
        self::assertSame([self::ITEM => 2, self::OTHER_ITEM => 1], $loads, 'the item that changed is rebuilt, the other stays warm');
        self::assertSame([SyncTag::resource(self::TYPE, self::ITEM)], $this->notifier->pending()[$this->roleTopic($role)] ?? null);
    }

    public function testEveryMethodRefusesATypeNoEnabledPluginRegisters(): void
    {
        $calls = [
            fn () => $this->resources->changed('qa_unregistered_type', 1, true),
            fn () => $this->resources->deleted('qa_unregistered_type', 1),
            fn () => $this->resources->grantCreator('qa_plugin.items.create', 'qa_unregistered_type', 1),
            fn () => $this->resources->rightsFor('qa_unregistered_type', [1], 1),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('An unregistered type must be refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('qa_unregistered_type', $e->getMessage());
            }
        }
    }

    public function testRightsForReadsTheGrantBitsOfTheUsersRoles(): void
    {
        $viewOnly = $this->userWithGrant('qa_res_rights_view', DataAccessSecurityService::PERMISSION_READ);
        $viewUpdate = $this->userWithGrant('qa_res_rights_update', DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE);
        $all = $this->userWithGrant('qa_res_rights_all', DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE | DataAccessSecurityService::PERMISSION_DELETE);
        $none = $this->users->createUser('qa_res_rights_none@selfhelp.test', 'QA no grant');
        $admin = $this->user(QaBaselineFixture::QA_ADMIN_EMAIL);

        $view = ['can_view' => true, 'can_update' => false, 'can_delete' => false];
        $update = ['can_view' => true, 'can_update' => true, 'can_delete' => false];
        $everything = ['can_view' => true, 'can_update' => true, 'can_delete' => true];
        $nothing = ['can_view' => false, 'can_update' => false, 'can_delete' => false];

        self::assertSame([self::ITEM => $view], $this->resources->rightsFor(self::TYPE, [self::ITEM], (int) $viewOnly->getId()));
        self::assertSame([self::ITEM => $update], $this->resources->rightsFor(self::TYPE, [self::ITEM], (int) $viewUpdate->getId()));
        self::assertSame([self::ITEM => $everything], $this->resources->rightsFor(self::TYPE, [self::ITEM], (int) $all->getId()));
        self::assertSame([self::ITEM => $nothing], $this->resources->rightsFor(self::TYPE, [self::ITEM], (int) $none->getId()));
        self::assertSame([self::ITEM => $everything], $this->resources->rightsFor(self::TYPE, [self::ITEM], (int) $admin->getId()), 'the superuser may do everything');

        $many = $this->resources->rightsFor(self::TYPE, [self::OTHER_ITEM, self::ITEM, self::ITEM, 0, -3], (int) $viewUpdate->getId());
        self::assertSame([self::OTHER_ITEM, self::ITEM], array_keys($many), 'one entry per distinct positive id, in the order asked');
        self::assertSame($nothing, $many[self::OTHER_ITEM], 'no grant on the other item');
        self::assertSame([], $this->resources->rightsFor(self::TYPE, [], (int) $viewUpdate->getId()));
    }

    public function testGrantCreatorGivesFullAccessToTheCallersRolesThatHoldTheCreatePermission(): void
    {
        $creatorRole = $this->roles->createRole('qa_res_creator_role');
        $this->roles->grantPermission($creatorRole, QaItemPluginFixture::PERMISSION_CREATE);
        $readerRole = $this->roles->createRole('qa_res_creator_reader_role');
        $this->roles->grantPermission($readerRole, QaItemPluginFixture::PERMISSION_READ);
        $email = 'qa_res_creator@selfhelp.test';
        $this->users->createUser($email, 'QA creator', roles: [$creatorRole, $readerRole]);

        $this->resources->grantCreator(QaItemPluginFixture::PERMISSION_CREATE, self::TYPE, 77);
        self::assertSame(0, $this->grantCount(77), 'no one is signed in: nothing happens');

        $this->actAs($email);
        $this->resources->grantCreator(QaItemPluginFixture::PERMISSION_CREATE, self::TYPE, 77);

        self::assertSame(CreatorGrantService::FULL_ACCESS, $this->grantBits($creatorRole, 77), 'the role that may create gets the item in full');
        self::assertNull($this->grantBits($readerRole, 77), 'a role without the create permission gets nothing');
    }

    // -- helpers -----------------------------------------------------------------------

    /**
     * A page with one `qa-item-block` section whose draft holds the item.
     */
    private function itemPage(string $keyword, int $itemId, bool $openAccess): Page
    {
        $page = $this->pages->createPage($keyword, $openAccess);
        $section = $this->pages->createSection($keyword . '_section', QaItemPluginFixture::STYLE);
        $this->pages->linkSectionToPage($page, $section, 10);
        $this->pages->setSectionField($section, QaItemPluginFixture::FIELD, (string) $itemId);

        return $page;
    }

    /**
     * An action config with one job of the given type whose plugin config holds the item (C12).
     *
     * @return array<string, mixed>
     */
    private function jobConfig(string $jobType, int $itemId): array
    {
        return [
            ActionConfig::BLOCKS => [[
                ActionConfig::JOBS => [[
                    ActionConfig::JOB_NAME => 'qa_res_job',
                    ActionConfig::JOB_TYPE => $jobType,
                    'plugin_config' => [QaItemPluginFixture::JOB_FIELD => $itemId],
                ]],
            ]],
        ];
    }

    private function userWithGrant(string $prefix, int $bits): User
    {
        $role = $this->roles->createRole($prefix . '_role');
        $user = $this->users->createUser($prefix . '@selfhelp.test', 'QA ' . $prefix, roles: [$role]);
        $this->grants->grantResourceAccess($role, self::TYPE, self::ITEM, $bits);
        $this->service(DataAccessSecurityService::class)->invalidateUserPermissions((int) $user->getId());

        return $user;
    }

    private function user(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function actAs(string $email): void
    {
        $user = $this->user($email);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function roleTopic(Role $role): string
    {
        return $this->topics->syncRoleTopic((int) $role->getId());
    }

    /**
     * @return list<string>
     */
    private function superuserTopics(): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM roles WHERE is_superuser = 1');
        self::assertNotSame([], $ids, 'The baseline has a superuser role.');

        return array_map(fn (mixed $id): string => $this->topics->syncRoleTopic(self::coerceInt($id)), $ids);
    }

    private function grantCount(int $resourceId): int
    {
        return self::coerceInt($this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM role_data_access rda JOIN lookups l ON l.id = rda.id_resource_types'
            . ' WHERE l.type_code = :type AND l.lookup_code = :code AND rda.resource_id = :id',
            ['type' => LookupService::RESOURCE_TYPES, 'code' => self::TYPE, 'id' => $resourceId],
        ));
    }

    private function grantBits(Role $role, int $resourceId): ?int
    {
        $bits = $this->em->getConnection()->fetchOne(
            'SELECT rda.crud_permissions FROM role_data_access rda JOIN lookups l ON l.id = rda.id_resource_types'
            . ' WHERE l.type_code = :type AND l.lookup_code = :code AND rda.resource_id = :id AND rda.id_roles = :role',
            ['type' => LookupService::RESOURCE_TYPES, 'code' => self::TYPE, 'id' => $resourceId, 'role' => (int) $role->getId()],
        );

        return $bits === false ? null : self::coerceInt($bits);
    }

    /** The role's access version: an opaque token that changes whenever its rights are dropped. */
    private function aclVersion(Role $role): string
    {
        return self::coerceString($this->em->getConnection()->fetchOne('SELECT acl_version FROM roles WHERE id = :id', ['id' => (int) $role->getId()]));
    }
}
