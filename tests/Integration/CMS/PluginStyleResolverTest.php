<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\Page;
use App\Entity\User;
use App\Plugin\Cache\PluginCacheInvalidator;
use App\Plugin\Lifecycle\PluginCatalogueSynchronizer;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\Admin\PageVersionService;
use App\Service\Core\LookupService;
use App\Service\Sync\ResourceReadIndex;
use App\Service\Sync\SyncTag;
use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\Plugin\QaItemPluginFixture;
use App\Tests\Support\Plugin\QaItemStyleResolver;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Contract C6: plugin style resolvers run after the page cache; depends_on
 * gains `res:` tags from resourceFields and filtered resolver tags.
 */
#[PhpUnitGroup('security')]
final class PluginStyleResolverTest extends QaWebTestCase
{
    private const LANGUAGE_ID = 2;
    private const ITEM = 5;
    private const OTHER_ITEM = 9;

    private PageSectionFactory $pages;
    private DataGridFactory $grid;

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
        $this->grid = new DataGridFactory($em->getConnection());

        QaItemStyleResolver::reset();
        QaItemPluginFixture::install(
            $em,
            $this->service(PluginCatalogueSynchronizer::class),
            $this->service(PluginCacheInvalidator::class),
        );
        $this->service(ResourceReadIndex::class)->invalidate();
        $this->service(CacheService::class)->withCategory(CacheService::CATEGORY_PAGES)->invalidateCategory();
        $this->service(CacheService::class)->withCategory(CacheService::CATEGORY_SECTIONS)->invalidateCategory();
    }

    protected function tearDown(): void
    {
        QaItemStyleResolver::reset();
        $this->service(ResourceReadIndex::class)->invalidate();
        $this->service(CacheService::class)->withCategory(CacheService::CATEGORY_PAGES)->invalidateCategory();
        $this->service(CacheService::class)->withCategory(CacheService::CATEGORY_SECTIONS)->invalidateCategory();
        QaItemPluginFixture::forgetCaches(
            $this->service(CacheService::class),
            $this->service(PluginCacheInvalidator::class),
        );
        parent::tearDown();
    }

    public function testResolverDataAppearsForGuestAndSignedInUserAndRunsAgainOnCacheHit(): void
    {
        $page = $this->itemPage('prh3_resolve', self::ITEM);

        QaItemStyleResolver::$calls = 0;
        $guest = $this->pageOf($page);
        self::assertSame(1, QaItemStyleResolver::$calls);
        $plugin = $this->pluginOf($guest);
        self::assertSame(self::ITEM, $plugin['item_id'] ?? null);
        self::assertSame(0, $plugin['user_id'] ?? null, 'guest is user id 0');

        $token = $this->loginAsQaUser();
        $signedIn = $this->pageOf($page, $token);
        self::assertSame(2, QaItemStyleResolver::$calls, 'a second request runs the resolver again (after the page cache)');
        $pluginUser = $this->pluginOf($signedIn);
        self::assertSame(self::ITEM, $pluginUser['item_id'] ?? null);
        self::assertGreaterThan(0, $pluginUser['user_id'] ?? 0);

        // A third guest hit: the section tree is cached, but the resolver still runs.
        $this->pageOf($page);
        self::assertSame(3, QaItemStyleResolver::$calls, 'a cache hit still resolves plugin sections');
    }

    public function testASectionRemovedByItsConditionIsNotResolved(): void
    {
        $page = $this->pages->createPage('prh3_hidden', true);
        $visible = $this->pages->createSection('prh3_hidden_ok', QaItemPluginFixture::STYLE);
        $this->pages->linkSectionToPage($page, $visible, 10);
        $this->pages->setSectionField($visible, QaItemPluginFixture::FIELD, (string) self::ITEM);
        $hidden = $this->pages->createSection('prh3_hidden_no', QaItemPluginFixture::STYLE);
        $this->pages->linkSectionToPage($page, $hidden, 20);
        $this->pages->setSectionField($hidden, QaItemPluginFixture::FIELD, (string) self::OTHER_ITEM);
        $hidden->setCondition('{"==":[1,0]}');
        $this->service(\Doctrine\ORM\EntityManagerInterface::class)->flush();
        $this->pages->invalidatePageScopedCaches();

        QaItemStyleResolver::$calls = 0;
        $data = $this->pageOf($page);
        self::assertSame(1, QaItemStyleResolver::$calls, 'only the section that stayed on the page is resolved');
        $pageNode = $data['page'] ?? null;
        self::assertIsArray($pageNode);
        $sections = $pageNode['sections'] ?? null;
        self::assertIsArray($sections);
        self::assertCount(1, $sections);
        self::assertSame(self::ITEM, $this->pluginOf($data)['item_id'] ?? null);
    }

    public function testAThrowingResolverGivesPluginErrorOnThatSectionOnly(): void
    {
        $page = $this->pages->createPage('prh3_throw', true);
        $ok = $this->pages->createSection('prh3_throw_ok', QaItemPluginFixture::STYLE);
        $this->pages->linkSectionToPage($page, $ok, 10);
        $this->pages->setSectionField($ok, QaItemPluginFixture::FIELD, (string) self::ITEM);
        $bad = $this->pages->createSection('prh3_throw_bad', QaItemPluginFixture::STYLE);
        $this->pages->linkSectionToPage($page, $bad, 20);
        $this->pages->setSectionField($bad, QaItemPluginFixture::FIELD, (string) self::OTHER_ITEM);
        $this->pages->invalidatePageScopedCaches();

        QaItemStyleResolver::$throwForItemId = self::OTHER_ITEM;
        $payload = $this->pageOf($page);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $pageNode = $payload['page'] ?? null;
        self::assertIsArray($pageNode);
        $sections = $pageNode['sections'] ?? null;
        self::assertIsArray($sections);
        self::assertCount(2, $sections);
        $first = $sections[0] ?? null;
        $second = $sections[1] ?? null;
        self::assertIsArray($first);
        self::assertIsArray($second);
        $firstPlugin = $first['plugin'] ?? null;
        self::assertIsArray($firstPlugin);
        self::assertSame(self::ITEM, $firstPlugin['item_id'] ?? null);
        self::assertArrayNotHasKey('plugin_error', $first);
        self::assertSame('resolver_failed', $second['plugin_error'] ?? null);
        self::assertArrayNotHasKey('plugin', $second);
    }

    public function testDependsOnListsResourceFieldsAndFilteredResolverTags(): void
    {
        $tableId = $this->grid->table('qa_prh3_resolver_table', ['title' => 'text']);
        QaItemStyleResolver::$tableDependsOn = $tableId;

        $page = $this->itemPage('prh3_depends', self::ITEM);
        $sync = $this->syncOf($this->pageOf($page));

        self::assertContains(SyncTag::resource(QaItemPluginFixture::TYPE_CODE, self::ITEM), $sync['depends_on']);
        self::assertContains(SyncTag::table($tableId), $sync['depends_on']);
        self::assertNotContains('res:not_a_registered_type:9', $sync['depends_on']);
        self::assertNotContains(SyncTag::table(2_147_483_647), $sync['depends_on']);
        $last = $sync['depends_on'] === [] ? null : $sync['depends_on'][count($sync['depends_on']) - 1];
        self::assertSame(SyncTag::GLOBAL_VALUES, $last);
    }

    public function testDraftAndPublishedPayloadsEachListTheirOwnResourceReferences(): void
    {
        $page = $this->itemPage('prh3_versions', self::ITEM);
        $this->actAsAdmin();
        $versions = $this->service(PageVersionService::class);
        $version = $versions->createVersion((int) $page->getId());
        $versions->publishVersion((int) $page->getId(), (int) $version->getId());

        // Change the draft to another item after publishing.
        $em = $this->service(\Doctrine\ORM\EntityManagerInterface::class);
        $qaSection = $em->getRepository(\App\Entity\Section::class)->findOneBy(['name' => 'prh3_versions_section']);
        self::assertInstanceOf(\App\Entity\Section::class, $qaSection);
        $this->pages->setSectionField($qaSection, QaItemPluginFixture::FIELD, (string) self::OTHER_ITEM);
        $this->pages->invalidatePageScopedCaches();
        $this->service(ResourceReadIndex::class)->invalidate();

        $published = $this->syncOf($this->pageOf($page));
        self::assertContains(SyncTag::resource(QaItemPluginFixture::TYPE_CODE, self::ITEM), $published['depends_on']);
        self::assertNotContains(SyncTag::resource(QaItemPluginFixture::TYPE_CODE, self::OTHER_ITEM), $published['depends_on']);
        self::assertContains(SyncTag::page((int) $page->getId()), $published['depends_on']);

        $draft = $this->syncOf($this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/by-keyword/' . $page->getKeyword() . '?language_id=' . self::LANGUAGE_ID . '&preview=true',
            null,
            $this->loginAsQaAdmin(),
        )));
        self::assertContains(SyncTag::resource(QaItemPluginFixture::TYPE_CODE, self::OTHER_ITEM), $draft['depends_on']);
        self::assertNotContains(SyncTag::resource(QaItemPluginFixture::TYPE_CODE, self::ITEM), $draft['depends_on']);
        self::assertContains(SyncTag::pageDraft((int) $page->getId()), $draft['depends_on']);
    }

    private function itemPage(string $keyword, int $itemId): Page
    {
        $page = $this->pages->createPage($keyword, true);
        $section = $this->pages->createSection($keyword . '_section', QaItemPluginFixture::STYLE);
        $this->pages->linkSectionToPage($page, $section, 10);
        $this->pages->setSectionField($section, QaItemPluginFixture::FIELD, (string) $itemId);
        $this->pages->invalidatePageScopedCaches();

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageOf(Page $page, ?string $token = null): array
    {
        return $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            '/cms-api/v1/pages/by-keyword/' . $page->getKeyword() . '?language_id=' . self::LANGUAGE_ID,
            null,
            $token,
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function pluginOf(array $data): array
    {
        $pageNode = $data['page'] ?? null;
        self::assertIsArray($pageNode);
        $sections = $pageNode['sections'] ?? null;
        self::assertIsArray($sections);
        self::assertNotEmpty($sections);
        $first = $sections[0] ?? null;
        self::assertIsArray($first);
        $plugin = $first['plugin'] ?? null;
        self::assertIsArray($plugin);
        $typed = [];
        foreach ($plugin as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{depends_on: list<string>, valid_until: string|null}
     */
    private function syncOf(array $data): array
    {
        $sync = $data['sync'] ?? null;
        self::assertIsArray($sync);
        self::assertIsArray($sync['depends_on'] ?? null);

        return [
            'depends_on' => array_values(array_map(
                static fn (mixed $tag): string => is_string($tag) ? $tag : '',
                $sync['depends_on']
            )),
            'valid_until' => is_string($sync['valid_until'] ?? null) ? $sync['valid_until'] : null,
        ];
    }

    private function actAsAdmin(): void
    {
        $em = $this->service(\Doctrine\ORM\EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_ADMIN_EMAIL]);
        self::assertInstanceOf(User::class, $admin);
        $this->service(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($admin, 'main', $admin->getRoles())
        );
    }
}
