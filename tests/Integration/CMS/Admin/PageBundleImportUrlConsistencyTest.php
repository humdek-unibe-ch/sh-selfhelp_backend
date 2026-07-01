<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression for the imported-page "Page not found" bug (issue #30 follow-up).
 *
 * When a bundle is imported with a route prefix, `createPage` first stores the
 * bundle's RAW (unprefixed) `pages.url`, but the routes are materialized with
 * the prefix. The navigation menu / admin list build their link from
 * `pages.url`, so the link pointed at the unprefixed path and 404'd through the
 * DB router. The importer now realigns `pages.url` to the (prefixed) canonical
 * route in pass 2. This test proves:
 *   - the public list page's `url` equals the PREFIXED canonical route
 *     (`/qa/team-members`), not the raw bundle url (`/team-members`);
 *   - the cms page's `url` equals its prefixed route (`/qa/cms/team-members`);
 *   - the (prefixed) public path resolves for the admin (admin always has
 *     access to imported pages).
 *
 * Imports through the public admin API and tears the pages down again; DAMA
 * rolls back the surrounding transaction (Testing Rules 9/10).
 */
#[Group('golden')]
final class PageBundleImportUrlConsistencyTest extends QaWebTestCase
{
    private const KEYWORD_PREFIX = 'qa_';
    private const ROUTE_PREFIX = '/qa';

    public function testImportedPageUrlIsRealignedToPrefixedCanonicalRoute(): void
    {
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->loadExampleBundle();

        $import = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => [
                'keywordPrefix' => self::KEYWORD_PREFIX,
                'routePrefix' => self::ROUTE_PREFIX,
            ],
        ], $admin);

        $data = $this->assertEnvelopeSuccess($import, 201);
        $created = $data['created'] ?? null;
        self::assertIsArray($created, 'Import must return the created pages.');

        // Map the (prefixed) keyword -> created page id.
        $idByKeyword = [];
        foreach ($created as $entry) {
            if (is_array($entry) && is_string($entry['keyword'] ?? null) && is_int($entry['page_id'] ?? null)) {
                $idByKeyword[$entry['keyword']] = $entry['page_id'];
            }
        }

        $publicKeyword = self::KEYWORD_PREFIX . 'team-members';
        $cmsKeyword = self::KEYWORD_PREFIX . 'cms-team-members';
        self::assertArrayHasKey($publicKeyword, $idByKeyword, 'The public list page must be created.');
        self::assertArrayHasKey($cmsKeyword, $idByKeyword, 'The cms list page must be created.');

        try {
            // The public list page url must be the PREFIXED canonical route, so
            // the menu/admin-list link resolves through the DB router. Without
            // the realignment fix this would still be the raw `/team-members`.
            self::assertSame(
                self::ROUTE_PREFIX . '/team-members',
                $this->fetchPageUrl($idByKeyword[$publicKeyword], $admin),
                'Imported public page url must equal its prefixed canonical route.'
            );

            // The cms page keeps its `/cms/...` shape under the prefix.
            self::assertSame(
                self::ROUTE_PREFIX . '/cms/team-members',
                $this->fetchPageUrl($idByKeyword[$cmsKeyword], $admin),
                'Imported cms page url must equal its prefixed canonical route.'
            );

            // Admin always has access to an imported page: the prefixed public
            // path resolves (200) for the admin who imported it.
            $resolve = $this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode(self::ROUTE_PREFIX . '/team-members'),
                null,
                $admin
            );
            $this->assertEnvelopeSuccess($resolve, 200);
        } finally {
            foreach ($idByKeyword as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    /**
     * Read a created page's persisted `url` through the admin get-page endpoint.
     */
    private function fetchPageUrl(int $pageId, string $admin): string
    {
        $response = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
        $data = $this->assertEnvelopeSuccess($response, 200);
        $page = is_array($data['page'] ?? null) ? $data['page'] : [];
        self::assertIsString($page['url'] ?? null, 'The admin page payload must carry a string url.');

        return (string) $page['url'];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadExampleBundle(): array
    {
        $path = dirname(__DIR__, 4) . '/docs/examples/cms-in-cms/team-members.bundle.json';
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
