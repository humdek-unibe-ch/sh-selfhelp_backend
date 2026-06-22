<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\HttpFoundation\Response;

/**
 * Golden workflow for the AI section-import contract, focused on LOCALES — the
 * exact concern behind the curated `generated-examples/*.json`:
 *
 *   admin creates a qa_ page -> imports a section tree through the real
 *   POST /admin/pages/{id}/sections/import endpoint -> a subject user renders
 *   the page through the PUBLIC frontend API in BOTH content languages.
 *
 * The seeded baseline has languages all(1) / de-CH(2) / en-GB(3) with the CMS
 * default language = de-CH(2). This test pins the behaviour the examples depend
 * on:
 *
 *   1. Property fields authored under the `all` locale (id 1) render in every
 *      language (language-independent), e.g. `card.radius`.
 *   2. A content field authored in BOTH en-GB and de-CH renders the matching
 *      language for each viewer (bilingual authoring works through nested slots
 *      card -> card-segment -> text).
 *   3. A content field authored ONLY in the default language (de-CH) falls back
 *      to that default for an en-GB viewer — proving the render-time
 *      default-language fallback (PageService +
 *      SectionsFieldsTranslationRepository::fetchTranslationsForSectionsWithFallback).
 *
 * (1)+(2) are why the curated examples must carry content in the default content
 * language, not en-GB alone: with default = de-CH, an en-GB-only content field
 * is empty for the default (German) audience because the fallback merge is
 * skipped when the requested language IS the default. All data is qa_-prefixed
 * and rolled back by the DAMA transaction.
 */
