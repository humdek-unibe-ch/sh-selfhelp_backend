<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\Entity\Page;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\ACL\ACLService;
use App\Service\Action\ActionConfig;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\PageVersionService;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Sync\ResourceReadIndex;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\RoleFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Contract C7: where a plugin resource is used.
 */
#[PhpUnitGroup('security')]
final class AdminResourceUsageControllerTest extends QaWebTestCase
{
    private const TYPE = QaItemPluginFixture::TYPE_CODE;
    private const ITEM = 5;
    private const URI = '/cms-api/v1/admin/resources/' . self::TYPE . '/' . self::ITEM . '/usage';

    private PageSectionFactory $pages;
    private RoleFactory $roles;
    private RoleDataAccessFactory $grants;
    private UserFactory $users;
    private ActionFactory $actions;

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->service(\Doctrine\ORM\EntityManagerInterface::class);
        $this->pages = new PageSectionFactory(
            $em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
        $this->roles = new RoleFactory($em);
        $this->grants = new RoleDataAccessFactory(
            $em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
        $this->users = new UserFactory(
            $em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $this->actions = new ActionFactory($em);

        QaItemPluginFixture::install(
            $em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
        $this->service(ResourceReadIndex::class)->invalidate();
    }

    protected function tearDown(): void
    {
        $this->service(ResourceReadIndex::class)->invalidate();
        QaItemPluginFixture::forgetCaches(
            $this->service(CacheService::class),
            $this->service(PluginCacheInvalidator::class),
        );
        parent::tearDown();
    }

    public function testSuperuserSeesEveryPageAndAction(): void
    {
        $pageA = $this->itemPage('prh3_usage_a', self::ITEM, true);
        $pageB = $this->itemPage('prh3_usage_b', self::ITEM, false);
        $this->publish($pageA);
        $action = $this->itemAction('prh3_usage_action', self::ITEM);
        $this->service(ResourceReadIndex::class)->invalidate();

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $this->loginAsQaAdmin()));

        self::assertSame(0, $data['hidden_count'] ?? null);
        $pages = $data['pages'] ?? null;
        self::assertIsArray($pages);
        $keywords = [];
        $byKeyword = [];
        foreach ($pages as $row) {
            self::assertIsArray($row);
            $keyword = $row['keyword'] ?? null;
            self::assertIsString($keyword);
            $keywords[] = $keyword;
            $byKeyword[$keyword] = $row;
        }
        self::assertSame(['prh3_usage_a', 'prh3_usage_b'], $keywords);
        self::assertTrue($byKeyword['prh3_usage_a']['draft_uses'] ?? false);
        self::assertTrue($byKeyword['prh3_usage_a']['published_uses'] ?? false);
        self::assertTrue($byKeyword['prh3_usage_b']['draft_uses'] ?? false);
        self::assertFalse($byKeyword['prh3_usage_b']['published_uses'] ?? true);
        self::assertSame([['id' => (int) $action->getId(), 'name' => 'prh3_usage_action']], $data['actions'] ?? null);
    }

    public function testRoleWithViewOnItemAndReadOnOneOfTwoPagesGetsHiddenCount(): void
    {
        $pageVisible = $this->itemPage('prh3_usage_vis', self::ITEM, true);
        $pageHidden = $this->itemPage('prh3_usage_hid', self::ITEM, true);
        $this->service(ResourceReadIndex::class)->invalidate();

        $token = $this->tokenWith(
            'prh3_usage_one_page',
            [self::ITEM => DataAccessSecurityService::PERMISSION_READ],
            ['admin.access', 'admin.page.read'],
            [(int) $pageVisible->getId() => DataAccessSecurityService::PERMISSION_READ],
        );

        $data = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $token));

        $pages = $data['pages'] ?? null;
        self::assertIsArray($pages);
        self::assertCount(1, $pages);
        $first = $pages[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('prh3_usage_vis', $first['keyword'] ?? null);
        self::assertSame(1, $data['hidden_count'] ?? null, 'the second page is hidden');
        self::assertSame([], $data['actions'] ?? null);
        unset($pageHidden);
    }

