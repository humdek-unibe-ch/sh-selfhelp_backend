<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\CMS;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
     * @return iterable<string, array{0: string}>
     */
    public static function bundleProvider(): iterable
    {
        foreach (['team-members', 'news', 'events', 'faq', 'contact-directory', 'testimonials'] as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('bundleProvider')]
    public function testTemplateUsesStableCodesTranslatedLabelsAndUnderscoredRuntimeTokens(string $id): void
    {
        $backendRoot = dirname(__DIR__, 3);
        $frontendPath = dirname($backendRoot) . '/sh-selfhelp_frontend/examples/cms-in-cms/' . $id . '.bundle.json';
        $fixturePath = $backendRoot . '/tests/fixtures/examples/' . $id . '.bundle.json';

        self::assertFileExists($frontendPath);
        self::assertFileExists($fixturePath);
        $frontendJson = file_get_contents($frontendPath);
        $fixtureJson = file_get_contents($fixturePath);
        self::assertIsString($frontendJson);
        self::assertSame($frontendJson, $fixtureJson, 'Frontend example and backend fixture mirror must be byte-identical.');

        $bundle = json_decode($frontendJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($bundle);
        $optionSections = $this->optionSections($bundle['pages'] ?? []);
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

            $isMultiple = ($this->fieldContentOptional($fields, 'is_multiple', 'all') ?? '') === '1'
                || ($this->fieldContentOptional($fields, 'web_combobox_multi_select', 'all') ?? '') === '1';
            $runtimeToken = $isMultiple
                ? '{{_' . $fieldName . '_labels}}'
                : '{{_' . $fieldName . '_label}}';
            self::assertStringContainsString($runtimeToken, $frontendJson);

            $labelColumn = $isMultiple
                ? '_' . $fieldName . '_labels'
                : '_' . $fieldName . '_label';
            self::assertStringContainsString(
                $labelColumn,
                $frontendJson,
                sprintf('%s CMS entry-table must display hydrated labels via fields_map.', $id),
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
