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
 * End-to-end proof that the curated AI examples shipped in
 * `sh-selfhelp_frontend/docs/reference/ai-prompts/generated-examples/*.json`
 * actually import through the real
 * `POST /admin/pages/{id}/sections/import` endpoint AND render correctly in
 * BOTH content languages — the precise worry behind making the examples
 * bilingual.
 *
 * A REPRESENTATIVE subset of the curated files (broad style coverage, distinct
 * German vs English copy) is imported in a SINGLE call onto ONE qa_ page (their
 * top-level nodes are independent), then the page is rendered through the
 * PUBLIC frontend API once per locale. The subset keeps this write-heavy
 * workflow within a feasible runtime; the full set of curated files is checked
 * structurally (styles/fields/locales + mandatory default-language content) by
 * the DB-free `scripts/validate-ai-examples.mjs` in the frontend repo. The test
 * asserts that every authored real-locale `content` value shows up in the
 * render of its OWN locale — in particular every de-CH (the CMS default) value,
 * so no example renders blank for the default audience.
 *
 * The examples live in the sibling frontend repo; when that checkout is not
 * present beside the backend (e.g. backend-only CI) the test skips instead of
 * failing. All data is qa_-prefixed and rolled back by the DAMA transaction.
 *
 * @see SectionImportLocalizationWorkflowTest for the synthetic-payload contract
 *      (property `all` locale, bilingual content, default-language fallback).
 */
#[TestGroup('golden')]
final class AiExampleImportRenderTest extends QaWebTestCase
{
    private const KEYWORD = 'qa_ai_examples_import';
    private const URL = '/qa-ai-examples-import';

    /**
     * Representative curated files: broad style coverage (container, card,
     * card-segment, stack, image, title, text, group, badge, button, list,
     * list-item) with clearly distinct de-CH copy. Kept small on purpose — the
     * full catalogue is validated DB-free by the frontend validator.
     */
    private const REPRESENTATIVE = [
        'developer-profile-card.json',
        'modern-team-list.json',
    ];

    private const LANG_DE = 2; // de-CH — seeded CMS default language
    private const LANG_EN = 3; // en-GB

    /** locale code => language id */
    private const LOCALES = ['en-GB' => self::LANG_EN, 'de-CH' => self::LANG_DE];

    private EntityManagerInterface $em;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        // One kernel/cache pool for the whole test so the ACL-grant cache the
        // factory invalidates is the exact pool the render reads.
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

    public function testRepresentativeCuratedExamplesImportAndRenderBilingually(): void
    {
        $dir = self::examplesDir();
        if ($dir === null) {
            self::markTestSkipped('Frontend generated-examples directory not found beside the backend repo.');
        }

        $files = [];
        foreach (self::REPRESENTATIVE as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                $files[] = $path;
            }
        }
        self::assertNotEmpty($files, 'Expected at least one representative curated example JSON to verify.');

        // Load each example, namespace its section names per file (so the
        // combined import has no collisions), and gather expected content.
        $allSections = [];
        $expected = ['en-GB' => [], 'de-CH' => []];
        $fileCount = 0;

        foreach ($files as $i => $file) {
            $base = basename($file, '.json');
            $raw = file_get_contents($file);
            self::assertIsString($raw, sprintf('Cannot read example "%s".', $base));
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded, sprintf('Example "%s" must be a JSON array of section nodes.', $base));
            /** @var list<array<string, mixed>> $sections */
            $sections = array_values(array_filter($decoded, 'is_array'));
            self::assertNotEmpty($sections, sprintf('Example "%s" has no importable sections.', $base));

            $prefix = sprintf('qa_ex%d_', $i);
            foreach ($sections as $node) {
                $allSections[] = $this->namespaceNames($node, $prefix);
            }