#[TestGroup('golden')]
final class SectionImportLocalizationWorkflowTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_section_import_localization';
    private const URL = '/qa-section-import-localization';

    private const LANG_DE = 2; // de-CH — the seeded CMS default language
    private const LANG_EN = 3; // en-GB

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        // Single kernel/cache pool for the whole test so the ACL-grant cache the
        // factory invalidates is the exact pool the render reads (the proven
        // SectionAuthoringRenderingWorkflowTest pattern).
        $this->client->disableReboot();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );
    }

    public function testImportedSectionsRenderInBothLanguagesWithDefaultFallback(): void
    {
        $admin = $this->loginAsQaAdmin();

        // 1. Create the page through the admin API.
        $pageData = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => self::KEYWORD,
                'pageAccessTypeCode' => 'web',
                'url' => self::URL,
            ], $admin),
            Response::HTTP_CREATED
        );
        self::assertIsInt($pageData['id'] ?? null, 'Created page must expose an integer id');
        $pageId = (int) $pageData['id'];

        // 2. Import a tree that mirrors the curated examples' contract:
        //    - a `text` section with BILINGUAL content (en-GB + de-CH);
        //    - a `text` section with content in the DEFAULT language only (de-CH);
        //    - a nested card -> card-segment -> text with a property field under
        //      `all` (radius) and bilingual content.
        $importBody = [
            'position' => 0,
            'sections' => [
                [
                    'section_name' => 'qa_imp_bilingual',
                    'style_name' => 'text',
                    'fields' => [
                        'text' => [
                            'en-GB' => ['content' => 'Hello from the import'],
                            'de-CH' => ['content' => 'Hallo aus dem Import'],
                        ],
                    ],
                ],
                [
                    'section_name' => 'qa_imp_default_only',
                    'style_name' => 'text',
                    'fields' => [
                        // Authored ONLY in the default language (de-CH).
                        'text' => [
                            'de-CH' => ['content' => 'Nur auf Deutsch'],
                        ],
                    ],
                ],
                [
                    'section_name' => 'qa_imp_card',
                    'style_name' => 'card',
                    'fields' => [
                        // property field under the `all` locale (id 1).
                        'radius' => ['all' => ['content' => 'md']],
                    ],
                    'children' => [
                        [
                            'section_name' => 'qa_imp_card_segment',
                            'style_name' => 'card-segment',
                            'children' => [
                                [
                                    'section_name' => 'qa_imp_card_text',
                                    'style_name' => 'text',
                                    'fields' => [
                                        'text' => [
                                            'en-GB' => ['content' => 'Nested card body'],
                                            'de-CH' => ['content' => 'Verschachtelter Kartentext'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $imported = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', sprintf('/cms-api/v1/admin/pages/%d/sections/import', $pageId), $importBody, $admin)
        );
        // The endpoint returns a flat list of imported nodes (page + nested).
        $importedList = $this->asList($imported['imported_sections'] ?? $imported['sections'] ?? $imported);
        self::assertNotEmpty($importedList, 'Import must report the created sections.');

        // 3. Drop page-scoped caches so the public render observes THIS run's
        //    sections + ACL (DAMA reuses the deterministic keyword across runs).
        $this->pages->invalidatePageScopedCaches();
        $user = $this->loginAsQaUser();

        // 4a. Render in en-GB (id 3).
        $en = $this->renderPage($pageId, self::LANG_EN, $user);
        $enTop = $this->topLevelSections($en);

        self::assertSame('Hello from the import', $this->textOf($this->styleNamed($enTop, 'text', 0)), 'Bilingual section renders en-GB for an en-GB viewer.');
        self::assertSame('Nur auf Deutsch', $this->textOf($this->styleNamed($enTop, 'text', 1)), 'Default-only (de-CH) content falls back to the default for an en-GB viewer.');

        $enCard = $this->styleNamed($enTop, 'card', 0);
        self::assertSame('md', $this->fieldOf($enCard, 'radius'), 'Property field authored under `all` renders regardless of language.');
        $enCardText = $this->childSections($this->childSections($enCard)[0])[0];
        self::assertSame('Nested card body', $this->textOf($enCardText), 'Nested bilingual content renders en-GB for an en-GB viewer.');

        // 4b. Render in de-CH (id 2, the default).
        $de = $this->renderPage($pageId, self::LANG_DE, $user);
        $deTop = $this->topLevelSections($de);

        self::assertSame('Hallo aus dem Import', $this->textOf($this->styleNamed($deTop, 'text', 0)), 'Bilingual section renders de-CH for a de-CH viewer.');
        self::assertSame('Nur auf Deutsch', $this->textOf($this->styleNamed($deTop, 'text', 1)), 'Default-language content renders directly for the default audience.');

        $deCard = $this->styleNamed($deTop, 'card', 0);
        self::assertSame('md', $this->fieldOf($deCard, 'radius'), 'Property field under `all` is language-independent.');
        $deCardText = $this->childSections($this->childSections($deCard)[0])[0];
        self::assertSame('Verschachtelter Kartentext', $this->textOf($deCardText), 'Nested bilingual content renders de-CH for a de-CH viewer.');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @param string $token
     * @return array<string, mixed>
     */
    private function renderPage(int $pageId, int $languageId, string $token): array
    {
        return $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf('/cms-api/v1/pages/%d?language_id=%d', $pageId, $languageId), null, $token)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function topLevelSections(array $data): array
    {
        $page = $this->asArray($data['page'] ?? null);
        $sections = [];
        foreach ($this->asList($page['sections'] ?? null) as $section) {
            $sections[] = $this->asArray($section);
        }

        return $sections;
    }

    /**
     * The nth section with the given style_name (in document order).
     *
     * @param list<array<string, mixed>> $sections
     * @return array<string, mixed>
     */
    private function styleNamed(array $sections, string $styleName, int $nth): array
    {
        $matches = array_values(array_filter(
            $sections,
            fn(array $s): bool => ($s['style_name'] ?? null) === $styleName
        ));
        self::assertArrayHasKey($nth, $matches, sprintf('Expected at least %d "%s" section(s).', $nth + 1, $styleName));

        return $matches[$nth];
    }

    /**
     * @param array<string, mixed> $section
     * @return list<array<string, mixed>>
     */
    private function childSections(array $section): array
    {
        $children = [];
        foreach ($this->asList($section['children'] ?? null) as $child) {
            $children[] = $this->asArray($child);
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $section
     */
    private function textOf(array $section): string
    {
        return $this->fieldOf($section, 'text');
    }

    /**
     * Read a rendered field's resolved content (`section[field]['content']`).
     *
     * @param array<string, mixed> $section
     */
    private function fieldOf(array $section, string $field): string
    {
        return $this->asString($this->asArray($section[$field] ?? null)['content'] ?? null);
    }
}
