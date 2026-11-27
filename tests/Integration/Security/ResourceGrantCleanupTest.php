<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Plugin\Plugin;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\AdminAssetService;
use App\Service\CMS\Admin\AdminGroupService;
use App\Service\CMS\Admin\AdminPageService;
use App\Service\CMS\DataTableService;
use App\Service\Core\LookupService;
use App\Service\Mercure\MercureTopicResolver;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Security\ResourceGrantService;
use App\Service\Sync\ChangeNotifier;
use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * F280 (decided): `role_data_access` has no foreign key to the resource it
 * points at, so a grant on a page, group, data table, asset or plugin item
 * used to outlive it, and rule 4.6f kept counting it as something the role
 * holds. Every delete path now removes the grants of what it deletes
 * (`ResourceGrantService::revokeAll()`), then drops the cached rights of each
 * affected role (new `acl_version`, `acl` notice to the role topic, F250).
 */
#[PhpUnitGroup('security')]
final class ResourceGrantCleanupTest extends QaKernelTestCase
{
    private PageSectionFactory $pages;
    private GroupFactory $groups;
    private RoleDataAccessFactory $grants;
    private DataGridFactory $grid;
    private ChangeNotifier $notifier;
    private MercureTopicResolver $topics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $this->groups = new GroupFactory($this->em);
        $this->grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
        $this->grid = new DataGridFactory($this->em->getConnection());
        $this->notifier = $this->service(ChangeNotifier::class);
        $this->topics = $this->service(MercureTopicResolver::class);
        $this->notifier->reset();
    }

    protected function tearDown(): void
    {
        $this->notifier->reset();
        parent::tearDown();
    }

    public function testDeletingAPageRemovesItsGrantsAndTellsTheRoles(): void
    {
        $this->actAsAdmin();
        $page = $this->pages->createPage('qa_cleanup_page', false);
        $neighbour = $this->pages->createPage('qa_cleanup_page_neighbour', false);
        $role = $this->grants->createRole('qa_cleanup_page_role');
        $roleId = (int) $role->getId();
        $this->grants->grantPageAccess($role, (int) $page->getId(), DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE);
        $this->grants->grantPageAccess($role, (int) $neighbour->getId(), DataAccessSecurityService::PERMISSION_READ);
        $before = $this->aclVersion($roleId);
        $this->notifier->reset();

        $this->service(AdminPageService::class)->deletePage((int) $page->getId());

        self::assertSame(0, $this->grantCount(LookupService::RESOURCE_TYPES_PAGES, (int) $page->getId()), 'no grant is left on the deleted page');
        self::assertSame(1, $this->grantCount(LookupService::RESOURCE_TYPES_PAGES, (int) $neighbour->getId()), 'the grant on another page stays');
        $this->assertRoleWasTold($roleId, $before);
    }

    public function testDeletingAGroupRemovesItsGrantsAndTellsTheRoles(): void
    {
        $admin = $this->actAsAdmin();
        $group = $this->groups->createGroup('qa_cleanup_group');
        $neighbour = $this->groups->createGroup('qa_cleanup_group_neighbour');
        $role = $this->grants->createRole('qa_cleanup_group_role');
        $roleId = (int) $role->getId();
        $this->grants->grantGroupAccess($role, (int) $group->getId(), DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_DELETE);
        $this->grants->grantGroupAccess($role, (int) $neighbour->getId(), DataAccessSecurityService::PERMISSION_READ);
        $before = $this->aclVersion($roleId);
        $this->notifier->reset();

        $this->service(AdminGroupService::class)->deleteGroup((int) $admin->getId(), (int) $group->getId());

        self::assertSame(0, $this->grantCount(LookupService::RESOURCE_TYPES_GROUP, (int) $group->getId()), 'no grant is left on the deleted group');
        self::assertSame(1, $this->grantCount(LookupService::RESOURCE_TYPES_GROUP, (int) $neighbour->getId()), 'the grant on another group stays');
        $this->assertRoleWasTold($roleId, $before);
    }

    public function testDeletingADataTableRemovesItsGrantsAndTellsTheRoles(): void
    {
        $tableId = $this->grid->table('qa_cleanup_table', ['title' => 'text']);
        $neighbourId = $this->grid->table('qa_cleanup_table_neighbour', ['title' => 'text']);
        $role = $this->grants->createRole('qa_cleanup_table_role');
        $roleId = (int) $role->getId();
        $this->grants->grantDataTableAccess($role, $tableId, DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE);
        $this->grants->grantDataTableAccess($role, $neighbourId, DataAccessSecurityService::PERMISSION_READ);
        $before = $this->aclVersion($roleId);
        $this->notifier->reset();

        self::assertTrue($this->service(DataTableService::class)->deleteDataTable('qa_cleanup_table'));

        self::assertSame(0, $this->grantCount(LookupService::RESOURCE_TYPES_DATA_TABLE, $tableId), 'no grant is left on the deleted table');
        self::assertSame(1, $this->grantCount(LookupService::RESOURCE_TYPES_DATA_TABLE, $neighbourId), 'the grant on another table stays');
        $this->assertRoleWasTold($roleId, $before);
    }

    public function testDeletingAnAssetRemovesItsGrantsAndTellsTheRoles(): void
    {
        $this->actAsAdmin();
        $connection = $this->em->getConnection();
        $assetType = $connection->fetchOne("SELECT id FROM lookups WHERE type_code = 'assetTypes' ORDER BY id LIMIT 1");
        self::assertIsNumeric($assetType);
        $connection->insert('assets', ['uuid' => '00000000-0000-4000-8000-0000000c1ea1', 'id_asset_types' => (int) $assetType, 'name' => 'qa_cleanup_asset']);
        $assetId = (int) $connection->lastInsertId();
        $connection->insert('assets', ['uuid' => '00000000-0000-4000-8000-0000000c1ea2', 'id_asset_types' => (int) $assetType, 'name' => 'qa_cleanup_asset_neighbour']);
        $neighbourId = (int) $connection->lastInsertId();

        $role = $this->grants->createRole('qa_cleanup_asset_role');
        $roleId = (int) $role->getId();
        $this->grants->grantAssetAccess($role, $assetId, DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_DELETE);
        $this->grants->grantAssetAccess($role, $neighbourId, DataAccessSecurityService::PERMISSION_READ);
        $before = $this->aclVersion($roleId);
        $this->notifier->reset();

        $this->service(AdminAssetService::class)->deleteAsset($assetId);

        self::assertSame(0, $this->grantCount(LookupService::RESOURCE_TYPES_ASSET, $assetId), 'no grant is left on the deleted asset');
        self::assertSame(1, $this->grantCount(LookupService::RESOURCE_TYPES_ASSET, $neighbourId), 'the grant on another asset stays');
        $this->assertRoleWasTold($roleId, $before);
    }

    public function testRevokeAllWorksForAPluginTypeAndReturnsEachRoleOnce(): void
    {
        $this->installQaPlugin();
        $first = $this->grants->createRole('qa_cleanup_item_role_a');
        $second = $this->grants->createRole('qa_cleanup_item_role_b');
        $this->grants->grantResourceAccess($first, QaItemPluginFixture::TYPE_CODE, 55, DataAccessSecurityService::PERMISSION_READ);
        $this->grants->grantResourceAccess($second, QaItemPluginFixture::TYPE_CODE, 55, DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE);
        $this->grants->grantResourceAccess($first, QaItemPluginFixture::TYPE_CODE, 56, DataAccessSecurityService::PERMISSION_READ);
        $service = $this->service(ResourceGrantService::class);

        $roles = $service->revokeAll(QaItemPluginFixture::TYPE_CODE, 55);

        $expected = [(int) $first->getId(), (int) $second->getId()];
        sort($expected);
        self::assertSame($expected, $roles, 'every role that lost a grant, once, sorted');
        self::assertSame(0, $this->grantCount(QaItemPluginFixture::TYPE_CODE, 55));
        self::assertSame(1, $this->grantCount(QaItemPluginFixture::TYPE_CODE, 56), 'the grant on another item stays');
        self::assertSame([], $service->revokeAll(QaItemPluginFixture::TYPE_CODE, 55), 'nothing left to remove');
    }

    public function testRevokeAllRefusesATypeNoEnabledPluginRegistersAndAnIdThatIsNotPositive(): void
    {
        $service = $this->service(ResourceGrantService::class);

        try {
            $service->revokeAll('qa_unregistered_type', 1);
            self::fail('An unregistered type must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('qa_unregistered_type', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $service->revokeAll(LookupService::RESOURCE_TYPES_PAGES, 0);
    }

    public function testRevokeAllRefusesTheTypeOfADisabledPlugin(): void
    {
        $plugin = $this->installQaPlugin();
        $plugin->setEnabled(false);
        $this->em->flush();
        $this->service(PluginCacheInvalidator::class)->invalidatePluginSurfaceCaches();

        $this->expectException(\InvalidArgumentException::class);
        $this->service(ResourceGrantService::class)->revokeAll(QaItemPluginFixture::TYPE_CODE, 55);
    }

    private function installQaPlugin(): Plugin
    {
        return QaItemPluginFixture::install(
            $this->em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
    }

    private function actAsAdmin(): User
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));

        return $admin;
    }

    private function grantCount(string $typeCode, int $resourceId): int
    {
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM role_data_access rda JOIN lookups l ON l.id = rda.id_resource_types '
            . 'WHERE l.type_code = :type AND l.lookup_code = :code AND rda.resource_id = :id',
            ['type' => LookupService::RESOURCE_TYPES, 'code' => $typeCode, 'id' => $resourceId]
        );

        return self::coerceInt($count);
    }

    /** The role's access version: an opaque token that changes whenever its rights are dropped. */
    private function aclVersion(int $roleId): string
    {
        return self::coerceString($this->em->getConnection()->fetchOne('SELECT acl_version FROM roles WHERE id = :id', ['id' => $roleId]));
    }

    /**
     * The role's rights are dropped: a new `acl_version` and an `acl` notice on its topic.
     */
    private function assertRoleWasTold(int $roleId, string $versionBefore): void
    {
        self::assertNotSame($versionBefore, $this->aclVersion($roleId), 'the role has a new acl_version');
        $pending = $this->notifier->pending();
        self::assertContains('acl', $pending[$this->topics->syncRoleTopic($roleId)] ?? [], 'the role topic is told to refresh');
    }
}