            $collected = $this->collectContentByLocale($sections);
            $expected['en-GB'] = array_merge($expected['en-GB'], $collected['en-GB']);
            $expected['de-CH'] = array_merge($expected['de-CH'], $collected['de-CH']);
            $fileCount++;
        }

        $admin = $this->loginAsQaAdmin();

        // Create the target page through the admin API.
        $pageData = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                'keyword' => self::KEYWORD,
                'pageAccessTypeCode' => 'web',
                'url' => self::URL,
            ], $admin),
            Response::HTTP_CREATED
        );
        self::assertIsInt($pageData['id'] ?? null, 'Created page must expose an integer id.');
        $pageId = (int) $pageData['id'];

        // Import every curated example tree in one call.
        $imported = $this->assertEnvelopeSuccess(
            $this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/admin/pages/%d/sections/import', $pageId),
                ['position' => 0, 'sections' => $allSections],
                $admin
            )
        );
        $importedList = $this->asList($imported['imported_sections'] ?? $imported['sections'] ?? $imported);
        self::assertNotEmpty($importedList, 'Combined import must report created sections.');

        // Drop page-scoped caches so the public render observes this run.
        $this->pages->invalidatePageScopedCaches();
        $user = $this->loginAsQaUser();

        foreach (self::LOCALES as $locale => $languageId) {
            $rendered = $this->renderPage($languageId, $user);
            $blob = (string) json_encode($rendered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $expectedValues = $this->uniqueValues($expected[$locale]);
            self::assertNotEmpty(
                $expectedValues,
                sprintf('No %s content collected across %d example(s) — every example must author the %s locale.', $locale, $fileCount, $locale)
            );

            foreach ($expectedValues as $value) {
                self::assertStringContainsString(
                    $value,
                    $blob,
                    sprintf('Curated examples rendered in %s are missing authored content "%s".', $locale, $value)
                );
            }
        }
    }

    // -- helpers ------------------------------------------------------------

    private static function examplesDir(): ?string
    {
        // tests/Golden -> backend root -> SelfHelp root -> frontend repo.
        $backendRoot = dirname(__DIR__, 2);
        $candidate = dirname($backendRoot)
            . '/sh-selfhelp_frontend/docs/reference/ai-prompts/generated-examples';

        return is_dir($candidate) ? $candidate : null;
    }

    /**
     * Recursively prefix every `section_name` so files combined into one import
     * cannot collide. Nodes without a name keep auto-assignment.
     *
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private function namespaceNames(array $node, string $prefix): array
    {
        if (isset($node['section_name']) && is_string($node['section_name'])) {
            $node['section_name'] = $prefix . $node['section_name'];
        }

        $children = $node['children'] ?? null;
        if (is_array($children)) {
            $node['children'] = array_map(
                fn(mixed $child): mixed => is_array($child) ? $this->namespaceNames($child, $prefix) : $child,
                $children
            );
        }

        return $node;
    }

    /**
     * Recursively gather every authored real-locale (en-GB / de-CH) `content`
     * string from a section tree. Property fields (the `all` locale) and
     * `global_fields` (css/css_mobile) are intentionally ignored.
     *
     * @param list<array<string, mixed>> $nodes
     * @return array<string, list<string>> locale => content values
     */
    private function collectContentByLocale(array $nodes): array
    {
        $out = ['en-GB' => [], 'de-CH' => []];

        foreach ($nodes as $node) {
            $fields = $node['fields'] ?? null;
            if (is_array($fields)) {
                foreach ($fields as $translations) {
                    if (!is_array($translations)) {
                        continue;
                    }
                    foreach (array_keys(self::LOCALES) as $locale) {
                        $entry = $translations[$locale] ?? null;
                        if (!is_array($entry)) {
                            continue;
                        }
                        $content = $entry['content'] ?? null;
                        if (is_string($content) && $content !== '') {
                            $out[$locale][] = $content;
                        }
                    }
                }
            }

            $children = $node['children'] ?? null;
            if (is_array($children)) {
                /** @var list<array<string, mixed>> $childNodes */
                $childNodes = array_values(array_filter($children, 'is_array'));
                $childOut = $this->collectContentByLocale($childNodes);
                $out['en-GB'] = array_merge($out['en-GB'], $childOut['en-GB']);
                $out['de-CH'] = array_merge($out['de-CH'], $childOut['de-CH']);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function uniqueValues(array $values): array
    {
        return array_values(array_unique($values));
    }

    /**
     * @return array<string, mixed>
     */
    private function renderPage(int $languageId, string $token): array
    {
        return $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', sprintf('/cms-api/v1/pages/by-keyword/%s?language_id=%d', self::KEYWORD, $languageId), null, $token)
        );
    }
}
