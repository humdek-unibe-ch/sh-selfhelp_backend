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
 * Export/import portability of the CMS-in-CMS admin grid (issue #30 polish
 * wave): the `entry-table` style stores its binding as an install-specific
 * numeric `data_tables.id`, and every in-content URL (entry-table `add_url` /
 * `edit_url`, card link `url`) is written against the bundle's own routes.
 *
 * A round-trip must therefore:
 *  1. rewrite the numeric `data_table` field to the portable
 *     `@section:<owner form section>` token on export (and carry the owner in
 *     `data_tables[]`),
 *  2. relink the token to the freshly-created owner's NEW data table id on
 *     import, and
 *  3. when the import applies a route prefix, move the bundle's own
 *     cross-page links onto the prefixed URLs (otherwise "Add new" / per-row
 *     edit / card "Open" would point at the unprefixed originals).
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

    public function testEntryTableBindingAndLinksSurvivePrefixedRoundTrip(): void
    {
        $admin = $this->loginAsQaAdmin();

        // Scaffold the full five-page app (form + cms pair + public pair).
        $shell = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', [
                'name' => self::BASE . '-app',
                'slug' => self::BASE . '-app',
            ], $admin),
            201
        );
        $appId = (int) ($shell['id'] ?? 0);
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
        self::assertCount(5, $sourcePageIds, 'The scaffold must create five pages.');

        $importedPageIds = [];
        try {
            // ---- Export: the numeric data_table binding must leave as a token.
            $export = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/export', [
                'pageIds' => $sourcePageIds,
                'options' => ['includeDataTables' => true],
            ], $admin);
            $bundle = $this->assertEnvelopeSuccess($export, 200);

            $entryTable = $this->findBundleSectionByStyle($bundle['pages'] ?? [], 'entry-table');
            self::assertNotNull($entryTable, 'The exported bundle must carry the admin entry-table section.');

            $exportedBinding = $this->firstFieldContent($entryTable, 'data_table');
            self::assertIsString($exportedBinding);
            self::assertStringStartsWith(
                '@section:',
                $exportedBinding,
                'The entry-table data_table binding must be exported as a portable owner token, not a numeric id.'
            );
            $ownerName = substr($exportedBinding, strlen('@section:'));

            $formHolder = $this->findBundleSectionByStyle($bundle['pages'] ?? [], 'form-record');
            self::assertNotNull($formHolder, 'The exported bundle must carry the form-record holder.');
            self::assertSame(
                $formHolder['section_name'] ?? null,
                $ownerName,
                'The token must name the form section that owns the data table.'
            );

            $dataTables = is_array($bundle['data_tables'] ?? null) ? $bundle['data_tables'] : [];
            $owners = [];
            foreach ($dataTables as $table) {
                if (is_array($table) && is_string($table['owner_section_name'] ?? null)) {
                    $owners[] = $table['owner_section_name'];
                }
            }
            self::assertContains(
                $ownerName,
                $owners,
                'The entry-table-referenced table must be exported in the data_tables[] block.'
            );

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
            self::assertCount(5, $importedPageIds, 'The prefixed import must recreate all five pages.');

            $importedAdminListId = $this->pageIdByKeyword($created, self::IMPORT_KEYWORD_PREFIX . 'cms-' . self::BASE);
            $importedPublicListId = $this->pageIdByKeyword($created, self::IMPORT_KEYWORD_PREFIX . self::BASE);

            // The relinked binding is the NEW owner's data table: numeric, and
            // different from the source app's table id.
            $sourceBinding = $this->sectionFieldValue($sourceByRole['cms_list'], 'entry-table', 'data_table', $admin);
            $importedBinding = $this->sectionFieldValue($importedAdminListId, 'entry-table', 'data_table', $admin);
            self::assertIsNumeric($importedBinding, 'The imported entry-table must be relinked to a real data table id.');
            self::assertNotSame(
                $sourceBinding,
                $importedBinding,
                'The imported entry-table must bind to the NEW app\'s table, not the source install\'s.'
            );

            // The new binding points at a table owned by an IMPORTED form
            // section (table name == owner section id). The wizard reuses one
            // form section on two pages; the bundle format embeds sections per
            // page, so the import materializes one copy per page — the relink
            // may bind to either copy. Collect every imported form-record
            // section id across the app's pages as valid owners.
            $importedFormSectionIds = [];
            foreach ($importedPageIds as $importedPageId) {
                $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $importedPageId), null, $admin);
                $data = $this->assertEnvelopeSuccess($resp);
                $formSectionId = $this->findSectionIdByStyle(
                    is_array($data['sections'] ?? null) ? $data['sections'] : [],
                    'form-record'
                );
                if ($formSectionId !== null) {
                    $importedFormSectionIds[] = (string) $formSectionId;
                }
            }
            self::assertNotEmpty($importedFormSectionIds, 'The imported app must contain form-record sections.');
            $newTableName = $this->dataTableNameById((int) $importedBinding, $admin);
            self::assertContains(
                $newTableName,
                $importedFormSectionIds,
                'The relinked table must be owned by one of the imported form sections.'
            );

            // Route-prefixed in-content links: entry-table add/edit URLs and the
            // public card link all moved onto the /qa2 base.
            $addUrl = $this->sectionFieldValue($importedAdminListId, 'entry-table', 'add_url', $admin);
            self::assertSame(
                self::IMPORT_ROUTE_PREFIX . '/cms/' . self::BASE . '/form',
                $addUrl,
                'The "Add new" URL must follow the route prefix.'
            );
            $editUrl = $this->sectionFieldValue($importedAdminListId, 'entry-table', 'edit_url', $admin);
            self::assertSame(
                self::IMPORT_ROUTE_PREFIX . '/cms/' . self::BASE . '/{record_id}',
                $editUrl,
                'The per-row edit URL must follow the route prefix (keeping its {record_id} template).'
            );
            $cardLink = $this->sectionFieldValue($importedPublicListId, 'link', 'url', $admin);
            self::assertSame(
                self::IMPORT_ROUTE_PREFIX . '/' . self::BASE . '/{{record_id}}',
                $cardLink,
                'The public card "Open" link must follow the route prefix (keeping its {{record_id}} token).'
            );

            // The source app is untouched: its links keep the unprefixed base.
            $sourceAddUrl = $this->sectionFieldValue($sourceByRole['cms_list'], 'entry-table', 'add_url', $admin);
            self::assertSame('/cms/' . self::BASE . '/form', $sourceAddUrl, 'The source app must keep its own URLs.');
        } finally {
            foreach (array_merge($sourcePageIds, $importedPageIds) as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
        }
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
                /** @var array<string, mixed> $section */
                return $section;
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
