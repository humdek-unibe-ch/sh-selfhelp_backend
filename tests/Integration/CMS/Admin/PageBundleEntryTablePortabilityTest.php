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
 * Export/import portability of the data bindings of a CMS-in-CMS app. The
 * redesigned app is one dataset with two surfaces: the CMS page holds an
 * `entry-form-list` whose inline form owns the table, the public pages read it
 * (`data-view` for the list, `entry-record` for the detail).
 *
 * Every list, data view and detail stores its binding as an install-specific
 * numeric `data_tables.id`, and the public list links to the detail through a
 * `detail_url` written against the bundle's own routes. A round trip must:
 *  1. rewrite the numeric `data_table` field to the portable
 *     `@section:<owner form section>` token on export (and carry the owner in
 *     `data_tables[]`),
 *  2. relink the token to the freshly-created owner's NEW data table id on
 *     import, so the three pages read the one table of the imported form, and
 *  3. when the import applies a route prefix, move the bundle's own
 *     cross-page links onto the prefixed URLs (otherwise a visitor's click on a
 *     row would leave the imported app).
 *
 * The app is scaffolded through the real wizard, exported and re-imported
 * through the public admin API; all pages are deleted in a finally block and
 * DAMA rolls back the surrounding transaction (Testing Rules 9/10).
 */
#[Group('golden')]
final class PageBundleEntryTablePortabilityTest extends QaWebTestCase
{
    private const BASE = 'qa-et-port';
    private const IMPORT_KEYWORD_PREFIX = 'qa2-';
    private const IMPORT_ROUTE_PREFIX = '/qa2';

