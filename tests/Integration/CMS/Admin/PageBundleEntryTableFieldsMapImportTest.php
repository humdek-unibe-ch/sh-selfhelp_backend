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
 * Regression guard: entry-table `fields_map` must relink through the imported
 * data table id to the owner form section, not treat `data_tables.id` as a
 * section id (they diverge on every real import).
 */
#[Group('golden')]
final class PageBundleEntryTableFieldsMapImportTest extends QaWebTestCase
{
    public function testImportedEntryTableFieldsMapUsesImmutableFieldKeys(): void
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

        $import = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => [
                'keywordPrefix' => 'qa_fields_map_',
                'routePrefix' => '/qa-fields-map',
                'importData' => false,
            ],
        ], $admin);
        $data = $this->assertEnvelopeSuccess($import, 201);

        $createdPageIds = [];
        foreach (is_array($data['created'] ?? null) ? $data['created'] : [] as $entry) {
            if (is_array($entry) && is_int($entry['page_id'] ?? null)) {
                $createdPageIds[] = $entry['page_id'];
            }
        }
        self::assertNotEmpty($createdPageIds);

        try {
            $cmsListPageId = null;
            foreach ($createdPageIds as $pageId) {
                $page = $this->assertEnvelopeSuccess(
                    $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin)
                );
                if (($page['cms_app_role'] ?? null) === 'cms_list') {
                    $cmsListPageId = $pageId;
                    break;
                }
            }
            self::assertNotNull($cmsListPageId);

            $entryTableSectionId = $this->firstSectionIdByStyle($cmsListPageId, 'entry-table', $admin);
            self::assertGreaterThan(0, $entryTableSectionId);

            $dataTableId = (int) ($this->sectionFieldValue($cmsListPageId, 'entry-table', 'data_table', $admin) ?? '0');
            self::assertGreaterThan(0, $dataTableId);

            $fieldsMapRaw = (string) ($this->sectionFieldValue($cmsListPageId, 'entry-table', 'fields_map', $admin) ?? '');
            self::assertNotSame('', $fieldsMapRaw);

            $fieldsMap = json_decode($fieldsMapRaw, true);
            self::assertIsArray($fieldsMap);
            self::assertNotEmpty($fieldsMap);

            foreach ($fieldsMap as $fieldKey) {
                self::assertIsString($fieldKey);
                self::assertStringStartsWith(
                    'section_',
                    $fieldKey,
                    'Imported fields_map must store immutable field keys, not bundle logical names.'
                );
            }

            self::assertNotContains('name', $fieldsMap);
            self::assertNotContains('email', $fieldsMap);

            $formPageId = null;
            foreach ($createdPageIds as $pageId) {
                $page = $this->assertEnvelopeSuccess(
                    $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin)
                );
                if (($page['cms_app_role'] ?? null) === 'form') {
                    $formPageId = $pageId;
                    break;
                }
            }
            self::assertNotNull($formPageId);

            $formSectionId = $this->firstSectionIdByStyle($formPageId, 'form-record', $admin);
            self::assertGreaterThan(0, $formSectionId);
            self::assertNotSame(
                $dataTableId,
                $formSectionId,
                'The regression targets the common case where data_tables.id differs from the owner section id.'
            );
        } finally {
            foreach ($createdPageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    private function firstSectionIdByStyle(int $pageId, string $styleName, string $token): int
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);

        $found = 0;
        $walk = function ($nodes) use (&$walk, &$found, $styleName): void {
            if (!is_array($nodes)) {
                return;
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $style = is_array($node['style'] ?? null) ? ($node['style']['name'] ?? null) : ($node['style_name'] ?? null);
                if ($style === $styleName && is_numeric($node['id'] ?? null)) {
                    $found = (int) $node['id'];

                    return;
                }
                if (isset($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $walk($data['sections'] ?? $data);

        return $found;
    }

    private function sectionFieldValue(int $pageId, string $styleName, string $fieldName, string $token): ?string
    {
        $sectionId = $this->firstSectionIdByStyle($pageId, $styleName, $token);
        self::assertGreaterThan(0, $sectionId);

        $detail = $this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $pageId, $sectionId),
            null,
            $token
        );
        $data = $this->assertEnvelopeSuccess($detail);

        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $field) {
            if (!is_array($field) || ($field['name'] ?? null) !== $fieldName) {
                continue;
            }
            foreach (is_array($field['translations'] ?? null) ? $field['translations'] : [] as $translation) {
                if (is_array($translation) && is_string($translation['content'] ?? null) && $translation['content'] !== '') {
                    return $translation['content'];
                }
            }
        }

        return null;
    }
}
