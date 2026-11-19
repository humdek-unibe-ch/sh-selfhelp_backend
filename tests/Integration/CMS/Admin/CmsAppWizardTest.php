<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\Entity\Page;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataQuery\DataFilter;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQuery;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\FormFieldKeyResolver;
use App\Service\Core\LookupService;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * End-to-end coverage for first-class CMS app scaffold
 * (`POST /admin/cms-apps` + `POST /admin/cms-apps/{id}/scaffold`):
 *
 * - empty app shell, then scaffold with `create_form`
 * - one CMS entry list that holds the form (roles: cms_list) and a read-only public
 *   data view with its detail page (roles: public_list, public_detail)
 * - both surfaces read the one data table the form owns; the inputs are defined once
 * - records are added, edited and deleted through the list and the public view follows
 */
#[Group('golden')]
final class CmsAppWizardTest extends QaWebTestCase
{
    private const BASE = 'qa-team-wizard';
    private const MULTI_BASE = 'qa-team-wizard-multi';

    public function testWizardScaffoldsOneCmsListWithItsFormAndAReadOnlyPublicView(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, $created, $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => self::BASE,
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'form_field_name' => 'title',
        ], 'qa-team-wizard-app');

        try {
            self::assertCount(3, $created, 'One CMS list and the public list/detail pair: no form page, no CMS detail page.');
            $roles = array_keys($byRole);
            sort($roles);
            self::assertSame(['cms_list', 'public_detail', 'public_list'], $roles);

            // CMS surface: ONE entry-form-list that is the form (inputs sit directly inside it).
            $cmsBody = $this->sectionsBody($byRole['cms_list'], $admin);
            self::assertStringContainsString('entry-form-list', $cmsBody);
            self::assertStringNotContainsString('form-record', $cmsBody);
            self::assertStringContainsString('text-input', $cmsBody);
            self::assertStringNotContainsString('entry-table', $cmsBody);
            self::assertStringNotContainsString('entry-record-form', $cmsBody);
            $cmsField = fn (string $field): ?string => $this->sectionFieldValue($byRole['cms_list'], 'entry-form-list', $field, $admin);
            self::assertSame('qa_team_wizard', $cmsField('list_key'));
            self::assertSame('table', $cmsField('list_layout'));
            self::assertSame('modal', $cmsField('form_presentation'));
            self::assertSame('0', $cmsField('own_entries_only'));
            self::assertSame('1', $cmsField('allow_create'));
            self::assertSame('1', $cmsField('allow_update'));
            self::assertSame('1', $cmsField('allow_delete'));
            self::assertSame('1', $cmsField('show_history'));
            self::assertNull($cmsField('data_table'), 'The list owns its table; it names no foreign data_table.');
            $cmsColumns = $this->jsonField($byRole['cms_list'], 'entry-form-list', 'list_columns', $admin);
            self::assertCount(1, $cmsColumns);
            self::assertStringStartsWith('section_', self::coerceString($cmsColumns[0]['field'] ?? null), 'The import turned the input name into the key of the new input.');
            self::assertSame(true, $cmsColumns[0]['searchable'] ?? null);

            // Public surface: a read-only data view of the SAME table; nothing of the form is repeated.
            $publicBody = $this->sectionsBody($byRole['public_list'], $admin);
            self::assertStringContainsString('data-view', $publicBody);
            foreach (['entry-form-list', 'form-record', 'text-input', 'entry-table', 'entry-list'] as $style) {
                self::assertStringNotContainsString($style, $publicBody, sprintf('The public list must not repeat "%s".', $style));
            }
            $publicField = fn (string $field): ?string => $this->sectionFieldValue($byRole['public_list'], 'data-view', $field, $admin);
            self::assertSame('cards', $publicField('list_layout'));
            self::assertSame('/' . self::BASE . '/{record_id}', $publicField('detail_url'));
            self::assertSame('0', $publicField('own_entries_only'));

            $formSectionId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            self::assertGreaterThan(0, $formSectionId);
            $tableId = self::coerceInt($this->service(EntityManagerInterface::class)->getConnection()->fetchOne(
                'SELECT id FROM data_tables WHERE name = :name',
                ['name' => (string) $formSectionId],
            ));
            self::assertGreaterThan(0, $tableId, 'The list owns a data table named after its section.');
            self::assertSame((string) $tableId, $publicField('data_table'), 'The public view reads the table of the list.');
            self::assertSame(
                (string) $tableId,
                $this->sectionFieldValue($byRole['public_detail'], 'entry-record', 'data_table', $admin),
                'The public detail reads the same table.'
            );
            self::assertSame(
                self::coerceString($cmsColumns[0]['field'] ?? null),
                $this->jsonField($byRole['public_list'], 'data-view', 'list_columns', $admin)[0]['field'] ?? null,
                'Both lists name the column by the key of the one input.'
            );

            // The public detail is a normal full page.
            self::assertNull($this->pageProperty($byRole['public_detail'], 'open_in_modal', $admin));
            self::assertSame(
                'record_id',
                $this->sectionFieldValue($byRole['public_detail'], 'entry-record', 'load_record_from', $admin)
            );
            self::assertNull($this->sectionFieldValue($byRole['public_detail'], 'entry-record', 'filter', $admin));
            self::assertNull($this->sectionFieldValue($byRole['public_detail'], 'entry-record', 'url_param', $admin));

            // Hub sync: the form of the app is the CMS list itself.
            $appDetail = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            self::assertSame($byRole['cms_list'], $appDetail['id_cms_list_page'] ?? null);
            self::assertSame($byRole['public_list'], $appDetail['id_public_list_page'] ?? null);
            self::assertSame($byRole['public_detail'], $appDetail['id_public_detail_page'] ?? null);
            self::assertSame($formSectionId, $appDetail['id_form_section'] ?? null);
            self::assertNull($appDetail['id_cms_detail_page'] ?? null);
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testWizardWithStatusAndImageFields(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, $created, $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => 'qa-media-status',
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'with_status' => true,
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'label' => 'Title'],
                ['name' => 'portrait', 'style' => 'image', 'label' => 'Portrait'],
                ['name' => 'attachments', 'style' => 'files', 'label' => 'Files'],
            ],
        ], 'qa-media-status-app');

        try {
            self::assertCount(3, $created);
            $cmsBody = $this->sectionsBody($byRole['cms_list'], $admin);
            self::assertStringContainsString('file-input', $cmsBody);
            self::assertStringContainsString('status', $cmsBody);
            $actions = (string) $this->sectionFieldValue($byRole['cms_list'], 'entry-form-list', 'quick_actions', $admin);
            self::assertStringContainsString('publish', $actions);
            self::assertStringContainsString('unpublish', $actions);
            $columns = $this->jsonField($byRole['cms_list'], 'entry-form-list', 'list_columns', $admin);
            $formats = array_map(static fn (array $c): mixed => $c['format'] ?? null, $columns);
            self::assertContains('image', $formats);
            self::assertContains('file', $formats);
            self::assertContains('badge', $formats);
            $publicFilter = (string) $this->sectionFieldValue($byRole['public_list'], 'data-view', 'list_fixed_filter', $admin);
            self::assertStringContainsString('published', $publicFilter);
            $detailFilter = (string) $this->sectionFieldValue($byRole['public_detail'], 'entry-record', 'fixed_filter', $admin);
            self::assertStringContainsString('published', $detailFilter);
            self::assertStringContainsString('"operator":"eq"', $detailFilter);
            $imgSrc = (string) $this->sectionFieldValue($byRole['public_detail'], 'image', 'img_src', $admin);
            self::assertStringContainsString('__url', $imgSrc);
            $downloadsId = $this->sectionIdByNameContains($byRole['public_detail'], 'detail-downloads', $admin);
            self::assertGreaterThan(0, $downloadsId);
            $downloadsText = (string) $this->fieldContentOf($byRole['public_detail'], $downloadsId, 'text', $admin);
            self::assertStringContainsString('__links', $downloadsText);
            self::assertStringNotContainsString('__files', $downloadsText);
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    /**
     * F169: a form field the scaffold declares (status) has no `data_cols` row
     * until the first save that holds it. The public data-view still filters on
     * status; the list must answer 200 and treat the field as empty — never
     * `Unknown column`. An undeclared name stays refused.
     */
    public function testDeclaredUnsavedStatusReadsAsEmptyOnPublicList(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => 'qa-f169-empty',
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'with_status' => true,
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'label' => 'Title'],
            ],
        ], 'qa-f169-empty-app');

        try {
            $em = $this->service(EntityManagerInterface::class);
            $public = $em->find(Page::class, $byRole['public_list']);
            self::assertInstanceOf(Page::class, $public);
            $public->setIsOpenAccess(true);
            $em->flush();

            $viewId = $this->firstSectionIdByStyle($byRole['public_list'], 'data-view', $admin);
            $listId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            self::assertGreaterThan(0, $viewId);
            self::assertGreaterThan(0, $listId);

            $stateTotal = static function (array $payload): int {
                $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];

                return self::coerceInt($pagination['total'] ?? 0);
            };

            // No record saved yet: status has no data_cols row, but the fixed
            // filter names it. Must not be list_error / Unknown column.
            $emptyPublic = $this->assertEnvelopeSuccess(
                $this->listState($viewId, $byRole['public_list'], $admin),
            );
            self::assertSame(0, $stateTotal($emptyPublic));

            // CMS list projects status and can sort by its field key before any save.
            $listKey = (string) $this->sectionFieldValue($byRole['cms_list'], 'entry-form-list', 'list_key', $admin);
            $columns = $this->jsonField($byRole['cms_list'], 'entry-form-list', 'list_columns', $admin);
            $statusField = null;
            foreach ($columns as $column) {
                if (($column['format'] ?? null) === 'badge' || str_contains(strtolower(self::coerceString($column['label'] ?? null)), 'status')) {
                    $statusField = self::coerceString($column['field'] ?? null);
                    break;
                }
            }
            self::assertNotNull($statusField, 'Scaffold must expose a status column.');
            $cmsEmpty = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                sprintf(
                    '/cms-api/v1/lists/%d/state?page_id=%d&%s.sort=%s%%3Aasc',
                    $listId,
                    $byRole['cms_list'],
                    rawurlencode($listKey),
                    rawurlencode($statusField),
                ),
                null,
                $admin,
            ));
            self::assertSame(0, $stateTotal($cmsEmpty));
            $statusColumnFound = false;
            foreach (self::asList($cmsEmpty['columns'] ?? []) as $column) {
                $column = self::asArray($column);
                if (($column['field'] ?? null) === $statusField) {
                    $statusColumnFound = true;
                    break;
                }
            }
            self::assertTrue($statusColumnFound, 'CMS list must return the declared status column before any save.');

            // Submit only title — status may still lack a cell; public eq published matches nothing.
            $formId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $created = $this->assertEnvelopeSuccess($this->jsonRequest(
                'POST',
                '/cms-api/v1/forms/submit',
                [
                    'page_id' => $byRole['cms_list'],
                    'section_id' => $formId,
                    'form_data' => ['title' => 'Only title'],
                ],
                $admin,
            ));
            $draftId = self::coerceInt($created['record_id'] ?? null);

            $after = $this->assertEnvelopeSuccess($this->listState($viewId, $byRole['public_list'], $admin));
            self::assertSame(0, $stateTotal($after), 'Empty/draft status is not published.');

            // CMS list: never-saved / empty status cell is empty; neq published and is empty match it.
            $cmsAfter = $this->assertEnvelopeSuccess($this->listState($listId, $byRole['cms_list'], $admin));
            $cmsRows = self::asList($cmsAfter['rows'] ?? []);
            self::assertNotEmpty($cmsRows);
            $firstValues = self::asArray(self::asArray($cmsRows[0])['values'] ?? null);
            self::assertArrayHasKey($statusField, $firstValues, 'Status column must be projected before any save.');
            $statusCell = $firstValues[$statusField];
            self::assertTrue(
                $statusCell === null || $statusCell === '' || $statusCell === [],
                'Status cell must read as empty before it is saved: ' . json_encode($statusCell),
            );

            $tableId = self::coerceInt($em->getConnection()->fetchOne(
                'SELECT id FROM data_tables WHERE name = :name',
                ['name' => (string) $formId],
            ));
            self::assertGreaterThan(0, $tableId);
            $optional = [];
            foreach (array_keys($this->service(FormFieldKeyResolver::class)->getFieldKeyToName((string) $formId)) as $key) {
                $optional[$key] = LookupService::DATA_COL_VALUE_TYPES_TEXT;
            }
            $engine = $this->service(DataQueryEngine::class);
            $neqIds = array_map(
                static fn (array $row): int => self::coerceInt($row['record_id'] ?? null),
                $engine->run(new DataQuery(
                    tableId: $tableId,
                    filter: new DataFilterGroup(DataFilterGroup::MODE_AND, [
                        new DataFilter($statusField, DataFilter::OP_NEQ, 'published'),
                    ]),
                    limit: null,
                    columns: [],
                    optionalColumns: $optional,
                ))->rows,
            );
            self::assertContains($draftId, $neqIds, 'neq published must match the unsaved-status row.');
            $emptyIds = array_map(
                static fn (array $row): int => self::coerceInt($row['record_id'] ?? null),
                $engine->run(new DataQuery(
                    tableId: $tableId,
                    filter: new DataFilterGroup(DataFilterGroup::MODE_AND, [
                        new DataFilter($statusField, DataFilter::OP_EMPTY, null),
                    ]),
                    limit: null,
                    columns: [],
                    optionalColumns: $optional,
                ))->rows,
            );
            self::assertContains($draftId, $emptyIds, 'is empty must match the never-saved status cell.');

            // Publish one record: public list returns exactly that row; the other stays out.
            $second = $this->assertEnvelopeSuccess($this->jsonRequest(
                'POST',
                '/cms-api/v1/forms/submit',
                [
                    'page_id' => $byRole['cms_list'],
                    'section_id' => $formId,
                    'form_data' => ['title' => 'Published one', 'status' => 'published'],
                ],
                $admin,
            ));
            $publishedId = self::coerceInt($second['record_id'] ?? null);
            $publicLive = $this->assertEnvelopeSuccess($this->listState($viewId, $byRole['public_list'], $admin));
            self::assertSame([$publishedId], $this->recordIds($publicLive), 'Public list must show only the published record.');

            // Undeclared sort column is refused (not silently accepted).
            $bad = $this->jsonRequest(
                'GET',
                sprintf(
                    '/cms-api/v1/lists/%d/state?page_id=%d&%s.sort=no_such_field%%3Aasc',
                    $listId,
                    $byRole['cms_list'],
                    rawurlencode($listKey),
                ),
                null,
                $admin,
            );
            self::assertSame(400, $bad['status'] ?? 0, (string) json_encode($bad));
            self::assertNotNull($bad['error'] ?? null);
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    /**
     * F5-P2-A3: wizard detail downloads use `__links`; a published record's public
     * detail holds both file names with section-scoped URLs; a draft is not found.
     */
    public function testWizardDetailDownloadsLinksForPublishedRecord(): void
    {
        $admin = $this->loginAsQaAdmin();
        $base = 'qa-dl-links';
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => $base,
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'with_status' => true,
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'label' => 'Title'],
                ['name' => 'attachments', 'style' => 'files', 'label' => 'Files'],
            ],
        ], 'qa-dl-links-app');

        try {
            $em = $this->service(EntityManagerInterface::class);
            foreach (['public_list', 'public_detail'] as $role) {
                $page = $em->find(Page::class, $byRole[$role]);
                self::assertInstanceOf(Page::class, $page);
                $page->setIsOpenAccess(true);
            }
            $em->flush();

            $downloadsId = $this->sectionIdByNameContains($byRole['public_detail'], 'detail-downloads', $admin);
            $downloadsText = (string) $this->fieldContentOf($byRole['public_detail'], $downloadsId, 'text', $admin);
            self::assertStringContainsString('__links', $downloadsText);

            $formId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $listId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            // Minimal PDF headers so the extension/content check accepts them.
            $pdfA = "%PDF-1.1\n1 0 obj<< /Title (one) >>endobj\ntrailer<<>>\n%%EOF\n";
            $pdfB = "%PDF-1.1\n1 0 obj<< /Title (two) >>endobj\ntrailer<<>>\n%%EOF\n";
            $pathA = (string) tempnam(sys_get_temp_dir(), 'qa_dl_a_');
            $pathB = (string) tempnam(sys_get_temp_dir(), 'qa_dl_b_');
            file_put_contents($pathA, $pdfA);
            file_put_contents($pathB, $pdfB);

            $this->client->request(
                'POST',
                '/cms-api/v1/forms/submit',
                [
                    'page_id' => (string) $byRole['cms_list'],
                    'section_id' => (string) $formId,
                    'title' => 'With files',
                    'status' => 'draft',
                ],
                [
                    'attachments' => [
                        new UploadedFile($pathA, 'one.pdf', 'application/pdf', null, true),
                        new UploadedFile($pathB, 'two.pdf', 'application/pdf', null, true),
                    ],
                ],
                ['HTTP_Authorization' => 'Bearer ' . $admin],
            );
            $created = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
            $recordId = self::coerceInt($created['record_id'] ?? null);
            $version = self::coerceInt($created['version'] ?? null);
            $allCells = $this->service(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
                'SELECT col.field_key, c.value FROM data_cells c
                 JOIN data_cols col ON col.id = c.id_data_cols
                 WHERE c.id_data_rows = :id',
                ['id' => $recordId],
            );
            $stored = '';
            foreach ($allCells as $cell) {
                $value = self::coerceString($cell['value'] ?? null);
                if (\App\Service\CMS\FormFileUploadService::cellHoldsFiles($value)) {
                    $stored = $value;
                    break;
                }
            }
            self::assertNotSame(
                '',
                $stored,
                'Wizard files field must store the uploads; cells=' . json_encode($allCells) . ' created=' . json_encode($created),
            );
            self::assertGreaterThanOrEqual(
                2,
                count(\App\Service\CMS\FormFileUploadService::keysIn($stored)),
                'Both PDFs must be stored: ' . $stored,
            );

            $detailDraft = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $recordId),
            ));
            self::assertStringNotContainsString('With files', self::jsonEncodeMessage($detailDraft));
            self::assertStringNotContainsString('one.pdf', self::jsonEncodeMessage($detailDraft));

            $this->assertEnvelopeSuccess($this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/lists/%d/records/%d/actions/publish', $listId, $recordId),
                ['page_id' => $byRole['cms_list'], 'expected_version' => $version],
                $admin,
            ));

            $detailLive = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $recordId),
            ));
            $downloadsHtml = self::resolvedSectionText($detailLive, $downloadsId);
            self::assertNotSame('', $downloadsHtml, 'Published detail must render downloads HTML');
            self::assertStringContainsString('one.pdf', $downloadsHtml);
            self::assertStringContainsString('two.pdf', $downloadsHtml);
            self::assertMatchesRegularExpression(
                '#<a href="/lists/\d+/records/' . $recordId . '/files/[^"]+">one\.pdf</a>#',
                $downloadsHtml,
            );
            self::assertMatchesRegularExpression(
                '#<a href="/lists/\d+/records/' . $recordId . '/files/[^"]+">two\.pdf</a>#',
                $downloadsHtml,
            );
            self::assertStringContainsString('page_id=', $downloadsHtml);
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    /**
     * P1-A6 / P1-A7: draft hidden on public surfaces; publish shows list, detail and
     * file; unpublish hides them again.
     */
    public function testPublishUnpublishShowsAndHidesPublicListDetailAndFile(): void
    {
        $admin = $this->loginAsQaAdmin();
        $base = 'qa-pub-cycle';
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => $base,
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'with_status' => true,
            // Only fields that are written: an unused file column in list_columns
            // has no data_cols row until first save (lazy columns) and breaks the view.
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'label' => 'Title'],
                ['name' => 'portrait', 'style' => 'image', 'label' => 'Portrait'],
            ],
        ], 'qa-pub-cycle-app');

        try {
            $em = $this->service(EntityManagerInterface::class);
            foreach (['public_list', 'public_detail'] as $role) {
                $page = $em->find(Page::class, $byRole[$role]);
                self::assertInstanceOf(Page::class, $page);
                $page->setIsOpenAccess(true);
            }
            $em->flush();

            $listId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $formId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $viewId = $this->firstSectionIdByStyle($byRole['public_list'], 'data-view', $admin);
            $detailSectionId = $this->firstSectionIdByStyle($byRole['public_detail'], 'entry-record', $admin);
            $columns = $this->jsonField($byRole['cms_list'], 'entry-form-list', 'list_columns', $admin);
            $portraitKey = null;
            foreach ($columns as $column) {
                if (($column['format'] ?? null) === 'image') {
                    $portraitKey = self::coerceString($column['field'] ?? null);
                    break;
                }
            }
            self::assertNotNull($portraitKey, 'Scaffold must expose an image column.');

            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
            self::assertNotFalse($png);
            $path = (string) tempnam(sys_get_temp_dir(), 'qa_pub_');
            file_put_contents($path, $png);
            $upload = new UploadedFile($path, 'portrait.png', 'image/png', null, true);

            $this->client->request(
                'POST',
                '/cms-api/v1/forms/submit',
                [
                    'page_id' => (string) $byRole['cms_list'],
                    'section_id' => (string) $formId,
                    'title' => 'Draft hero',
                    'status' => 'draft',
                ],
                ['portrait' => $upload],
                ['HTTP_Authorization' => 'Bearer ' . $admin],
            );
            $created = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
            $recordId = self::coerceInt($created['record_id'] ?? null);
            $version = self::coerceInt($created['version'] ?? null);

            $guestView = $this->assertEnvelopeSuccess($this->listState($viewId, $byRole['public_list'], null));
            self::assertSame([], $this->recordIds($guestView), 'Draft must be absent from the public list.');

            // Unknown / out-of-filter record_id: page still 200, entry-record children empty (F166).
            $detailDraft = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $recordId),
            ));
            self::assertStringNotContainsString('Draft hero', self::jsonEncodeMessage($detailDraft), 'Draft detail must not show the record.');

            $this->client->request(
                'GET',
                sprintf('/cms-api/v1/lists/%d/records/%d/files/%s', $detailSectionId, $recordId, $portraitKey),
                ['page_id' => (string) $byRole['public_detail'], 'index' => '0', 'route' => ['record_id' => (string) $recordId]],
            );
            self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

            $published = $this->assertEnvelopeSuccess($this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/lists/%d/records/%d/actions/publish', $listId, $recordId),
                ['page_id' => $byRole['cms_list'], 'expected_version' => $version],
                $admin,
            ));
            $version = self::coerceInt($published['version'] ?? null);

            $guestView = $this->assertEnvelopeSuccess($this->listState($viewId, $byRole['public_list'], null));
            self::assertSame([$recordId], $this->recordIds($guestView), 'Published record must appear on the public list.');
            $imageCell = self::asArray(self::asArray($this->firstRow($guestView)['values'] ?? null)[$portraitKey] ?? null);
            self::assertNotEmpty(self::asList($imageCell['files'] ?? null), 'Public list image cell must carry files.');

            $detailLive = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $recordId),
            ));
            $encodedDetail = self::jsonEncodeMessage($detailLive);
            self::assertStringContainsString('Draft hero', $encodedDetail, 'Published detail must show the title.');
            // json_encode escapes slashes as \/ — match either form.
            self::assertMatchesRegularExpression(
                '#\\\\?/lists\\\\?/\\d+\\\\?/records\\\\?/\\d+\\\\?/files#',
                $encodedDetail,
                'Published detail must carry a section-scoped image URL: ' . $encodedDetail,
            );

            $this->client->request(
                'GET',
                sprintf('/cms-api/v1/lists/%d/records/%d/files/%s', $detailSectionId, $recordId, $portraitKey),
                ['page_id' => (string) $byRole['public_detail'], 'index' => '0', 'route' => ['record_id' => (string) $recordId]],
            );
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
            self::assertSame($png, $this->client->getInternalResponse()->getContent());

            $unpublished = $this->assertEnvelopeSuccess($this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/lists/%d/records/%d/actions/unpublish', $listId, $recordId),
                ['page_id' => $byRole['cms_list'], 'expected_version' => $version],
                $admin,
            ));
            self::assertGreaterThan($version, self::coerceInt($unpublished['version'] ?? null));

            $guestView = $this->assertEnvelopeSuccess($this->listState($viewId, $byRole['public_list'], null));
            self::assertSame([], $this->recordIds($guestView), 'Unpublished record must leave the public list.');
            $detailGone = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $recordId),
            ));
            self::assertStringNotContainsString('Draft hero', self::jsonEncodeMessage($detailGone));
            $this->client->request(
                'GET',
                sprintf('/cms-api/v1/lists/%d/records/%d/files/%s', $detailSectionId, $recordId, $portraitKey),
                ['page_id' => (string) $byRole['public_detail'], 'index' => '0', 'route' => ['record_id' => (string) $recordId]],
            );
            self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testWizardMultiFieldBuilderScaffoldsEachInputAndColumn(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, $created, $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => self::MULTI_BASE,
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
            'form_fields' => [
                ['name' => 'first_name', 'style' => 'text-input', 'label' => 'First name'],
                ['name' => 'bio', 'style' => 'textarea'],
                ['name' => 'age', 'style' => 'number-input', 'label' => 'Age'],
                ['name' => 'agreed', 'style' => 'checkbox', 'label' => 'Agreed'],
                ['name' => 'kind', 'style' => 'select', 'label' => 'Kind'],
            ],
        ], 'qa-team-wizard-multi-app');

        try {
            self::assertCount(1, $created, 'Only the CMS list is created.');
            self::assertSame(['cms_list'], array_keys($byRole));

            $cmsBody = $this->sectionsBody($byRole['cms_list'], $admin);
            foreach (['textarea', 'number-input', 'checkbox', 'select', 'first_name', 'bio', 'age'] as $needle) {
                self::assertStringContainsString($needle, $cmsBody);
            }

            $columns = $this->jsonField($byRole['cms_list'], 'entry-form-list', 'list_columns', $admin);
            self::assertCount(5, $columns, 'One column per input, in the order of the form.');
            self::assertSame(
                ['First name', 'Bio', 'Age', 'Agreed', 'Kind'],
                array_map(static fn (array $column): string => is_string($column['label'] ?? null) ? $column['label'] : '', $columns)
            );
            self::assertSame([true, true, null, null, null], array_map(static fn (array $c): mixed => $c['searchable'] ?? null, $columns), 'Only text is searchable.');
            self::assertSame([null, null, 'number', 'boolean', null], array_map(static fn (array $c): mixed => $c['format'] ?? null, $columns));

            // The first choice input is offered as a filter (the values that exist).
            $controls = $this->jsonField($byRole['cms_list'], 'entry-form-list', 'list_controls', $admin);
            self::assertCount(1, $controls);
            self::assertSame('select', $controls[0]['type'] ?? null);
            self::assertSame($columns[4]['field'] ?? null, $controls[0]['field'] ?? null);
            self::assertSame('data', $controls[0]['options_from'] ?? null);
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testScaffoldedAppAddsEditsAndDeletesRecordsThroughTheListAndTheViewFollows(): void
    {
        $admin = $this->loginAsQaAdmin();
        $base = 'qa-editmode-app';

        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => $base,
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'label' => 'Title'],
                ['name' => 'notes', 'style' => 'textarea', 'label' => 'Notes'],
            ],
        ], 'qa-editmode-shell');

        try {
            $listId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $formId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $viewId = $this->firstSectionIdByStyle($byRole['public_list'], 'data-view', $admin);
            self::assertGreaterThan(0, $listId);
            self::assertGreaterThan(0, $formId);
            self::assertGreaterThan(0, $viewId);
            $titleKey = self::coerceString($this->jsonField($byRole['cms_list'], 'entry-form-list', 'list_columns', $admin)[0]['field'] ?? null);

            // Add through the list's form.
            $created = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => $byRole['cms_list'],
                'section_id' => $formId,
                'form_data' => ['title' => 'qa Alice', 'notes' => 'first'],
            ], $admin));
            $recordId = self::coerceInt($created['record_id'] ?? null);
            self::assertGreaterThan(0, $recordId);
            self::assertSame(1, $created['version'] ?? null);

            // The CMS list and the public view show the same record.
            $cmsState = $this->assertEnvelopeSuccess($this->listState($listId, $byRole['cms_list'], $admin));
            self::assertSame([$recordId], $this->recordIds($cmsState));
            self::assertSame('qa Alice', $this->firstValue($cmsState, $titleKey));
            $viewState = $this->assertEnvelopeSuccess($this->listState($viewId, $byRole['public_list'], $admin));
            self::assertSame([$recordId], $this->recordIds($viewState));
            self::assertSame('qa Alice', $this->firstValue($viewState, $titleKey));
            self::assertSame('/' . $base . '/' . $recordId, $this->firstRow($viewState)['detail_url'] ?? null, 'Every public row links to the public detail page.');

            // Edit: the next save is based on the version the person saw.
            $updated = $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
                'page_id' => $byRole['cms_list'],
                'section_id' => $formId,
                'form_data' => ['title' => 'qa Alice B.', 'notes' => 'first'],
                'update_based_on' => ['record_id' => $recordId],
                'expected_version' => 1,
            ], $admin));
            self::assertSame(2, $updated['version'] ?? null);
            $stale = $this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
                'page_id' => $byRole['cms_list'],
                'section_id' => $formId,
                'form_data' => ['title' => 'Lost edit', 'notes' => 'first'],
                'update_based_on' => ['record_id' => $recordId],
                'expected_version' => 1,
            ], $admin);
            self::assertSame(409, $stale['status'] ?? null, 'A save based on an old version is refused.');
            $viewState = $this->assertEnvelopeSuccess($this->listState($viewId, $byRole['public_list'], $admin));
            self::assertSame('qa Alice B.', $this->firstValue($viewState, $titleKey), 'The public view follows the edit at once.');

            // The public detail page shows the record of its address.
            $detail = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $recordId) . '&preview=true',
                null,
                $admin
            ));
            self::assertNotNull($this->findSectionByStyleInPage($detail, 'entry-record'));
            self::assertStringContainsString('qa Alice B. first', self::jsonEncodeMessage($detail), 'The detail text shows the fields of the record of its address.');

            // The public view cannot write; its table is only reachable through the form of the CMS list.
            $viaView = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => $byRole['public_list'],
                'section_id' => $viewId,
                'form_data' => ['title' => 'Intruder'],
            ], $admin);
            self::assertGreaterThanOrEqual(400, self::coerceInt($viaView['status'] ?? null), 'A data view refuses every write.');
            $viewState = $this->assertEnvelopeSuccess($this->listState($viewId, $byRole['public_list'], $admin));
            self::assertSame([$recordId], $this->recordIds($viewState));

            // Delete through the list; both lists lose the record.
            $deleted = $this->assertEnvelopeSuccess($this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', [
                'record_id' => $recordId,
                'page_id' => $byRole['cms_list'],
                'section_id' => $listId,
                'expected_version' => 2,
            ], $admin));
            self::assertSame(3, $deleted['version'] ?? null);
            self::assertSame([], $this->recordIds($this->assertEnvelopeSuccess($this->listState($listId, $byRole['cms_list'], $admin))));
            self::assertSame([], $this->recordIds($this->assertEnvelopeSuccess($this->listState($viewId, $byRole['public_list'], $admin))));
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testAnExistingTableGetsAListThatReadsAndDeletesButNeverWrites(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => 'qa-owner-app',
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
            'form_fields' => [['name' => 'title', 'style' => 'text-input', 'label' => 'Title']],
        ], 'qa-owner-shell');
        $secondAppId = null;
        $secondPageIds = [];

        try {
            $formId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $created = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
                'page_id' => $byRole['cms_list'],
                'section_id' => $formId,
                'form_data' => ['title' => 'shared record'],
            ], $admin));
            $recordId = self::coerceInt($created['record_id'] ?? null);

            [$secondAppId, $secondCreated, $secondByRole, $secondPageIds] = $this->createAppAndScaffold($admin, [
                'base_name' => 'qa-reader-app',
                'create_form' => false,
                'data_table' => (string) $formId,
                'create_public' => true,
                'create_admin' => true,
            ], 'qa-reader-shell');
            self::assertCount(3, $secondCreated);

            $cmsField = fn (string $field): ?string => $this->sectionFieldValue($secondByRole['cms_list'], 'entry-form-list', $field, $admin);
            self::assertSame('0', $cmsField('allow_create'), 'There is no form to write the table.');
            self::assertSame('0', $cmsField('allow_update'));
            self::assertSame('1', $cmsField('allow_delete'));
            self::assertNotNull($cmsField('data_table'));
            self::assertStringNotContainsString('form-record', $this->sectionsBody($secondByRole['cms_list'], $admin));

            $secondListId = $this->firstSectionIdByStyle($secondByRole['cms_list'], 'entry-form-list', $admin);
            $state = $this->assertEnvelopeSuccess($this->listState($secondListId, $secondByRole['cms_list'], $admin));
            self::assertSame([$recordId], $this->recordIds($state), 'The second app reads the table of the first.');
            self::assertFalse(self::asArray($state['capabilities'] ?? null)['create'] ?? true, 'The list offers no add.');
            $listInfo = self::asArray($state['list'] ?? null);
            self::assertArrayHasKey('form_section_id', $listInfo);
            self::assertNull($listInfo['form_section_id'], 'The list has no form of its own.');

            $secondViewId = $this->firstSectionIdByStyle($secondByRole['public_list'], 'data-view', $admin);
            self::assertSame([$recordId], $this->recordIds($this->assertEnvelopeSuccess($this->listState($secondViewId, $secondByRole['public_list'], $admin))));
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
            if ($secondAppId !== null) {
                $this->cleanupApp($admin, $secondAppId, $secondPageIds);
            }
        }
    }

    public function testAFormNeedsTheCmsListThatHoldsIt(): void
    {
        $admin = $this->loginAsQaAdmin();
        $created = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', ['name' => 'qa-needs-cms', 'slug' => 'qa-needs-cms'], $admin),
            201
        );
        $appId = self::coerceInt($created['id'] ?? null);

        try {
            $response = $this->jsonRequest('POST', sprintf('/cms-api/v1/admin/cms-apps/%d/scaffold', $appId), [
                'base_name' => 'qa-needs-cms',
                'create_form' => true,
                'create_public' => true,
                'create_admin' => false,
            ], $admin);
            self::assertSame(400, $response['status'] ?? null, 'The form lives inside the CMS list: a public-only scaffold cannot create it.');

            $pages = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            self::assertSame([], $pages['pages'] ?? [], 'A refused scaffold creates nothing.');
        } finally {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
        }
    }

    public function testPrimaryRoleUniquenessIsEnforced(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => 'qa-uniq-app',
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
        ], 'qa-uniq-shell');

        $extraPageId = null;
        try {
            self::assertArrayHasKey('cms_list', $byRole);

            $extra = $this->assertEnvelopeSuccess(
                $this->jsonRequest('POST', '/cms-api/v1/admin/pages', [
                    'keyword' => 'qa-uniq-extra',
                    'url' => '/qa-cms/qa-uniq-extra',
                    'pageAccessTypeCode' => 'mobile_and_web',
                    'headless' => false,
                    'openAccess' => false,
                    'surface' => 'cms',
                ], $admin),
                201
            );
            $extraPageId = self::coerceInt($extra['id'] ?? null);
            self::assertGreaterThan(0, $extraPageId);

            $conflict = $this->jsonRequest(
                'POST',
                sprintf('/cms-api/v1/admin/cms-apps/%d/pages', $appId),
                ['page_id' => $extraPageId, 'role' => 'cms_list'],
                $admin
            );
            self::assertSame(409, $conflict['status'] ?? 0);
        } finally {
            if ($extraPageId !== null && $extraPageId > 0) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $extraPageId), null, $admin);
            }
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testDeleteAppShellKeepsPages(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => 'qa-keep-pages',
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
        ], 'qa-keep-shell');

        try {
            self::assertArrayHasKey('cms_list', $byRole);
            $listId = $byRole['cms_list'];

            $delete = $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
            $this->assertEnvelopeSuccess($delete, 200);

            $gone = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
            self::assertSame(404, $gone['status'] ?? 0);

            $page = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $listId), null, $admin)
            );
            self::assertArrayHasKey('fields', $page);

            $list = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $admin)
            );
            $pages = is_array($list['pages'] ?? null) ? $list['pages'] : $list;
            $row = null;
            foreach ($pages as $candidate) {
                if (is_array($candidate) && self::coerceInt($candidate['id_pages'] ?? null) === $listId) {
                    $row = $candidate;
                    break;
                }
            }
            self::assertNotNull($row, 'CMS list page must still appear in the admin pages list after app delete.');
            self::assertNull($row['cms_app_id'] ?? null);
            self::assertNull($row['cms_app_role'] ?? null);
        } finally {
            foreach ($pageIds as $pageId) {
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            }
        }
    }

    public function testHubSyncOnScaffoldAssignChangeRoleUnassignAndPageDelete(): void
    {
        $admin = $this->loginAsQaAdmin();
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => 'qa-hub-sync',
            'create_form' => true,
            'create_public' => false,
            'create_admin' => true,
        ], 'qa-hub-sync-shell');

        try {
            $listPageId = $byRole['cms_list'];
            $formSectionId = $this->firstSectionIdByStyle($listPageId, 'entry-form-list', $admin);
            self::assertGreaterThan(0, $formSectionId);

            $detail = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            self::assertSame($listPageId, self::coerceInt($detail['id_cms_list_page'] ?? null));
            self::assertSame($formSectionId, $detail['id_form_section'] ?? null, 'The form of the app is the inline form of its CMS list.');

            $demoted = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'PATCH',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages/%d', $appId, $listPageId),
                    ['role' => 'other'],
                    $admin
                )
            );
            self::assertArrayHasKey('id_cms_list_page', $demoted);
            self::assertNull($demoted['id_cms_list_page']);
            self::assertNull($demoted['id_form_section'] ?? null, 'Without its CMS list the app has no form.');

            $restored = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'PATCH',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages/%d', $appId, $listPageId),
                    ['role' => 'cms_list'],
                    $admin
                )
            );
            self::assertSame($listPageId, self::coerceInt($restored['id_cms_list_page'] ?? null));
            self::assertSame($formSectionId, $restored['id_form_section'] ?? null);

            $unassigned = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'DELETE',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages/%d', $appId, $listPageId),
                    null,
                    $admin
                )
            );
            self::assertArrayHasKey('id_form_section', $unassigned);
            self::assertNull($unassigned['id_form_section']);
            self::assertNull($unassigned['id_cms_list_page'] ?? null);

            $assigned = $this->assertEnvelopeSuccess(
                $this->jsonRequest(
                    'POST',
                    sprintf('/cms-api/v1/admin/cms-apps/%d/pages', $appId),
                    ['page_id' => $listPageId, 'role' => 'cms_list'],
                    $admin
                )
            );
            self::assertSame($listPageId, self::coerceInt($assigned['id_cms_list_page'] ?? null));
            self::assertSame($formSectionId, $assigned['id_form_section'] ?? null);

            $this->assertEnvelopeSuccess(
                $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $listPageId), null, $admin)
            );
            $pageIds = array_values(array_filter($pageIds, static fn(int $id): bool => $id !== $listPageId));

            $afterDelete = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            self::assertArrayHasKey('id_cms_list_page', $afterDelete);
            self::assertNull($afterDelete['id_cms_list_page']);
            self::assertNull($afterDelete['id_form_section'] ?? null);
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    /**
     * P1-A1 / P1-A4: text-input and textarea may be marked translatable; other styles
     * stay unmarked. An omitted `translatable` key behaves as before.
     */
    public function testScaffoldSetsTranslatableOnTextAndTextareaOnly(): void
    {
        $admin = $this->loginAsQaAdmin();
        $base = 'qa-admpol-i18n';
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => $base,
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'with_status' => false,
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'translatable' => true],
                ['name' => 'body', 'style' => 'textarea', 'translatable' => true],
                ['name' => 'price', 'style' => 'number-input'],
            ],
        ], 'qa-admpol-i18n-app');

        try {
            $cmsList = $byRole['cms_list'];
            $titleId = $this->sectionIdByNameContains($cmsList, $base . '-form-title', $admin);
            $bodyId = $this->sectionIdByNameContains($cmsList, $base . '-form-body', $admin);
            $priceId = $this->sectionIdByNameContains($cmsList, $base . '-form-price', $admin);
            self::assertGreaterThan(0, $titleId);
            self::assertGreaterThan(0, $bodyId);
            self::assertGreaterThan(0, $priceId);

            self::assertSame('1', $this->fieldContentOf($cmsList, $titleId, 'translatable', $admin));
            self::assertSame('1', $this->fieldContentOf($cmsList, $bodyId, 'translatable', $admin));
            self::assertNull(
                $this->fieldContentOf($cmsList, $priceId, 'translatable', $admin),
                'A non-translatable field must not get a translatable property row.'
            );
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    /**
     * P1-A2: `translatable: true` is refused for non-text styles; nothing is created.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function nonTextTranslatableStyles(): array
    {
        return [
            ['select', 'qa-admpol-bad-select'],
            ['image', 'qa-admpol-bad-image'],
            ['number-input', 'qa-admpol-bad-number'],
        ];
    }

    #[DataProvider('nonTextTranslatableStyles')]
    public function testScaffoldRefusesTranslatableOnNonTextStyles(string $style, string $slug): void
    {
        $admin = $this->loginAsQaAdmin();
        $this->service(CacheService::class)
            ->withCategory(CacheService::CATEGORY_STYLES)
            ->invalidateAllListsInCategory();

        $createdApp = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', [
                'name' => $slug,
                'slug' => $slug,
            ], $admin),
            201
        );
        $appId = self::coerceInt($createdApp['id'] ?? null);
        self::assertGreaterThan(0, $appId);

        $pagesBefore = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $admin)
        );
        $pageCountBefore = count(is_array($pagesBefore['pages'] ?? null) ? $pagesBefore['pages'] : $pagesBefore);

        $response = $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/cms-apps/%d/scaffold', $appId),
            [
                'base_name' => $slug,
                'create_form' => true,
                'create_public' => true,
                'create_admin' => true,
                'with_status' => false,
                'form_fields' => [
                    ['name' => 'title', 'style' => 'text-input'],
                    ['name' => 'bad', 'style' => $style, 'translatable' => true],
                ],
            ],
            $admin
        );

        try {
            self::assertSame(400, $response['status'] ?? 0, self::jsonEncodeMessage($response));
            $this->assertEnvelope400($response);
            $expected = sprintf('Form field "bad" of type "%s" cannot be translatable.', $style);
            $haystack = self::coerceString($response['message'] ?? '') . ' ' . self::coerceString($response['error'] ?? '');
            self::assertStringContainsString($expected, $haystack);

            $pagesAfter = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', '/cms-api/v1/admin/pages', null, $admin)
            );
            $pageCountAfter = count(is_array($pagesAfter['pages'] ?? null) ? $pagesAfter['pages'] : $pagesAfter);
            self::assertSame($pageCountBefore, $pageCountAfter, 'A refused scaffold must create no pages.');

            $detail = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin)
            );
            self::assertNull($detail['id_cms_list_page'] ?? null);
            self::assertNull($detail['id_form_section'] ?? null);
            self::assertNull($detail['id_public_list_page'] ?? null);
            self::assertNull($detail['id_public_detail_page'] ?? null);
        } finally {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
        }
    }

    /**
     * P1-A3: a translatable wizard field stores one cell per language; the public
     * detail shows the value of the visitor's language.
     */
    public function testTranslatableWizardFieldRoundTripsPerLanguage(): void
    {
        $admin = $this->loginAsQaAdmin();
        $base = 'qa-admpol-rt';
        [$appId, , $byRole, $pageIds] = $this->createAppAndScaffold($admin, [
            'base_name' => $base,
            'create_form' => true,
            'create_public' => true,
            'create_admin' => true,
            'with_status' => false,
            'form_fields' => [
                ['name' => 'title', 'style' => 'text-input', 'label' => 'Title', 'translatable' => true],
            ],
        ], 'qa-admpol-rt-app');

        try {
            $em = $this->service(EntityManagerInterface::class);
            $detailPage = $em->find(Page::class, $byRole['public_detail']);
            self::assertInstanceOf(Page::class, $detailPage);
            $detailPage->setIsOpenAccess(true);
            $em->flush();

            $formId = $this->firstSectionIdByStyle($byRole['cms_list'], 'entry-form-list', $admin);
            $created = $this->assertEnvelopeSuccess($this->jsonRequest(
                'POST',
                '/cms-api/v1/forms/submit',
                [
                    'page_id' => $byRole['cms_list'],
                    'section_id' => $formId,
                    'form_data' => [
                        'title' => [
                            ['language_id' => 2, 'value' => 'Hallo'],
                            ['language_id' => 3, 'value' => 'Hello'],
                        ],
                    ],
                ],
                $admin,
            ));
            $recordId = self::coerceInt($created['record_id'] ?? null);
            self::assertGreaterThan(0, $recordId);

            $cells = $em->getConnection()->fetchAllAssociative(
                'SELECT c.id_languages, c.value FROM data_cells c
                 JOIN data_cols col ON col.id = c.id_data_cols
                 WHERE c.id_data_rows = :id AND col.field_key LIKE :key
                 ORDER BY c.id_languages',
                ['id' => $recordId, 'key' => 'section_%'],
            );
            self::assertCount(2, $cells, 'One cell per language in the same row.');
            self::assertSame(
                [['id_languages' => 2, 'value' => 'Hallo'], ['id_languages' => 3, 'value' => 'Hello']],
                array_map(
                    static fn (array $row): array => [
                        'id_languages' => self::coerceInt($row['id_languages'] ?? null),
                        'value' => self::coerceString($row['value'] ?? null),
                    ],
                    $cells,
                ),
            );

            $path = '/' . $base . '/' . $recordId;
            $de = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode($path) . '&language_id=2',
            ));
            $en = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode($path) . '&language_id=3',
            ));
            self::assertStringContainsString('Hallo', self::jsonEncodeMessage($de));
            self::assertStringNotContainsString('Hello', self::jsonEncodeMessage($de));
            self::assertStringContainsString('Hello', self::jsonEncodeMessage($en));
            self::assertStringNotContainsString('Hallo', self::jsonEncodeMessage($en));

            // F190 observation (do not fix): a second record with only German has no
            // English cell and no language-id-1 cell. English resolve falls back to
            // language id 1 (property row) and therefore shows an empty title.
            $onlyDe = $this->assertEnvelopeSuccess($this->jsonRequest(
                'POST',
                '/cms-api/v1/forms/submit',
                [
                    'page_id' => $byRole['cms_list'],
                    'section_id' => $formId,
                    'form_data' => [
                        'title' => [
                            ['language_id' => 2, 'value' => 'NurDeutsch'],
                        ],
                    ],
                ],
                $admin,
            ));
            $onlyDeId = self::coerceInt($onlyDe['record_id'] ?? null);
            $enMissing = $this->assertEnvelopeSuccess($this->jsonRequest(
                'GET',
                '/cms-api/v1/pages/resolve?path=' . rawurlencode('/' . $base . '/' . $onlyDeId) . '&language_id=3',
            ));
            self::assertStringNotContainsString('NurDeutsch', self::jsonEncodeMessage($enMissing));
        } finally {
            $this->cleanupApp($admin, $appId, $pageIds);
        }
    }

    public function testLegacyCmsInCmsBundleWithoutCmsAppMetadataIsRejected(): void
    {
        $admin = $this->loginAsQaAdmin();
        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => [
                'format' => 'selfhelp/page-bundle',
                'version' => '1.0',
                'title' => 'Legacy CMS app',
                'tags' => ['cms-in-cms'],
                'pages' => [[
                    'keyword' => 'qa-legacy-cms-list',
                    'url' => '/qa-cms/qa-legacy-cms-list',
                    'page_access_type' => 'mobile_and_web',
                    'surface' => 'cms',
                    'headless' => false,
                    'open_access' => false,
                    'sections' => [],
                ]],
            ],
        ], $admin);

        self::assertSame(400, $response['status'] ?? 0, self::jsonEncodeMessage($response));
        $haystack = strtolower(
            self::coerceString($response['message'] ?? '') . ' ' . self::coerceString($response['error'] ?? '') . ' ' . self::jsonEncodeMessage($response)
        );
        self::assertStringContainsString('cms_app', $haystack);
    }

    public function testCmsInCmsBundleImportSetsHubsAndRejectsInvalidRole(): void
    {
        $admin = $this->loginAsQaAdmin();
        $slug = 'qa-import-hubs';

        $ok = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => [
                'format' => 'selfhelp/page-bundle',
                'version' => '1.0',
                'title' => 'Import hubs',
                'tags' => ['cms-in-cms'],
                'cms_app' => [
                    'name' => 'Import hubs',
                    'slug' => $slug,
                    'description' => null,
                ],
                'pages' => [[
                    'keyword' => 'qa-import-hubs-list',
                    'url' => '/qa-cms/qa-import-hubs-list',
                    'page_access_type' => 'mobile_and_web',
                    'surface' => 'cms',
                    'headless' => false,
                    'open_access' => false,
                    'cms_app_role' => 'cms_list',
                    'sections' => [],
                ]],
            ],
        ], $admin);
        $data = $this->assertEnvelopeSuccess($ok, 201);
        $created = is_array($data['created'] ?? null) ? $data['created'] : [];
        $firstCreated = is_array($created[0] ?? null) ? $created[0] : [];
        $pageId = self::coerceInt($firstCreated['page_id'] ?? null);
        self::assertGreaterThan(0, $pageId);

        try {
            $bySlug = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/by-slug/%s', $slug), null, $admin)
            );
            self::assertSame($pageId, self::coerceInt($bySlug['id_cms_list_page'] ?? null));
            $appId = self::coerceInt($bySlug['id'] ?? null);

            $bad = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
                'bundle' => [
                    'format' => 'selfhelp/page-bundle',
                    'version' => '1.0',
                    'title' => 'Bad role',
                    'tags' => ['cms-in-cms'],
                    'cms_app' => [
                        'name' => 'Bad role',
                        'slug' => 'qa-bad-role-app',
                        'description' => null,
                    ],
                    'pages' => [[
                        'keyword' => 'qa-bad-role-page',
                        'url' => '/qa-cms/qa-bad-role-page',
                        'page_access_type' => 'mobile_and_web',
                        'surface' => 'cms',
                        'headless' => false,
                        'open_access' => false,
                        'cms_app_role' => 'not-a-role',
                        'sections' => [],
                    ]],
                ],
            ], $admin);
            self::assertSame(400, $bad['status'] ?? 0, self::jsonEncodeMessage($bad));
            $haystack = strtolower(
                self::coerceString($bad['message'] ?? '') . ' ' . self::coerceString($bad['error'] ?? '') . ' ' . self::jsonEncodeMessage($bad)
            );
            self::assertStringContainsString('cms_app_role', $haystack);

            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
        } catch (\Throwable $e) {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            throw $e;
        }
    }

    public function testCmsAppImportSanitisesKeywordPrefixUnderscoresInSlug(): void
    {
        $admin = $this->loginAsQaAdmin();
        $keywordPrefix = 'demo_team_members_';
        $expectedSlug = 'demo-team-members-qa-team-members';

        $response = $this->jsonRequest('POST', '/cms-api/v1/admin/pages/import', [
            'bundle' => [
                'format' => 'selfhelp/page-bundle',
                'version' => '1.0',
                'title' => 'Prefixed team',
                'tags' => ['cms-in-cms'],
                'cms_app' => [
                    'name' => 'Team members',
                    'slug' => 'qa-team-members',
                    'description' => null,
                ],
                'pages' => [[
                    'keyword' => 'qa-cms-team-members',
                    'url' => '/qa-cms/team-members',
                    'page_access_type' => 'mobile_and_web',
                    'surface' => 'cms',
                    'headless' => false,
                    'open_access' => false,
                    'cms_app_role' => 'cms_list',
                    'sections' => [],
                ]],
            ],
            'options' => [
                'keywordPrefix' => $keywordPrefix,
                'routePrefix' => '/demo-team-members',
            ],
        ], $admin);
        $data = $this->assertEnvelopeSuccess($response, 201);
        $created = is_array($data['created'] ?? null) ? $data['created'] : [];
        $firstCreated = is_array($created[0] ?? null) ? $created[0] : [];
        $pageId = self::coerceInt($firstCreated['page_id'] ?? null);
        self::assertGreaterThan(0, $pageId);

        try {
            $bySlug = $this->assertEnvelopeSuccess(
                $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/cms-apps/by-slug/%s', $expectedSlug), null, $admin)
            );
            self::assertSame('Team members', $bySlug['name'] ?? null);
            self::assertSame($expectedSlug, $bySlug['slug'] ?? null);
            $appId = self::coerceInt($bySlug['id'] ?? null);
            self::assertGreaterThan(0, $appId);

            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $admin);
        } catch (\Throwable $e) {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $admin);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function listState(int $listSectionId, int $pageId, ?string $token): array
    {
        return $this->jsonRequest('GET', sprintf('/cms-api/v1/lists/%d/state?page_id=%d', $listSectionId, $pageId), null, $token);
    }

    /**
     * @param array<string, mixed> $state
     * @return list<int>
     */
    private function recordIds(array $state): array
    {
        $ids = [];
        foreach (self::asList($state['rows'] ?? null) as $row) {
            $ids[] = self::coerceInt(self::asArray($row)['record_id'] ?? null);
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function firstRow(array $state): array
    {
        return self::asArray(self::asList($state['rows'] ?? null)[0] ?? null, 'The list has no row.');
    }

    /**
     * @param array<string, mixed> $state
     */
    private function firstValue(array $state, string $fieldKey): string
    {
        return self::coerceString(self::asArray($this->firstRow($state)['values'] ?? null)[$fieldKey] ?? null);
    }

    /**
     * A section field that holds a JSON list (columns, controls), decoded.
     *
     * @return list<array<string, mixed>>
     */
    private function jsonField(int $pageId, string $styleName, string $fieldName, string $token): array
    {
        $raw = $this->sectionFieldValue($pageId, $styleName, $fieldName, $token);
        self::assertNotNull($raw, sprintf('"%s" of "%s" must be set.', $fieldName, $styleName));

        $items = [];
        foreach (self::asList(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)) as $item) {
            $items[] = self::asArray($item);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $scaffold
     * @return array{0: int, 1: list<array<string, mixed>>, 2: array<string, int>, 3: list<int>}
     */
    private function createAppAndScaffold(string $token, array $scaffold, string $slug): array
    {
        // Style-field seed migrations (e.g. entry-record.fixed_filter) leave the
        // CATEGORY_STYLES Redis list stale until the next generation bump.
        $this->service(CacheService::class)
            ->withCategory(CacheService::CATEGORY_STYLES)
            ->invalidateAllListsInCategory();

        $createdApp = $this->assertEnvelopeSuccess(
            $this->jsonRequest('POST', '/cms-api/v1/admin/cms-apps', [
                'name' => $slug,
                'slug' => $slug,
            ], $token),
            201
        );
        $appId = self::coerceInt($createdApp['id'] ?? null);
        self::assertGreaterThan(0, $appId);

        // Existing fixtures predate draft/publish; opt out unless a test asks for it.
        if (!array_key_exists('with_status', $scaffold)) {
            $scaffold['with_status'] = false;
        }

        $response = $this->jsonRequest(
            'POST',
            sprintf('/cms-api/v1/admin/cms-apps/%d/scaffold', $appId),
            $scaffold,
            $token
        );
        $data = $this->assertEnvelopeSuccess($response, 201);
        $createdRaw = is_array($data['created'] ?? null) ? $data['created'] : [];

        $created = [];
        $byRole = [];
        $pageIds = [];
        foreach ($createdRaw as $entry) {
            if (!is_array($entry) || !is_int($entry['page_id'] ?? null)) {
                continue;
            }
            $normalized = [];
            foreach ($entry as $key => $value) {
                $normalized[(string) $key] = $value;
            }
            $created[] = $normalized;
            $pageIds[] = $entry['page_id'];
            $role = is_string($entry['role'] ?? null) ? $entry['role'] : '';
            $byRole[$role] = $entry['page_id'];
        }

        return [$appId, $created, $byRole, $pageIds];
    }

    private static function jsonEncodeMessage(mixed $value): string
    {
        $encoded = json_encode($value);

        return $encoded === false ? 'json_encode_failed' : $encoded;
    }

    /**
     * Text field content of a section in a resolved public page payload.
     *
     * @param array<string, mixed> $payload
     */
    private static function resolvedSectionText(array $payload, int $sectionId): string
    {
        $sections = self::asList(self::asArray($payload['page'] ?? null)['sections'] ?? null);
        $stack = $sections;
        while ($stack !== []) {
            $section = array_shift($stack);
            if (!is_array($section)) {
                continue;
            }
            foreach (self::asList($section['children'] ?? null) as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
            if (self::coerceInt($section['id'] ?? null) !== $sectionId) {
                continue;
            }
            $text = $section['text'] ?? null;
            if (is_array($text) && is_string($text['content'] ?? null)) {
                return $text['content'];
            }

            return is_string($text) ? $text : '';
        }

        return '';
    }

    /** @param list<int> $pageIds */
    private function cleanupApp(string $token, int $appId, array $pageIds): void
    {
        foreach ($pageIds as $pageId) {
            $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $token);
        }
        $this->jsonRequest('DELETE', sprintf('/cms-api/v1/admin/cms-apps/%d', $appId), null, $token);
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

    private function sectionsBody(int $pageId, string $token): string
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);

        return (string) json_encode($data);
    }

    private function sectionFieldValue(int $pageId, string $styleName, string $fieldName, string $token): ?string
    {
        $sectionId = $this->firstSectionIdByStyle($pageId, $styleName, $token);
        self::assertGreaterThan(0, $sectionId);

        return $this->fieldContentOf($pageId, $sectionId, $fieldName, $token);
    }

    private function sectionIdByNameContains(int $pageId, string $needle, string $token): int
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d/sections', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);
        $found = 0;
        $walk = function ($nodes) use (&$walk, &$found, $needle): void {
            if (!is_array($nodes) || $found > 0) {
                return;
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $name = is_string($node['section_name'] ?? null) ? $node['section_name'] : '';
                if ($name !== '' && str_contains($name, $needle) && is_numeric($node['id'] ?? null)) {
                    $found = (int) $node['id'];

                    return;
                }
                $walk($node['children'] ?? null);
            }
        };
        $walk($data['sections'] ?? null);

        return $found;
    }

    private function fieldContentOf(int $pageId, int $sectionId, string $fieldName, string $token): ?string
    {
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

    private function pageProperty(int $pageId, string $fieldName, string $token): ?string
    {
        $resp = $this->jsonRequest('GET', sprintf('/cms-api/v1/admin/pages/%d', $pageId), null, $token);
        $data = $this->assertEnvelopeSuccess($resp);
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['name'] ?? null) !== $fieldName) {
                continue;
            }
            $translations = is_array($field['translations'] ?? null) ? $field['translations'] : [];
            foreach ($translations as $translation) {
                if (is_array($translation) && array_key_exists('content', $translation)) {
                    return is_string($translation['content']) ? $translation['content'] : null;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $pagePayload
     * @return array<string, mixed>|null
     */
    private function findSectionByStyleInPage(array $pagePayload, string $styleName): ?array
    {
        $found = null;
        $walk = function ($nodes) use (&$walk, &$found, $styleName): void {
            if (!is_array($nodes) || $found !== null) {
                return;
            }
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $style = $node['style_name'] ?? null;
                if ($style === null && is_array($node['style'] ?? null)) {
                    $style = $node['style']['name'] ?? null;
                }
                if ($style === $styleName) {
                    $normalized = [];
                    foreach ($node as $key => $value) {
                        $normalized[(string) $key] = $value;
                    }
                    $found = $normalized;

                    return;
                }
                if (isset($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $sections = $pagePayload['sections']
            ?? (is_array($pagePayload['page'] ?? null) ? ($pagePayload['page']['sections'] ?? []) : []);
        $walk($sections);

        return $found;
    }
}
