<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Tests\Support\ExampleBundleTestPaths;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * CI guard for the CMS-in-CMS template gallery (issue #30): every shipped
 * template bundle must validate cleanly, import with prefixes + sample data,
 * and render its public entry page with at least two hydrated rows. A template
 * edit that breaks import or hydration fails here, so the gallery cannot rot.
 *
 * Imported with a `qa_` keyword prefix AND a `/qa-tpl` route prefix (the exact
 * one-click "Start from template" flow); pages are deleted in a finally block
 * and DAMA rolls back the surrounding transaction.
 */
#[Group('golden')]
final class CmsInCmsTemplateBundlesImportTest extends QaWebTestCase
{
    private const KEYWORD_PREFIX = 'qa_';
    private const ROUTE_PREFIX = '/qa-tpl';

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     *   template id -> [bundle path, public list path, expected sample markers]
     */
    public static function templateBundles(): array
    {
        $bundles = ExampleBundleTestPaths::cmsInCmsBundles();

        // Public entry path (post route-prefix) + two sample-row markers that
        // must appear in the hydrated list render.
        $expectations = [
            'team-members' => ['/team-members', ['Ada Lovelace', 'Grace Hopper']],
            'news' => ['/news', ['Neue Studienplattform ist live', 'Zwei neue Fragebogen-Vorlagen']],
            // Default CMS language is de-CH; sample rows ship bilingual content and
            // the public list renders the default-locale labels.
            // Markers must match decoded render content. Accordion labels are
            // "Category — question"; assert on the question fragment (umlaut-safe
            // via JSON decode below, not raw response bytes).
            'faq' => ['/faq', ['Wie setze ich mein Passwort zurück?', 'Wer kann meine Antworten sehen?']],
            'events' => ['/events', ['Kick-off-Webinar zur Studie', 'Tag der offenen Labortür']],
            'contact-directory' => ['/contacts', ['Nora Keller', 'Sofia Ricci']],
            'testimonials' => ['/testimonials', ['Lena Baumann', 'Priya Sharma']],
        ];

        $cases = [];
        foreach ($expectations as $id => [$publicPath, $markers]) {
            $cases[$id] = [$bundles[$id], self::ROUTE_PREFIX . $publicPath, $markers];
        }

        return $cases;
    }

    /**
     * @param list<string> $markers
     */
    #[DataProvider('templateBundles')]
    public function testTemplateBundleImportsAndRendersSampleRows(string $bundlePath, string $publicPath, array $markers): void
    {
        $admin = $this->loginAsQaAdmin();

        self::assertFileExists($bundlePath);
        $decoded = json_decode((string) file_get_contents($bundlePath), true);
        self::assertIsArray($decoded);
        $bundle = [];
        foreach ($decoded as $key => $value) {
            $bundle[(string) $key] = $value;
        }

        $options = [
            'keywordPrefix' => self::KEYWORD_PREFIX,
            'routePrefix' => self::ROUTE_PREFIX,
            'importData' => true,
        ];

        // The validate endpoint must accept the shipped bundle without errors.
        $validate = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import/validate', [
            'bundle' => $bundle,
            'options' => $options,
        ], $admin);
        $validation = $this->assertEnvelopeSuccess($validate);
        self::assertTrue(
            (bool) ($validation['valid'] ?? false),
            'Bundle must validate cleanly: ' . json_encode($validation['issues'] ?? [])
        );

        $import = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => $options,
        ], $admin);
        $data = $this->assertEnvelopeSuccess($import, 201);

        $createdPageIds = [];
        foreach (is_array($data['created'] ?? null) ? $data['created'] : [] as $entry) {
            if (is_array($entry) && is_int($entry['page_id'] ?? null)) {
                $createdPageIds[] = $entry['page_id'];
            }
        }
        self::assertNotEmpty($createdPageIds, 'The import must create pages.');

        try {
            // The public entry page resolves through the PREFIXED route and
            // renders the hydrated sample rows (entry-list cloning worked and
            // the sample data import seeded the owned table).
            $this->client->request(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode($publicPath) . '&preview=true',
                [],
                [],
                $this->authHeaders($admin)
            );
            $response = $this->client->getResponse();
            self::assertSame(
                200,
                $response->getStatusCode(),
                'resolve ' . $publicPath . ': ' . (string) $response->getContent()
            );
            $body = (string) $response->getContent();
            // Decode so umlauts match UTF-8 markers (raw JSON may use \uXXXX escapes).
            $decodedBody = json_decode($body, true);
            $searchHaystack = is_array($decodedBody)
                ? (string) json_encode($decodedBody, JSON_UNESCAPED_UNICODE)
                : $body;

            foreach ($markers as $marker) {
                self::assertStringContainsString(
                    $marker,
                    $searchHaystack,
                    sprintf('The rendered list at %s must contain the seeded row marker "%s".', $publicPath, $marker)
                );
            }

            $cmsAppPayload = is_array($bundle['cms_app'] ?? null) ? $bundle['cms_app'] : [];
            $bundleSlug = is_string($cmsAppPayload['slug'] ?? null) ? $cmsAppPayload['slug'] : '';
            self::assertNotSame('', $bundleSlug, 'Template bundles must ship cms_app.slug metadata.');
            $importedSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', self::KEYWORD_PREFIX . $bundleSlug) ?? '', '-'));

            $declaredRoles = [];
            foreach (is_array($bundle['pages'] ?? null) ? $bundle['pages'] : [] as $page) {
                if (is_array($page) && is_string($page['cms_app_role'] ?? null) && $page['cms_app_role'] !== '') {
                    $declaredRoles[$page['cms_app_role']] = true;
                }
            }

            $appDetail = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', '/cms-api/v1/admin/cms-apps/by-slug/' . rawurlencode($importedSlug), null, $admin),
            );
            if (isset($declaredRoles['form'])) {
                self::assertNotEmpty($appDetail['id_form_section'] ?? null, 'CMS app hub must point at the form section.');
            }
            if (isset($declaredRoles['public_list'])) {
                self::assertNotEmpty($appDetail['id_public_list_page'] ?? null, 'CMS app hub must point at the public list page.');
            }
            if (isset($declaredRoles['public_detail'])) {
                self::assertNotEmpty($appDetail['id_public_detail_page'] ?? null, 'CMS app hub must point at the public detail page.');
            }
            if (isset($declaredRoles['cms_list'])) {
                self::assertNotEmpty($appDetail['id_cms_list_page'] ?? null, 'CMS app hub must point at the CMS entry-table page.');
            }
        } finally {
            foreach ($createdPageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }
}
