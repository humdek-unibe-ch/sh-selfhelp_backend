<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\CMS;

use App\Tests\Support\ExampleBundleTestPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stored data holds option codes; translated labels are added while a page
 * renders. Every option input of a shipped template therefore ships stable
 * codes with a label per locale, and its sample rows store codes.
 *
 * Two public shapes exist. A composition (`entry-list`, `entry-record`) reads the
 * label of a code through the hydrated token `{{scope._field_label}}`. A data
 * view shows the stored value as it is, so a template whose data view shows a
 * field uses free text for it, not a code; a field that only the managers see
 * (publish status on news/team-members, or the status of a testimonial) may stay
 * an option input without a public composition token.
 */
final class CmsInCmsEnumBundleContractTest extends TestCase
{
    /** @var array<string, string> */
    private const CATALOG_FIELDS = [
        'select' => 'options',
        'radio' => 'radio_options',
        'combobox' => 'combobox_options',
        'segmented-control' => 'segmented_control_data',
    ];

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: bool}> template id, has option inputs, a composition renders their labels
     */
    public static function bundleProvider(): iterable
    {
        yield 'team-members' => ['team-members', true, true];
        yield 'news' => ['news', true, true];
        yield 'events' => ['events', true, true];
        yield 'faq' => ['faq', true, true];
        // The directory shows department and role as the free text they are: a data view prints stored values.
        yield 'contact-directory' => ['contact-directory', false, false];
        // The status of a testimonial is moderation data: only the managers see it.
        yield 'testimonials' => ['testimonials', true, false];
    }

    #[DataProvider('bundleProvider')]
    public function testTemplateUsesStableCodesTranslatedLabelsAndUnderscoredRuntimeTokens(string $id, bool $hasOptionInputs, bool $compositionRendersLabels): void
    {
        $backendRoot = dirname(__DIR__, 3);
        $frontendPath = dirname($backendRoot) . '/sh-selfhelp_frontend/examples/cms-in-cms/' . $id . '.bundle.json';
        $fixturePath = $backendRoot . '/tests/fixtures/examples/' . $id . '.bundle.json';

        self::assertFileExists($fixturePath);

        // When the frontend sibling is checked out (local monorepo), keep the
        // gallery example and backend fixture byte-identical. CI only has the
        // backend checkout, so fall back to the fixture via ExampleBundleTestPaths.
        if (is_file($frontendPath)) {
            $frontendJson = file_get_contents($frontendPath);
            $fixtureJson = file_get_contents($fixturePath);
            self::assertIsString($frontendJson);
            self::assertIsString($fixtureJson);
            self::assertSame($frontendJson, $fixtureJson, 'Frontend example and backend fixture mirror must be byte-identical.');
            $bundleJson = $frontendJson;
        } else {
            $resolved = ExampleBundleTestPaths::cmsInCmsBundles()[$id] ?? null;
            self::assertNotNull($resolved);
            self::assertFileExists($resolved);
            $bundleJson = file_get_contents($resolved);
            self::assertIsString($bundleJson);
        }

        $bundle = json_decode($bundleJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($bundle);
        $optionSections = $this->optionSections($bundle['pages'] ?? []);
        if (!$hasOptionInputs) {
            self::assertSame([], $optionSections, sprintf('%s shows its values as free text; an option input would print raw codes.', $id));

            return;
        }
        self::assertNotSame([], $optionSections, sprintf('%s must exercise the option-label contract.', $id));

        $dataTables = $bundle['data_tables'] ?? null;
        self::assertIsArray($dataTables);
        $dataTable = $dataTables[0] ?? null;
        self::assertIsArray($dataTable);
        $rows = $dataTable['rows'] ?? null;
        self::assertIsArray($rows);
        foreach ($optionSections as $section) {
            $styleName = $section['style_name'];
            $catalogField = self::CATALOG_FIELDS[$styleName];
            $fields = $section['fields'];
            $fieldName = $this->fieldContent($fields, 'name', 'all');

            $catalogRaw = $this->fieldContent($fields, $catalogField, 'all');
            $catalog = json_decode($catalogRaw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($catalog);
            $codes = [];
            foreach ($catalog as $option) {
                self::assertIsArray($option);
                self::assertArrayHasKey('value', $option);
                self::assertIsString($option['value']);
                self::assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $option['value']);
                self::assertArrayNotHasKey('text', $option);
                self::assertArrayNotHasKey('label', $option);
                $codes[] = $option['value'];
            }

            foreach (['de-CH', 'en-GB'] as $locale) {
                $labelsRaw = $this->fieldContent($fields, 'option_labels', $locale);
                $labels = json_decode($labelsRaw, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($labels);
                foreach ($codes as $code) {
                    self::assertArrayHasKey($code, $labels);
                    self::assertIsString($labels[$code]);
                    self::assertNotSame('', trim($labels[$code]));
                }
            }

            foreach ($rows as $row) {
                if (!is_array($row) || !array_key_exists($fieldName, $row)) {
                    continue;
                }
                self::assertIsString($row[$fieldName]);
                foreach (array_map('trim', explode(',', $row[$fieldName])) as $storedCode) {
                    self::assertContains($storedCode, $codes);
                }
            }

            if (!$compositionRendersLabels) {
                continue;
            }

            $isMultiple = ($this->fieldContentOptional($fields, 'is_multiple', 'all') ?? '') === '1'
                || ($this->fieldContentOptional($fields, 'web_combobox_multi_select', 'all') ?? '') === '1';
            $labelSuffix = $isMultiple ? '_labels' : '_label';
            $labelColumn = '_' . $fieldName . $labelSuffix;
            // Accept unscoped `{{_role_label}}` or scoped `{{team_member._role_label}}`.
            $labelTokenPattern = '/\{\{(?:[A-Za-z0-9_]+\.)?' . preg_quote($labelColumn, '/') . '\}\}/';
            $rawTokenPattern = '/\{\{(?:[A-Za-z0-9_]+\.)?' . preg_quote($fieldName, '/') . '\}\}/';
            // Publish/moderation fields (draft/published) often stay on the CMS list
            // and filters only; they need codes + labels, not a public composition token.
            if (
                preg_match($labelTokenPattern, $bundleJson) !== 1
                && preg_match($rawTokenPattern, $bundleJson) !== 1
            ) {
                continue;
            }
            self::assertMatchesRegularExpression(
                $labelTokenPattern,
                $bundleJson,
                sprintf('%s must interpolate hydrated option labels via %s.', $id, $labelColumn),
            );
            self::assertStringContainsString(
                $labelColumn,
                $bundleJson,
                sprintf('%s must read the hydrated label column %s.', $id, $labelColumn),
            );
        }
    }

    /**
     * @param mixed $pages
     * @return list<array{style_name: string, fields: array<string, mixed>}>
     */
    private function optionSections(mixed $pages): array
    {
        if (!is_array($pages)) {
            return [];
        }

        $result = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $result = [...$result, ...$this->walkSections($page['sections'] ?? [])];
        }

        return $result;
    }

    /**
     * @param mixed $sections
     * @return list<array{style_name: string, fields: array<string, mixed>}>
     */
    private function walkSections(mixed $sections): array
    {
        if (!is_array($sections)) {
            return [];
        }

        $result = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $styleName = $section['style_name'] ?? null;
            $fields = $section['fields'] ?? null;
            if (is_string($styleName) && isset(self::CATALOG_FIELDS[$styleName]) && is_array($fields)) {
                $normalizedFields = [];
                foreach ($fields as $fieldName => $fieldValue) {
                    if (is_string($fieldName)) {
                        $normalizedFields[$fieldName] = $fieldValue;
                    }
                }
                $result[] = [
                    'style_name' => $styleName,
                    'fields' => $normalizedFields,
                ];
            }
            $result = [...$result, ...$this->walkSections($section['children'] ?? [])];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function fieldContent(array $fields, string $fieldName, string $languageCode): string
    {
        $content = $this->fieldContentOptional($fields, $fieldName, $languageCode);
        self::assertIsString($content);

        return $content;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function fieldContentOptional(array $fields, string $fieldName, string $languageCode): ?string
    {
        $field = $fields[$fieldName] ?? null;
        if (!is_array($field)) {
            return null;
        }
        $translation = $field[$languageCode] ?? null;
        if (!is_array($translation)) {
            return null;
        }
        $content = $translation['content'] ?? null;

        return is_string($content) ? $content : null;
    }
}
