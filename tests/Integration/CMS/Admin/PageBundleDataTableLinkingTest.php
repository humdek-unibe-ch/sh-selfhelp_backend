<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Tests\Support\ExampleBundleTestPaths;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration coverage for the CMS-in-CMS bundle data-table LINKING contract
 * (issue #30, p7): the keystone the audit flagged as missing.
 *
 * Imports the shipped self-contained Team-Members example bundle (which binds
 * its entry-list / entry-record sections to the owning form section via the
 * portable `fields.data_table = "@section:<owner>"` token and carries sample rows
 * in `data_tables[]`) and asserts the round-trip works end to end:
 *   - all four pages are created (form + public list/detail + admin list);
 *   - the owner token is relinked to the NEW form section id (a numeric data
 *     table), proven by the public list resolving the SEEDED rows;
 *   - `importData=true` re-inserts the sample rows through the form-save path.
 *
 * Everything is keyword/route-prefixed with `qa`, created through the public
 * admin API, and torn down by deleting the created pages; DAMA rolls back the
 * surrounding transaction (Testing Rules 9/10).
 */
#[Group('golden')]
final class PageBundleDataTableLinkingTest extends QaWebTestCase
{
    private const KEYWORD_PREFIX = 'qa_';
    private const ROUTE_PREFIX = '/qa';

    public function testImportingBundleRelinksOwnerTokenAndSeedsRows(): void
    {
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->loadExampleBundle();

        // Import with a qa keyword/route prefix (collision-free) and importData
        // so the owned table is created and the sample rows are seeded.
        $import = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => [
                'keywordPrefix' => self::KEYWORD_PREFIX,
                'routePrefix' => self::ROUTE_PREFIX,
                'importData' => true,
            ],
        ], $admin);

        $data = $this->assertEnvelopeSuccess($import, 201);
        $created = $data['created'] ?? null;
        self::assertIsArray($created, 'Import must return the created pages.');
        self::assertCount(4, $created, 'The Team-Members bundle defines four pages.');

        $createdPageIds = [];
        foreach ($created as $entry) {
            if (is_array($entry) && is_int($entry['page_id'] ?? null)) {
                $createdPageIds[] = $entry['page_id'];
            }
        }
        self::assertCount(4, $createdPageIds, 'Every created page must expose an integer id.');

        try {
            // Resolving the public list proves the entry-list's `@section:` owner
            // token was relinked to the real (new) form-owned table AND the
            // sample rows were seeded — otherwise the list would resolve empty.
            $resolve = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::ROUTE_PREFIX . '/team-members'),
                null,
                $admin
            );
            $this->assertEnvelopeSuccess($resolve, 200);

            $body = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString(
                'Ada Lovelace',
                $body,
                'The resolved public list must contain a seeded row, proving the owner token relinked to the seeded table.'
            );
            self::assertStringNotContainsString(
                '@section:',
                $body,
                'No unresolved owner token may survive into a rendered page.'
            );
        } finally {
            foreach ($createdPageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    public function testImportSeedsTranslatableBundleRowsPerLocale(): void
    {
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->loadExampleBundle();

        $import = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => [
                'keywordPrefix' => self::KEYWORD_PREFIX,
                'routePrefix' => self::ROUTE_PREFIX,
                'importData' => true,
            ],
        ], $admin);

        $data = $this->assertEnvelopeSuccess($import, 201);
        $created = $data['created'] ?? null;
        self::assertIsArray($created);

        $createdPageIds = [];
        foreach ($created as $entry) {
            if (is_array($entry) && is_int($entry['page_id'] ?? null)) {
                $createdPageIds[] = $entry['page_id'];
            }
        }

        try {
            $resolveDe = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::ROUTE_PREFIX . '/team-members')
                    . '&language_id=2',
                null,
                $admin
            );
            $this->assertEnvelopeSuccess($resolveDe, 200);
            $bodyDe = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString(
                'analytischen Maschine',
                $bodyDe,
                'German bio translations from data_tables[] must be seeded on import.'
            );
        } finally {
            foreach ($createdPageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    public function testImportFailsWhenBundleCarriesAnUninstalledLocale(): void
    {
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->loadExampleBundle();

        // Inject a translatable field in a locale the QA install does not carry.
        $pages = is_array($bundle['pages'] ?? null) ? array_values($bundle['pages']) : [];
        self::assertNotEmpty($pages, 'The example bundle must define pages.');
        $firstPage = is_array($pages[0]) ? $pages[0] : [];
        $firstPage['fields'] = [[
            'name' => 'title',
            'display' => true,
            'translations' => [
                ['language_code' => 'zz-ZZ', 'content' => 'Nope'],
            ],
        ]];
        $pages[0] = $firstPage;
        $bundle['pages'] = $pages;

        $validate = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import/validate', [
            'bundle' => $bundle,
        ], $admin);

        $report = $this->assertEnvelopeSuccess($validate, 200);
        self::assertFalse($report['valid'] ?? true, 'A bundle with an uninstalled locale must be invalid.');

        $issues = $report['issues'] ?? [];
        self::assertIsArray($issues);
        $codes = [];
        foreach ($issues as $issue) {
            if (is_array($issue) && isset($issue['code'])) {
                $codes[] = $issue['code'];
            }
        }
        self::assertContains('missing_locale', $codes, 'The missing locale must be reported by code.');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadExampleBundle(): array
    {
        $path = ExampleBundleTestPaths::teamMembersBundle();
        self::assertFileExists($path, 'The shipped example bundle must exist.');

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, 'The example bundle must be valid JSON.');

        $bundle = [];
        foreach ($decoded as $key => $value) {
            $bundle[(string) $key] = $value;
        }

        return $bundle;
    }
}
