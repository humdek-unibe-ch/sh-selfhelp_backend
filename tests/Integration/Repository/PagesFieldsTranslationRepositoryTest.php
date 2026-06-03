<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Field;
use App\Entity\Language;
use App\Entity\Page;
use App\Entity\PagesFieldsTranslation;
use App\Repository\PagesFieldsTranslationRepository;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for {@see PagesFieldsTranslationRepository} against the
 * real DB (plan Phase 12: translation repositories).
 *
 * Exercises the page-title translation query the frontend page list relies on:
 * primary-language fetch (display-field only), language fallback to the default
 * when the primary row is missing, the field-level "effectively empty" merge
 * (an empty rich-text wrapper must not win over a real default), the
 * missing-translation shape, and the no-fallback-requested passthrough.
 *
 * Uses the seeded `title` display field (id 22) and the seeded `de-CH` / `en-GB`
 * languages, resolved by name/locale rather than hard-coded ids. DAMA rolls the
 * created page + translation rows back at tearDown.
 */
final class PagesFieldsTranslationRepositoryTest extends QaKernelTestCase
{
    private PagesFieldsTranslationRepository $repository;
    private PageSectionFactory $pages;
    private Field $titleField;
    private Language $primary;
    private Language $fallback;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->service(PagesFieldsTranslationRepository::class);

        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );

        $title = $this->em->getRepository(Field::class)->findOneBy(['name' => 'title']);
        self::assertInstanceOf(Field::class, $title, 'Seeded display field "title" is required. Run: composer test:reset-db');
        self::assertTrue($title->isDisplay(), 'The "title" field must be a display field for this query.');
        $this->titleField = $title;

        $this->primary = $this->language('de-CH');
        $this->fallback = $this->language('en-GB');
    }

    public function testFetchReturnsPrimaryLanguageTitleForDisplayField(): void
    {
        $page = $this->pages->createPage('qa_pft_primary', true);
        $this->setTitle($page, $this->primary, 'qa_titel_de');
        $this->setTitle($page, $this->fallback, 'qa_title_en');

        $result = $this->repository->fetchTitleTranslationsForPages([$this->pageId($page)], $this->primaryId());

        self::assertSame('qa_titel_de', $this->titleOf($result, $page));
    }

    public function testFetchWithFallbackUsesDefaultWhenPrimaryIsMissing(): void
    {
        $page = $this->pages->createPage('qa_pft_fallback', true);
        // Only the default (en-GB) title exists; the primary (de-CH) is absent.
        $this->setTitle($page, $this->fallback, 'qa_title_en_only');

        $result = $this->repository->fetchTitleTranslationsWithFallback(
            [$this->pageId($page)],
            $this->primaryId(),
            $this->fallbackId()
        );

        self::assertSame(
            'qa_title_en_only',
            $this->titleOf($result, $page),
            'When the primary-language title is missing, the default-language title must be used.'
        );
    }

    public function testFetchWithFallbackKeepsDefaultWhenPrimaryIsEffectivelyEmpty(): void
    {
        $page = $this->pages->createPage('qa_pft_empty_primary', true);
        // An empty rich-text wrapper must NOT override a real default value.
        $this->setTitle($page, $this->primary, '<p></p>');
        $this->setTitle($page, $this->fallback, 'qa_real_default_title');

        $result = $this->repository->fetchTitleTranslationsWithFallback(
            [$this->pageId($page)],
            $this->primaryId(),
            $this->fallbackId()
        );

        self::assertSame(
            'qa_real_default_title',
            $this->titleOf($result, $page),
            'An effectively-empty primary title must fall back to the default.'
        );
    }

    public function testFetchWithFallbackOverridesDefaultWhenPrimaryHasContent(): void
    {
        $page = $this->pages->createPage('qa_pft_override', true);
        $this->setTitle($page, $this->primary, 'qa_real_primary_title');
        $this->setTitle($page, $this->fallback, 'qa_default_title');

        $result = $this->repository->fetchTitleTranslationsWithFallback(
            [$this->pageId($page)],
            $this->primaryId(),
            $this->fallbackId()
        );

        self::assertSame('qa_real_primary_title', $this->titleOf($result, $page));
    }

    public function testFetchWithFallbackReturnsEmptyTranslationsForPageWithoutTitles(): void
    {
        $page = $this->pages->createPage('qa_pft_none', true);

        $pageId = $this->pageId($page);
        $result = $this->repository->fetchTitleTranslationsWithFallback(
            [$pageId],
            $this->primaryId(),
            $this->fallbackId()
        );

        self::assertArrayHasKey($pageId, $result);
        self::assertSame([], $result[$pageId], 'A page with no title translations yields an empty field map.');
    }

    public function testFetchWithoutDefaultLanguageReturnsPrimaryOnly(): void
    {
        $page = $this->pages->createPage('qa_pft_no_default', true);
        $this->setTitle($page, $this->primary, 'qa_only_primary');

        $result = $this->repository->fetchTitleTranslationsWithFallback(
            [$this->pageId($page)],
            $this->primaryId(),
            null
        );

        self::assertSame('qa_only_primary', $this->titleOf($result, $page));
    }

    public function testFetchReturnsEmptyForEmptyPageList(): void
    {
        self::assertSame([], $this->repository->fetchTitleTranslationsForPages([], $this->primaryId()));
        self::assertSame([], $this->repository->fetchTitleTranslationsWithFallback([], $this->primaryId(), $this->fallbackId()));
    }

    private function setTitle(Page $page, Language $language, string $content): void
    {
        $translation = new PagesFieldsTranslation();
        $translation->setPage($page);
        $translation->setField($this->titleField);
        $translation->setLanguage($language);
        $translation->setContent($content);
        $this->em->persist($translation);
        $this->em->flush();
    }

    /**
     * @param array<int|string, array<string, mixed>> $result
     */
    private function titleOf(array $result, Page $page): ?string
    {
        $pageId = $this->pageId($page);
        if (!isset($result[$pageId])) {
            return null;
        }

        $title = $result[$pageId]['title'] ?? null;

        return is_string($title) ? $title : null;
    }

    private function pageId(Page $page): int
    {
        $id = $page->getId();
        self::assertIsInt($id);

        return $id;
    }

    private function language(string $locale): Language
    {
        $language = $this->em->getRepository(Language::class)->findOneBy(['locale' => $locale]);
        self::assertInstanceOf(Language::class, $language, sprintf('Seeded language "%s" is required.', $locale));

        return $language;
    }

    private function primaryId(): int
    {
        return $this->idOf($this->primary);
    }

    private function fallbackId(): int
    {
        return $this->idOf($this->fallback);
    }

    private function idOf(Language $language): int
    {
        $id = $language->getId();
        self::assertIsInt($id);

        return $id;
    }
}
