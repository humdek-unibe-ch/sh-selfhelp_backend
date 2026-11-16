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
 * Regression guard: the columns a bundle names must relink through the imported
 * data table to the owner form section, not treat `data_tables.id` as a section
 * id (they diverge on every real import). Two shapes carry such columns:
 *
 *  - the `entry-form-list` of the shipped templates, whose `list_columns` name
 *    the inputs of the form inside it;
 *  - the older `entry-table`, whose `fields_map` names inputs of the form it is
 *    bound to by the owner token. No template uses it any more, but apps built
 *    before the form list still do, so its import path keeps its guard.
 */
#[Group('golden')]
final class PageBundleEntryTableFieldsMapImportTest extends QaWebTestCase
{
    public function testImportedFormListColumnsUseImmutableFieldKeys(): void
    {
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->teamMembersBundle();

        [$created, $createdPageIds] = $this->import($bundle, 'qa_fields_map_', '/qa-fields-map', $admin);

        try {
            // `GET /admin/pages/{id}` carries no cms_app_* keys, so the pages are
            // found by the keywords the import returns (prefix + bundle keyword).
            $cmsListPageId = $this->pageIdByKeyword($created, 'qa_fields_map_cms-team-members');

            $columns = json_decode((string) $this->sectionFieldValue($cmsListPageId, 'entry-form-list', 'list_columns', $admin), true);
            self::assertIsArray($columns);
            self::assertNotEmpty($columns);

            // The keys are those of the inputs of the form that arrived with the list.
            $inputIds = $this->inputIdsOfForm($cmsListPageId, $admin);
            self::assertGreaterThanOrEqual(3, count($inputIds), 'The team form has its inputs.');
            $fields = [];
            foreach ($columns as $column) {
                $field = self::coerceString(self::asArray($column)['field'] ?? null);
                self::assertStringStartsWith('section_', $field, 'Imported list_columns must store immutable field keys, not bundle logical names.');
                self::assertContains((int) substr($field, strlen('section_')), $inputIds, $field . ' must be an input of the imported form.');
                $fields[] = $field;
            }
            self::assertCount(count($columns), array_unique($fields), 'Each column names its own input.');
        } finally {
            $this->deletePages($createdPageIds, $admin);
        }
    }

    public function testImportedEntryTableFieldsMapUsesImmutableFieldKeys(): void
    {
        $admin = $this->loginAsQaAdmin();
        $bundle = $this->withEntryTableInPlaceOfTheList($this->teamMembersBundle());

        [$created, $createdPageIds] = $this->import($bundle, 'qa_fields_map_', '/qa-fields-map', $admin);

        try {
            $cmsListPageId = $this->pageIdByKeyword($created, 'qa_fields_map_cms-team-members');

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

            $formSectionId = $this->firstSectionIdByStyle($cmsListPageId, 'form-record', $admin);
            self::assertGreaterThan(0, $formSectionId);
            self::assertNotSame(
                $dataTableId,
                $formSectionId,
                'The regression targets the common case where data_tables.id differs from the owner section id.'
            );
        } finally {
            $this->deletePages($createdPageIds, $admin);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function teamMembersBundle(): array
    {
        $bundlePath = ExampleBundleTestPaths::cmsInCmsBundles()['team-members'];
        self::assertFileExists($bundlePath);

        $decoded = json_decode((string) file_get_contents($bundlePath), true);
        self::assertIsArray($decoded);

        return self::asArray($decoded);
    }

    /**
     * The bundle with the CMS page of an app built before the form list: an
     * `entry-table` over the table of the form, and the form next to it. The
     * table names two inputs of the form by their logical names.
     *
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function withEntryTableInPlaceOfTheList(array $bundle): array
    {
        $pages = self::asList($bundle['pages'] ?? []);
        foreach ($pages as $index => $page) {
            $page = self::asArray($page);
            if (($page['cms_app_role'] ?? null) !== 'cms_list') {
                continue;
            }
            $list = self::asArray(self::asList($page['sections'] ?? [])[0] ?? []);
            self::assertSame('entry-form-list', $list['style_name'] ?? null);
            $form = self::asArray(self::asList($list['children'] ?? [])[0] ?? []);
            self::assertSame('form-record', $form['style_name'] ?? null);

            $page['sections'] = [
                [
                    'section_name' => 'qa-fields-map-table',
                    'style_name' => 'entry-table',
                    'fields' => [
                        'data_table' => ['all' => ['content' => '@section:' . self::coerceString($form['section_name'] ?? null)]],
                        'fields_map' => ['all' => ['content' => '["name","email"]']],
                    ],
                    'children' => [],
                ],
                $form,
            ];
            $pages[$index] = $page;
        }
        $bundle['pages'] = $pages;

        return $bundle;
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array{0: list<mixed>, 1: list<int>} the `created` list of the response and the created page ids
     */
    private function import(array $bundle, string $keywordPrefix, string $routePrefix, string $token): array
    {
        $import = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => $bundle,
            'options' => [
                'keywordPrefix' => $keywordPrefix,
                'routePrefix' => $routePrefix,
                'importData' => false,
            ],
        ], $token);
        $data = $this->assertEnvelopeSuccess($import, 201);

        $created = is_array($data['created'] ?? null) ? array_values($data['created']) : [];
        $createdPageIds = [];
        foreach ($created as $entry) {
            if (is_array($entry) && is_int($entry['page_id'] ?? null)) {
                $createdPageIds[] = $entry['page_id'];
            }
        }
        self::assertNotEmpty($createdPageIds);

        return [$created, $createdPageIds];
    }

    /**
     * @param list<int> $pageIds
     */
    private function deletePages(array $pageIds, string $token): void
    {
        foreach ($pageIds as $pageId) {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $token);
        }
    }

    /**
     * @param list<mixed> $created the `created` list of the import response
     */
    private function pageIdByKeyword(array $created, string $keyword): int
    {
        foreach ($created as $entry) {
            if (is_array($entry) && ($entry['keyword'] ?? null) === $keyword && is_int($entry['page_id'] ?? null)) {
                return $entry['page_id'];
            }
        }
        self::fail(sprintf('Imported page "%s" not found in the import result.', $keyword));
    }

    /**
     * The ids of the inputs inside the form of a page.
     *
     * @return list<int>
     */
    private function inputIdsOfForm(int $pageId, string $token): array
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);

        $ids = [];
        $walk = function ($nodes, bool $insideForm) use (&$walk, &$ids): void {
            foreach (is_array($nodes) ? $nodes : [] as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $style = $node['style_name'] ?? null;
                if ($insideForm && is_numeric($node['id'] ?? null)) {
                    $ids[] = (int) $node['id'];
                }
                $walk($node['children'] ?? null, $insideForm || $style === 'form-record');
            }
        };
        $walk($data['sections'] ?? $data, false);

        return $ids;
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