    public function testDataBindingsAndLinksSurvivePrefixedRoundTrip(): void
    {
        $admin = $this->loginAsQaAdmin();

        // Scaffold the full app: the CMS page that manages the records and the public pair that shows them.
        $shell = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', [
                'name' => self::BASE . '-app',
                'slug' => self::BASE . '-app',
            ], $admin),
            201
        );
        $appId = self::coerceInt($shell['id'] ?? null);
        self::assertGreaterThan(0, $appId);

        $wizard = $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/cms-apps/%d/scaffold', $appId),
            [
                'base_name' => self::BASE,
                'create_form' => true,
                'create_public' => true,
                'create_admin' => true,
                'form_field_name' => 'title',
            ],
            $admin
        );
        $wizardData = $this->assertEnvelopeSuccess($wizard, 201);
        [$sourcePageIds, $sourceByRole] = $this->indexCreatedPages($wizardData['created'] ?? null);
        self::assertCount(3, $sourcePageIds, 'The scaffold must create the CMS list page and the public list and detail pages.');
        self::assertSame(['cms_list', 'public_detail', 'public_list'], $this->sortedKeys($sourceByRole));

        $importedPageIds = [];
        try {
            // ---- Export: the numeric data_table bindings must leave as a token.
            $export = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/export', [
                'pageIds' => $sourcePageIds,
                'options' => ['includeDataTables' => true],
            ], $admin);
            $bundle = $this->assertEnvelopeSuccess($export, 200);

            $formHolder = $this->findBundleSectionByStyle($bundle['pages'] ?? [], 'form-record');
            self::assertNotNull($formHolder, 'The exported bundle must carry the form inside the CMS list.');
            $ownerName = self::coerceString($formHolder['section_name'] ?? null);

            foreach (['entry-form-list', 'data-view', 'entry-record'] as $style) {
                $section = $this->findBundleSectionByStyle($bundle['pages'] ?? [], $style);
                self::assertNotNull($section, sprintf('The exported bundle must carry the "%s" section.', $style));
                $binding = $this->firstFieldContent($section, 'data_table');
                if ($style === 'entry-form-list') {
                    // The list reads the table of its own form: no binding to carry.
                    self::assertNull($binding, 'The CMS list needs no data_table: its inline form owns the table.');
                    continue;
                }
                self::assertSame(
                    '@section:' . $ownerName,
                    $binding,
                    sprintf('The "%s" data_table binding must be exported as the owner token of the inline form, not a numeric id.', $style)
                );
            }

            $owners = [];
            foreach (is_array($bundle['data_tables'] ?? null) ? $bundle['data_tables'] : [] as $table) {
                if (is_array($table) && is_string($table['owner_section_name'] ?? null)) {
                    $owners[] = $table['owner_section_name'];
                }
            }
            self::assertContains($ownerName, $owners, 'The table the public pages read must be exported in the data_tables[] block.');

            // ---- Import (prefixed): token relinks, links move onto the prefix.
            $import = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
                'bundle' => $bundle,
                'options' => [
                    'keywordPrefix' => self::IMPORT_KEYWORD_PREFIX,
                    'routePrefix' => self::IMPORT_ROUTE_PREFIX,
                ],
            ], $admin);
            $importData = $this->assertEnvelopeSuccess($import, 201);
            $created = is_array($importData['created'] ?? null) ? array_values($importData['created']) : [];
            foreach ($created as $entry) {
                if (is_array($entry) && is_int($entry['page_id'] ?? null)) {
                    $importedPageIds[] = $entry['page_id'];
                }
            }
            self::assertCount(3, $importedPageIds, 'The prefixed import must recreate the three pages.');

            $importedCmsListId = $this->pageIdByKeyword($created, self::IMPORT_KEYWORD_PREFIX . 'cms-' . self::BASE);
            $importedPublicListId = $this->pageIdByKeyword($created, self::IMPORT_KEYWORD_PREFIX . self::BASE);
            $importedDetailId = $this->pageIdByKeyword($created, self::IMPORT_KEYWORD_PREFIX . self::BASE . '-record');

            // The relinked binding is the NEW owner's data table: numeric, different from the source
            // app's table, owned by the form that came with the imported CMS list, and the same for
            // every page that reads it.
            $sourceBinding = $this->sectionFieldValue($sourceByRole['public_list'], 'data-view', 'data_table', $admin);
            $importedBinding = $this->sectionFieldValue($importedPublicListId, 'data-view', 'data_table', $admin);
            self::assertIsNumeric($importedBinding, 'The imported data view must be relinked to a real data table id.');
            self::assertNotSame($sourceBinding, $importedBinding, 'The imported data view must read the NEW app\'s table, not the source install\'s.');
            self::assertSame(
                $importedBinding,
                $this->sectionFieldValue($importedDetailId, 'entry-record', 'data_table', $admin),
                'The imported detail reads the table of the imported list.'
            );

            $importedFormSectionId = $this->firstSectionIdByStyle($importedCmsListId, 'form-record', $admin);
            self::assertSame(
                (string) $importedFormSectionId,
                $this->dataTableNameById((int) $importedBinding, $admin),
                'The relinked table must be owned by the form of the imported CMS list.'
            );

            // Route-prefixed in-content link: the data view's link to the detail moved onto the /qa2 base.
            self::assertSame(
                self::IMPORT_ROUTE_PREFIX . '/' . self::BASE . '/{record_id}',
                $this->sectionFieldValue($importedPublicListId, 'data-view', 'detail_url', $admin),
                'The row link must follow the route prefix (keeping its {record_id} template).'
            );

            // The source app is untouched: its link keeps the unprefixed base.
            self::assertSame(
                '/' . self::BASE . '/{record_id}',
                $this->sectionFieldValue($sourceByRole['public_list'], 'data-view', 'detail_url', $admin),
                'The source app must keep its own URLs.'
            );
        } finally {
            foreach (array_merge($sourcePageIds, $importedPageIds) as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
        }
    }

    /**
     * @param array<string, int> $byRole
     * @return list<string>
     */
    private function sortedKeys(array $byRole): array
    {
        $keys = array_keys($byRole);
        sort($keys);

        return $keys;
    }

    /**
     * @return array{0: list<int>, 1: array<string, int>}
     */
    private function indexCreatedPages(mixed $created): array
    {
        $pageIds = [];
        $byRole = [];
        foreach (is_array($created) ? $created : [] as $entry) {
            if (!is_array($entry) || !is_int($entry['page_id'] ?? null)) {
                continue;
            }
            $pageIds[] = $entry['page_id'];
            if (is_string($entry['role'] ?? null)) {
                $byRole[$entry['role']] = $entry['page_id'];
            }
        }

        return [$pageIds, $byRole];
    }

    /**
     * Find the first bundle section (recursively) with the given style.
     *
     * @param mixed $pages
     * @return array<string, mixed>|null
     */
    private function findBundleSectionByStyle(mixed $pages, string $styleName): ?array
    {
        foreach (is_array($pages) ? $pages : [] as $page) {
            if (!is_array($page)) {
                continue;
            }
            $found = $this->findInSectionList($page['sections'] ?? null, $styleName);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findInSectionList(mixed $sections, string $styleName): ?array
    {
        foreach (is_array($sections) ? $sections : [] as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['style_name'] ?? null) === $styleName) {
                return self::asArray($section);
            }
            $found = $this->findInSectionList($section['children'] ?? null, $styleName);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * First locale content of a bundle section's field
     * (`fields.<name>.<locale>.content`).
     *
     * @param array<string, mixed> $section
     */
    private function firstFieldContent(array $section, string $fieldName): ?string
    {
        $field = is_array($section['fields'] ?? null) ? ($section['fields'][$fieldName] ?? null) : null;
        foreach (is_array($field) ? $field : [] as $entry) {
            if (is_array($entry) && is_string($entry['content'] ?? null)) {
                return $entry['content'];
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $created
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
     * Resolve a section id on a page by style name (sections list endpoint).
     */
    private function firstSectionIdByStyle(int $pageId, string $styleName, string $token): int
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);
        $id = $this->findSectionIdByStyle(is_array($data['sections'] ?? null) ? $data['sections'] : [], $styleName);
        self::assertNotNull($id, sprintf('Page %d must carry a "%s" section.', $pageId, $styleName));

        return $id;
    }

    /**
     * @param array<int|string, mixed> $sections
     */
    private function findSectionIdByStyle(array $sections, string $styleName): ?int
    {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (($section['style_name'] ?? null) === $styleName && is_int($section['id'] ?? null)) {
                return $section['id'];
            }
            if (is_array($section['children'] ?? null)) {
                $found = $this->findSectionIdByStyle($section['children'], $styleName);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Read a style field's stored content from the admin section detail
     * endpoint (first translation), for the first section of the given style
     * on the page.
     */
    private function sectionFieldValue(int $pageId, string $styleName, string $fieldName, string $token): ?string
    {
        $sectionId = $this->firstSectionIdByStyle($pageId, $styleName, $token);
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

    /**
     * Resolve a data table's storage NAME from its numeric id through the data
     * tables list endpoint (the name of a form-owned table is the owning
     * section's id).
     */
    private function dataTableNameById(int $dataTableId, string $token): ?string
    {
        $resp = $this->jsonRequest('GET', '/cms-api/v1/admin/data/tables', null, $token);
        $data = $this->assertEnvelopeSuccess($resp);

        foreach (is_array($data['dataTables'] ?? null) ? $data['dataTables'] : [] as $table) {
            if (is_array($table) && is_numeric($table['id'] ?? null) && (int) $table['id'] === $dataTableId) {
                $name = $table['name'] ?? null;

                return is_scalar($name) ? (string) $name : null;
            }
        }

        return null;
    }
}
