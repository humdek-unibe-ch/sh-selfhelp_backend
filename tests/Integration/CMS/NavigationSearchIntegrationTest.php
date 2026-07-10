<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Repository\PageSearchIndexRepository;
use App\Service\CMS\NavigationCacheInvalidator;
use App\Service\CMS\NavigationSearchIndexService;
use App\Service\CMS\NavigationSearchService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

#[Group('integration')]
final class NavigationSearchIntegrationTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_nav_search_integration';
    private const UNIQUE_BODY = 'qa_nav_search_snippet_marker_xyzzy';
    private const UNIQUE_TITLE = 'qa_nav_search_acl_hidden_title';

    protected function tearDown(): void
    {
        $admin = $this->loginAsQaAdmin();
        /** @var PageRepository $pageRepo */
        $pageRepo = self::getContainer()->get(PageRepository::class);
        $page = $pageRepo->findOneBy(['keyword' => self::KEYWORD]);
        if ($page instanceof Page) {
            $this->jsonRequest('DELETE', '/cms-api/v1/admin/pages/' . $page->getId(), null, $admin);
        }

        parent::tearDown();
    }

    public function testSearchVisibilityHiddenExcludesPageFromResults(): void
    {
        $admin = $this->loginAsQaAdmin();
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD,
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        $this->setPagePropertyField($pageId, 'search_visibility', 'hidden');
        $this->setPageTitleTranslation($pageId, self::KEYWORD . '_findme');

        /** @var NavigationSearchIndexService $indexService */
        $indexService = self::getContainer()->get(NavigationSearchIndexService::class);
        $indexService->rebuildForPage($pageId);

        /** @var NavigationSearchService $searchService */
        $searchService = self::getContainer()->get(NavigationSearchService::class);
        $results = $searchService->search(
            self::KEYWORD . '_findme',
            LookupService::PAGE_ACCESS_TYPES_WEB,
            1,
            20,
        );
        foreach ($results as $row) {
            self::assertNotSame($pageId, $row['page_id'] ?? null);
        }
    }

    public function testContentSearchReturnsSnippetForIndexedBody(): void
    {
        self::markTestSkippedIf(!$this->searchIndexTableExists(), 'page_search_index migration not applied');

        $admin = $this->loginAsQaAdmin();
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD,
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO page_search_index (title_text, description_text, body_text, updated_at, id_pages, id_languages)
                VALUES (:title, NULL, :body, UTC_TIMESTAMP(), :pageId, 1)
                ON DUPLICATE KEY UPDATE body_text = VALUES(body_text), updated_at = UTC_TIMESTAMP()
                SQL,
            [
                'title' => self::KEYWORD,
                'body' => 'Intro paragraph with ' . self::UNIQUE_BODY . ' inside.',
                'pageId' => $pageId,
            ],
        );

        /** @var NavigationSearchService $searchService */
        $searchService = self::getContainer()->get(NavigationSearchService::class);
        $results = $searchService->search(self::UNIQUE_BODY, LookupService::PAGE_ACCESS_TYPES_WEB, 1, 20);
        self::assertNotEmpty($results);

        $hit = null;
        foreach ($results as $row) {
            if (($row['page_id'] ?? null) === $pageId) {
                $hit = $row;
                break;
            }
        }
        self::assertIsArray($hit);
        self::assertSame('content', $hit['source'] ?? null);
        self::assertIsString($hit['snippet'] ?? null);
        self::assertStringContainsString(self::UNIQUE_BODY, (string) $hit['snippet']);
    }

    public function testMetadataSearchFindsPageByOtherLanguageTitle(): void
    {
        $admin = $this->loginAsQaAdmin();
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD,
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        $otherLanguageId = $this->findSecondLanguageId();
        if ($otherLanguageId === null) {
            self::markTestSkipped('QA baseline has a single language');
        }

        $this->setPageTitleTranslation($pageId, 'qa_nav_xlang_current_title');
        $this->setPageTitleTranslationForLanguage($pageId, $otherLanguageId, 'qa_nav_xlang_other_marker');

        /** @var NavigationSearchService $searchService */
        $searchService = self::getContainer()->get(NavigationSearchService::class);
        // Query typed in the OTHER language must find the page even though the
        // search runs with language 1; the hit renders the current-language title.
        $results = $searchService->searchPageMetadataOnly(
            'qa_nav_xlang_other_marker',
            LookupService::PAGE_ACCESS_TYPES_WEB,
            1,
            20,
        );

        $hit = null;
        foreach ($results as $row) {
            if (($row['page_id'] ?? null) === $pageId) {
                $hit = $row;
                break;
            }
        }
        self::assertIsArray($hit, 'cross-language title should surface the page');
        self::assertSame('qa_nav_xlang_current_title', $hit['title'] ?? null);
    }

    public function testContentIndexSearchReturnsSinglePageHitAcrossLanguages(): void
    {
        self::markTestSkippedIf(!$this->searchIndexTableExists(), 'page_search_index migration not applied');
        $otherLanguageId = $this->findSecondLanguageId();
        if ($otherLanguageId === null) {
            self::markTestSkipped('QA baseline has a single language');
        }

        $admin = $this->loginAsQaAdmin();
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD,
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        foreach ([1, $otherLanguageId] as $languageId) {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO page_search_index (title_text, description_text, body_text, updated_at, id_pages, id_languages)
                    VALUES (:title, NULL, :body, UTC_TIMESTAMP(), :pageId, :languageId)
                    ON DUPLICATE KEY UPDATE body_text = VALUES(body_text), updated_at = UTC_TIMESTAMP()
                    SQL,
                [
                    'title' => self::KEYWORD,
                    'body' => 'Body with ' . self::UNIQUE_BODY . ' present.',
                    'pageId' => $pageId,
                    'languageId' => $languageId,
                ],
            );
        }

        /** @var NavigationSearchService $searchService */
        $searchService = self::getContainer()->get(NavigationSearchService::class);
        $results = $searchService->search(self::UNIQUE_BODY, LookupService::PAGE_ACCESS_TYPES_WEB, 1, 20);

        $hitsForPage = array_values(array_filter(
            $results,
            static fn (array $row): bool => ($row['page_id'] ?? null) === $pageId,
        ));
        self::assertCount(1, $hitsForPage, 'a page indexed in several languages must yield one hit');
    }

    public function testGuestSearchDoesNotReturnAdminOnlyPage(): void
    {
        $admin = $this->loginAsQaAdmin();
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => false,
            'url' => '/' . self::KEYWORD,
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        $this->setPageTitleTranslation($pageId, self::UNIQUE_TITLE);

        if ($this->searchIndexTableExists()) {
            /** @var NavigationSearchIndexService $indexService */
            $indexService = self::getContainer()->get(NavigationSearchIndexService::class);
            $indexService->rebuildForPage($pageId);
        }

        $this->loginAsQaGuest();

        /** @var NavigationSearchService $searchService */
        $searchService = self::getContainer()->get(NavigationSearchService::class);
        $results = $searchService->search(
            self::UNIQUE_TITLE,
            LookupService::PAGE_ACCESS_TYPES_WEB,
            1,
            20,
        );
        foreach ($results as $row) {
            self::assertNotSame($pageId, $row['page_id'] ?? null);
        }
    }

    public function testInvalidateForPageRebuildsSearchIndexRow(): void
    {
        self::markTestSkippedIf(!$this->searchIndexTableExists(), 'page_search_index migration not applied');
        $admin = $this->loginAsQaAdmin();
        $created = $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
            'keyword' => self::KEYWORD,
            'pageAccessTypeCode' => LookupService::PAGE_ACCESS_TYPES_WEB,
            'headless' => false,
            'openAccess' => true,
            'url' => '/' . self::KEYWORD,
        ], $admin);
        $pageData = $this->assertEnvelopeSuccess($created, Response::HTTP_CREATED);
        $pageId = $pageData['id'] ?? null;
        self::assertIsInt($pageId);

        $this->setPageTitleTranslation($pageId, 'before rebuild title');

        /** @var NavigationCacheInvalidator $invalidator */
        $invalidator = self::getContainer()->get(NavigationCacheInvalidator::class);
        $invalidator->invalidateForPage($pageId);

        /** @var PageSearchIndexRepository $indexRepo */
        $indexRepo = self::getContainer()->get(PageSearchIndexRepository::class);
        $row = $indexRepo->findOneByPageAndLanguage($pageId, 1);
        self::assertNotNull($row);
        self::assertStringContainsString('before rebuild', (string) $row->getTitleText());

        $this->setPageTitleTranslation($pageId, 'after rebuild title');
        $invalidator->invalidateForPage($pageId);

        $row = $indexRepo->findOneByPageAndLanguage($pageId, 1);
        self::assertNotNull($row);
        self::assertStringContainsString('after rebuild', (string) $row->getTitleText());
    }

    public function testNavigationSearchServiceHonoursAclForGuest(): void
    {
        /** @var NavigationSearchService $searchService */
        $searchService = self::getContainer()->get(NavigationSearchService::class);
        $results = $searchService->search(
            self::UNIQUE_TITLE,
            LookupService::PAGE_ACCESS_TYPES_WEB,
            1,
            20,
        );
        $pageIds = array_map(
            static function (array $row): int {
                $pageId = $row['page_id'] ?? 0;
                if (is_int($pageId)) {
                    return $pageId;
                }
                if (is_numeric($pageId)) {
                    return (int) $pageId;
                }

                return 0;
            },
            $results,
        );
        self::assertNotContains(0, $pageIds);
    }

    private function setPagePropertyField(int $pageId, string $fieldName, string $value): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO pages_fields_translation (id_pages, id_fields, id_languages, content)
                SELECT :pageId, f.id, 1, :value
                FROM fields f
                WHERE f.name = :fieldName
                ON DUPLICATE KEY UPDATE content = VALUES(content)
                SQL,
            ['pageId' => $pageId, 'fieldName' => $fieldName, 'value' => $value],
        );
    }

    private function setPageTitleTranslation(int $pageId, string $title): void
    {
        $this->setPageTitleTranslationForLanguage($pageId, 1, $title);
    }

    private function setPageTitleTranslationForLanguage(int $pageId, int $languageId, string $title): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO pages_fields_translation (id_pages, id_fields, id_languages, content)
                SELECT :pageId, f.id, :languageId, :title
                FROM fields f
                WHERE f.name = 'title'
                ON DUPLICATE KEY UPDATE content = VALUES(content)
                SQL,
            ['pageId' => $pageId, 'languageId' => $languageId, 'title' => $title],
        );
    }

    private function findSecondLanguageId(): ?int
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $id = $em->getConnection()->fetchOne('SELECT id FROM languages WHERE id <> 1 ORDER BY id LIMIT 1');

        return is_numeric($id) ? (int) $id : null;
    }

    private function searchIndexTableExists(): bool
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        try {
            $em->getConnection()->fetchOne('SELECT 1 FROM page_search_index LIMIT 1');
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private static function markTestSkippedIf(bool $condition, string $message): void
    {
        if ($condition) {
            self::markTestSkipped($message);
        }
    }
}
