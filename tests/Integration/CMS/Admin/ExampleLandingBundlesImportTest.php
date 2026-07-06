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
 * The shipped landing-page templates (`hero-home` + `mobile-onboarding`
 * example bundles) must stay importable: every style/field/locale they
 * reference has to exist on a default install, and the hero bundle must
 * materialize as a headless, openly accessible landing page with its full
 * section tree. Guards the "beautiful importable landing pages" feature
 * against style-catalog drift (renamed fields, removed styles).
 *
 * Imports through the public admin API with QA prefixes and tears the pages
 * down again; DAMA rolls back the surrounding transaction (Testing Rules 9/10).
 */
#[Group('golden')]
final class ExampleLandingBundlesImportTest extends QaWebTestCase
{
    private const KEYWORD_PREFIX = 'qa-';
    private const ROUTE_PREFIX = '/qa';

    public function testShippedLandingBundlesValidateCleanly(): void
    {
        $admin = $this->loginAsQaAdmin();

        $bundles = [
            'hero-home' => ExampleBundleTestPaths::heroHomeBundle(),
            'mobile-onboarding' => ExampleBundleTestPaths::mobileOnboardingBundle(),
        ];

        foreach ($bundles as $id => $path) {
            $response = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import/validate', [
                'bundle' => $this->loadBundle($path),
                'options' => [
                    'keywordPrefix' => self::KEYWORD_PREFIX,
                    'routePrefix' => self::ROUTE_PREFIX,
                ],
            ], $admin);

            $report = $this->assertEnvelopeSuccess($response, 200);
            $issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];
            $errors = array_values(array_filter(
                $issues,
                static fn ($issue): bool => is_array($issue) && ($issue['level'] ?? '') === 'error',
            ));

            self::assertSame(
                [],
                $errors,
                sprintf('Shipped "%s" bundle must validate without errors: %s', $id, json_encode($errors)),
            );
            self::assertTrue((bool) ($report['valid'] ?? false), sprintf('Shipped "%s" bundle must be valid.', $id));
        }
    }

    public function testHeroHomeBundleImportsAsHeadlessLandingPage(): void
    {
        $admin = $this->loginAsQaAdmin();

        $import = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $this->loadBundle(ExampleBundleTestPaths::heroHomeBundle()),
            'options' => [
                'keywordPrefix' => self::KEYWORD_PREFIX,
                'routePrefix' => self::ROUTE_PREFIX,
            ],
        ], $admin);

        $data = $this->assertEnvelopeSuccess($import, 201);
        $created = is_array($data['created'] ?? null) ? $data['created'] : [];

        $pageId = null;
        foreach ($created as $entry) {
            if (is_array($entry) && ($entry['keyword'] ?? null) === self::KEYWORD_PREFIX . 'hero-home') {
                $pageId = $entry['page_id'] ?? null;
            }
        }
        self::assertIsInt($pageId, 'Import must create the qa-hero-home landing page.');

        try {
            // Landing pages render without the site chrome and must be open to
            // guests — the two properties the template is built around.
            $pageResponse = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            $pageData = $this->assertEnvelopeSuccess($pageResponse, 200);
            $page = is_array($pageData['page'] ?? null) ? $pageData['page'] : [];
            self::assertTrue((bool) ($page['is_headless'] ?? $page['headless'] ?? false), 'Hero landing page must be headless.');
            self::assertSame(self::ROUTE_PREFIX . '/hero-home', $page['url'] ?? null, 'Landing page url must follow the prefixed canonical route.');

            // The full section tree must materialize: hero, stats, features,
            // showcase, quote, and CTA blocks as page roots with children.
            $sectionsResponse = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $admin);
            $sectionsData = $this->assertEnvelopeSuccess($sectionsResponse, 200);
            $roots = is_array($sectionsData['sections'] ?? null) ? $sectionsData['sections'] : [];
            self::assertCount(6, $roots, 'Hero landing page must import all six top-level blocks.');

            $rootNames = array_map(
                static function (mixed $section): string {
                    $name = is_array($section) ? ($section['section_name'] ?? '') : '';

                    return is_string($name) ? $name : '';
                },
                $roots,
            );
            foreach (['hero-home-hero', 'hero-home-features', 'hero-home-cta'] as $expected) {
                $matches = array_filter($rootNames, static fn (string $name): bool => str_starts_with($name, $expected));
                self::assertNotEmpty($matches, sprintf('Imported page must carry the "%s" block (got: %s)', $expected, implode(', ', $rootNames)));
            }

            $hero = null;
            foreach ($roots as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $name = $section['section_name'] ?? '';
                if (is_string($name) && str_starts_with($name, 'hero-home-hero')) {
                    $hero = $section;
                    break;
                }
            }
            self::assertIsArray($hero, 'Hero block must exist.');
            self::assertNotEmpty($hero['children'] ?? [], 'Hero block must keep its nested children (copy + imagery columns).');
        } finally {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBundle(string $path): array
    {
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