    public function testActionsAreListedOnlyWithAdminActionRead(): void
    {
        $page = $this->itemPage('prh3_usage_act_page', self::ITEM, true);
        $action = $this->itemAction('prh3_usage_act_only', self::ITEM);
        $this->service(ResourceReadIndex::class)->invalidate();

        $without = $this->tokenWith(
            'prh3_usage_no_act',
            [self::ITEM => DataAccessSecurityService::PERMISSION_READ],
            ['admin.access', 'admin.page.read'],
            [(int) $page->getId() => DataAccessSecurityService::PERMISSION_READ],
        );
        $withoutData = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $without));
        $withoutPages = $withoutData['pages'] ?? null;
        self::assertIsArray($withoutPages);
        self::assertCount(1, $withoutPages);
        self::assertSame([], $withoutData['actions'] ?? null);
        self::assertSame(1, $withoutData['hidden_count'] ?? null, 'the action is hidden without admin.action.read');

        $with = $this->tokenWith(
            'prh3_usage_with_act',
            [self::ITEM => DataAccessSecurityService::PERMISSION_READ],
            ['admin.access', 'admin.page.read', 'admin.action.read'],
            [(int) $page->getId() => DataAccessSecurityService::PERMISSION_READ],
        );
        $withData = $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $with));
        $withPages = $withData['pages'] ?? null;
        self::assertIsArray($withPages);
        self::assertCount(1, $withPages);
        self::assertSame([['id' => (int) $action->getId(), 'name' => 'prh3_usage_act_only']], $withData['actions'] ?? null);
        self::assertSame(0, $withData['hidden_count'] ?? null);
    }

    public function testWithoutViewGrantAnswers403(): void
    {
        $this->itemPage('prh3_usage_deny', self::ITEM, true);
        $this->service(ResourceReadIndex::class)->invalidate();

        $token = $this->tokenWith(
            'prh3_usage_noview',
            [],
            ['admin.access'],
            [],
        );
        $this->assertEnvelope403($this->jsonRequest('GET', self::URI, null, $token));
    }

    public function testUnknownTypeAnswers404(): void
    {
        $this->assertEnvelope404($this->jsonRequest(
            'GET',
            '/cms-api/v1/admin/resources/not_a_type/1/usage',
            null,
            $this->loginAsQaAdmin(),
        ));
    }

    public function testGuestAnswers401(): void
    {
        $this->assertEnvelope401($this->jsonRequest('GET', self::URI));
    }

    public function testResponseDataValidatesAgainstItsSchema(): void
    {
        $this->itemPage('prh3_usage_schema', self::ITEM, true);
        $this->itemAction('prh3_usage_schema_act', self::ITEM);
        $this->service(ResourceReadIndex::class)->invalidate();

        $this->assertEnvelopeSuccess($this->jsonRequest('GET', self::URI, null, $this->loginAsQaAdmin()));

        // F254: the formatter cannot check a data-level schema against the envelope;
        // validate the response `data` directly.
        $raw = json_decode((string) $this->client->getResponse()->getContent());
        self::assertIsObject($raw);
        self::assertIsObject($raw->data ?? null);
        $errors = $this->service(JsonSchemaValidationService::class)->validate(
            $raw->data,
            'responses/admin/resources/usage',
        );
        self::assertSame([], $errors, "usage data must match responses/admin/resources/usage:\n" . implode("\n", $errors));
    }

    private function itemPage(string $keyword, int $itemId, bool $openAccess): Page
    {
        $page = $this->pages->createPage($keyword, $openAccess);
        $section = $this->pages->createSection($keyword . '_section', QaItemPluginFixture::STYLE);
        $this->pages->linkSectionToPage($page, $section, 10);
        $this->pages->setSectionField($section, QaItemPluginFixture::FIELD, (string) $itemId);

        return $page;
    }

    private function publish(Page $page): void
    {
        $this->actAsAdmin();
        $versions = $this->service(PageVersionService::class);
        $version = $versions->createVersion((int) $page->getId());
        $versions->publishVersion((int) $page->getId(), (int) $version->getId());
        $this->pages->invalidatePageScopedCaches();
    }

    private function itemAction(string $name, int $itemId): \App\Entity\Action
    {
        $table = $this->actions->createDataTable('qa_prh3_usage_' . $name);
        $config = [
            ActionConfig::BLOCKS => [[
                ActionConfig::JOBS => [[
                    ActionConfig::JOB_NAME => 'qa_prh3_job',
                    ActionConfig::JOB_TYPE => QaItemPluginFixture::JOB_TYPE,
                    'plugin_config' => [QaItemPluginFixture::JOB_FIELD => $itemId],
                ]],
            ]],
        ];

        return $this->actions->createAction($table, LookupService::ACTION_TRIGGER_TYPES_FINISHED, $config, $name);
    }

    /**
     * @param array<int, int> $itemGrants item id => bits
     * @param list<string> $permissions route permissions
     * @param array<int, int> $pageGrants page id => bits
     */
    private function tokenWith(string $prefix, array $itemGrants, array $permissions, array $pageGrants): string
    {
        $role = $this->roles->createRole($prefix . '_role');
        foreach ($permissions as $permission) {
            $this->roles->grantPermission($role, $permission);
        }
        foreach ($itemGrants as $itemId => $bits) {
            $this->grants->grantResourceAccess($role, self::TYPE, $itemId, $bits);
        }
        foreach ($pageGrants as $pageId => $bits) {
            $this->grants->grantPageAccess($role, $pageId, $bits);
        }
        $email = $prefix . '@selfhelp.test';
        $this->users->createUser($email, 'QA ' . $prefix, roles: [$role]);
        $this->service(DataAccessSecurityService::class)->invalidateUserPermissions(
            (int) $this->service(\Doctrine\ORM\EntityManagerInterface::class)
                ->getRepository(User::class)->findOneBy(['email' => $email])?->getId()
        );

        return $this->loginAs($email);
    }

    private function actAsAdmin(): void
    {
        $em = $this->service(\Doctrine\ORM\EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => \App\DataFixtures\Test\QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->service(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles())
        );
    }
}
