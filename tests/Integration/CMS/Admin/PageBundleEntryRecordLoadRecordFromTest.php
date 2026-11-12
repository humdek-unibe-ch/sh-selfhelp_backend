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
 * The detail route parameter of an `entry-record` section (`load_record_from`).
 *
 * The exporter leaves out every field whose value equals the style default, and
 * the default of `load_record_from` is `record_id`. A bundle exported from an
 * app that uses the default therefore carries no such field, and the import
 * validation must read that as "the default parameter". It refuses only a value
 * that is present and empty, or that is not a parameter name.
 *
 * The shipped team-members bundle is used as the base; only its `entry-record`
 * sections are changed, and the dry-run endpoint reports what the import would
 * refuse. Nothing is written.
 */
#[Group('golden')]
final class PageBundleEntryRecordLoadRecordFromTest extends QaWebTestCase
{
    private const KEYWORD_PREFIX = 'qa_lrf_';
    private const ROUTE_PREFIX = '/qa-lrf';

    public function testABundleThatLeavesOutTheDefaultParameterStillValidates(): void
    {
        $report = $this->validateWithLoadRecordFrom(null);

        self::assertNotContains(
            'missing_entry_record_load_record_from',
            $report['codes'],
            'A bundle without the field means the style default (record_id), as the exporter writes it.'
        );
        self::assertTrue($report['valid'], 'The bundle must validate: ' . implode(', ', $report['codes']));
    }

    public function testAParameterThatIsPresentButEmptyIsStillRefused(): void
    {
        $report = $this->validateWithLoadRecordFrom('');

        self::assertContains('missing_entry_record_load_record_from', $report['codes']);
        self::assertFalse($report['valid']);
    }

    public function testAValueThatIsNotAParameterNameIsStillRefused(): void
    {
        $report = $this->validateWithLoadRecordFrom('1 not-a-name');

        self::assertContains('invalid_entry_record_load_record_from', $report['codes']);
        self::assertFalse($report['valid']);
    }

    public function testAnExplicitParameterNameStillValidates(): void
    {
        $report = $this->validateWithLoadRecordFrom('entry_id');

        self::assertNotContains('missing_entry_record_load_record_from', $report['codes']);
        self::assertNotContains('invalid_entry_record_load_record_from', $report['codes']);
        self::assertTrue($report['valid'], 'The bundle must validate: ' . implode(', ', $report['codes']));
    }

    /**
     * Validate the team-members bundle after setting `load_record_from` on every
     * `entry-record` section: `null` removes the field, a string writes it.
     *
     * @return array{valid: bool, codes: list<string>}
     */
    private function validateWithLoadRecordFrom(?string $content): array
    {
        $admin = $this->loginAsQaAdmin();

        $bundlePath = ExampleBundleTestPaths::cmsInCmsBundles()['team-members'];
        self::assertFileExists($bundlePath);
        $decoded = json_decode((string) file_get_contents($bundlePath), true);
        self::assertIsArray($decoded);
        $bundle = [];
        foreach ($decoded as $key => $value) {
            $bundle[(string) $key] = $value;
        }

        $touched = 0;
        $pages = [];
        foreach (is_array($bundle['pages'] ?? null) ? $bundle['pages'] : [] as $pageKey => $page) {
            if (is_array($page)) {
                $page['sections'] = $this->withLoadRecordFrom($page['sections'] ?? null, $content, $touched);
            }
            $pages[$pageKey] = $page;
        }
        self::assertGreaterThan(0, $touched, 'The example bundle must carry an entry-record section.');
        $bundle['pages'] = $pages;

        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import/validate', [
            'bundle' => $bundle,
            'options' => [
                'keywordPrefix' => self::KEYWORD_PREFIX,
                'routePrefix' => self::ROUTE_PREFIX,
            ],
        ], $admin);
        $data = $this->assertEnvelopeSuccess($response);

        $codes = [];
        foreach (is_array($data['issues'] ?? null) ? $data['issues'] : [] as $issue) {
            if (is_array($issue) && is_string($issue['code'] ?? null) && ($issue['level'] ?? null) === 'error') {
                $codes[] = $issue['code'];
            }
        }

        return ['valid' => (bool) ($data['valid'] ?? false), 'codes' => $codes];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function withLoadRecordFrom(mixed $sections, ?string $content, int &$touched): array
    {
        $result = [];
        foreach (is_array($sections) ? $sections : [] as $key => $section) {
            if (is_array($section)) {
                if (($section['style_name'] ?? null) === 'entry-record') {
                    $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
                    if ($content === null) {
                        unset($fields['load_record_from']);
                    } else {
                        $fields['load_record_from'] = ['all' => ['content' => $content]];
                    }
                    $section['fields'] = $fields;
                    ++$touched;
                }
                if (isset($section['children'])) {
                    $section['children'] = $this->withLoadRecordFrom($section['children'], $content, $touched);
                }
            }
            $result[$key] = $section;
        }

        return $result;
    }
}
