<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\DataTable;
use App\Entity\Page;
use App\Entity\Section;
use App\Service\JSON\JsonSchemaValidationService;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The published JSON Schemas of the list endpoints against what the server really
 * answers. The response formatter only checks a response when VALIDATE_RESPONSE_SCHEMA
 * is on, and then only writes a log line, so no other test would notice a schema that
 * drifted from the code (or from the shared types that follow it). Every answer below
 * is validated against its own schema: the state in every mode, the writes with their
 * version, the quick action, the history, the comparison, the restore, the page that
 * carries the lists and the admin section that tells the editor what a list may name.
 */
#[PhpUnitGroup('contract')]
#[PhpUnitGroup('form-list')]
final class FormListSchemaContractTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    /** The id of the content language en-GB. */
    private const EN = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testTheStateOfAPublicDataViewMatchesItsSchema(): void
    {
        [$page, $view] = $this->dataView('qa_lc_view', ['page_size' => '5']);

        foreach (['', 'list.sort=seats:desc', 'list.page=2', 'list.q=Event&list.f.city=Bern', 'list.f.seats=50..100&list.f.starts=2026-01-03..2026-01-20'] as $query) {
            $this->assertEnvelopeSuccess($this->state($page, $view, $query));
            $this->assertLastResponseMatches('responses/frontend/form_list_state');
        }
    }

    public function testTheStateOfADataViewWithARecordTemplateMatchesItsSchema(): void
    {
        [$page, $view] = $this->customDataView('qa_lc_custom');
        $this->recordTemplate($view, 'qa_lc_custom');

        $data = $this->assertEnvelopeSuccess($this->state($page, $view, 'language_id=' . self::EN));
        $this->assertLastResponseMatches('responses/frontend/form_list_state');

        $rows = $this->listOf($data, 'rows');
        self::assertNotEmpty($rows);
        self::assertNotEmpty($this->listOf($rows[0], 'sections'), 'The rows carry the rendered template, so the schema is checked against it.');
    }

    public function testTheStateOfAnEntryListMatchesItsSchemaInEveryMode(): void
    {
        [$page, $list, $tableId] = $this->formList('qa_lc_modes', ['show_history' => '1', 'quick_actions' => $this->doneAction()]);
        $token = $this->loginAsQaUser();
        $row = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'One', 'status' => 'open']);

        foreach (['', 'list.mode=create', 'list.record=' . $row, 'list.record=' . $row . '&list.mode=edit', 'list.q=On'] as $query) {
            $this->assertEnvelopeSuccess($this->state($page, $list, $query, $token));
            $this->assertLastResponseMatches('responses/frontend/form_list_state');
        }
    }

    public function testTheWritesAndTheHistoryOfAnEntryListMatchTheirSchemas(): void
    {
        [$page, $list, , $form] = $this->formList('qa_lc_flow', ['show_history' => '1', 'quick_actions' => $this->doneAction()]);
        $token = $this->loginAsQaUser();
        $pageId = (int) $page->getId();
        $formId = (int) $form->getId();

        $created = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => $pageId,
            'section_id' => $formId,
            'form_data' => ['title' => 'Write report', 'status' => 'open'],
        ], $token));
        $this->assertLastResponseMatches('responses/frontend/form_submitted');
        $recordId = self::coerceInt($created['record_id'] ?? null);

        $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => $pageId,
            'section_id' => $formId,
            'form_data' => ['title' => 'Write the report', 'status' => 'open'],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => 1,
        ], $token));
        $this->assertLastResponseMatches('responses/frontend/form_updated');

        $base = '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId;

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', $base . '/actions/done', ['page_id' => $pageId, 'expected_version' => 2], $token));
        $this->assertLastResponseMatches('responses/frontend/form_list_action');

        $this->assertEnvelopeSuccess($this->jsonRequest('GET', $base . '/history?page_id=' . $pageId, null, $token));
        $this->assertLastResponseMatches('responses/frontend/form_list_history');

        $this->assertEnvelopeSuccess($this->jsonRequest('GET', $base . '/versions/1?page_id=' . $pageId, null, $token));
        $this->assertLastResponseMatches('responses/frontend/form_list_version');

        $this->assertEnvelopeSuccess($this->jsonRequest('GET', $base . '/compare?page_id=' . $pageId . '&from=1&to=3', null, $token));
        $this->assertLastResponseMatches('responses/frontend/form_list_compare');

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', $base . '/restore', ['page_id' => $pageId, 'version' => 1, 'dry_run' => true], $token));
        $this->assertLastResponseMatches('responses/frontend/form_list_restore');

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', $base . '/restore', ['page_id' => $pageId, 'version' => 1, 'expected_version' => 3], $token));
        $this->assertLastResponseMatches('responses/frontend/form_list_restore');

        $this->assertEnvelopeSuccess($this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', [
            'page_id' => $pageId,
            'section_id' => (int) $list->getId(),
            'record_id' => $recordId,
            'expected_version' => 4,
        ], $token));
        $this->assertLastResponseMatches('responses/frontend/form_deleted');
    }

    public function testThePageHoldsTheListStateAndTheListErrorsInItsSchema(): void
    {
        [$page, $view] = $this->dataView('qa_lc_page', ['page_size' => '5']);
        foreach (['', 'list.page=2&list.q=Event', 'list.page=0'] as $query) {
            $this->assertEnvelopeSuccess($this->pagePayload($page, $query));
            $this->assertLastResponseMatches('responses/frontend/get_page');
        }
        self::assertGreaterThan(0, (int) $view->getId());

        // A guest on a list of own entries: the page is served and the list carries `list_error`.
        [$ownPage] = $this->formList('qa_lc_guest', [], openAccess: true);
        $this->assertEnvelopeSuccess($this->pagePayload($ownPage));
        $this->assertLastResponseMatches('responses/frontend/get_page');

        // A data view with a record template: the rows carry the rendered sections.
        [$customPage, $custom] = $this->customDataView('qa_lc_page_custom');
        $this->recordTemplate($custom, 'qa_lc_page_custom');
        $this->assertEnvelopeSuccess($this->pagePayload($customPage, 'language_id=' . self::EN));
        $this->assertLastResponseMatches('responses/frontend/get_page');
    }

    public function testTheAdminSectionOfAListMatchesItsSchema(): void
    {
        [$listPage, $list] = $this->formList('qa_lc_admin');
        [$viewPage, $view] = $this->dataView('qa_lc_admin_view', openAccess: false);
        $token = $this->loginAsQaAdmin();

        $this->assertAdminSection($token, $listPage, $list);
        $this->assertAdminSection($token, $viewPage, $view);
    }

    public function testImageAndFileCellsAndAdminHistoryPayloadsMatchTheirSchemas(): void
    {
        [$page, $list, $tableId, $form, $keys] = $this->declaredList(
            'qa_lc_media',
            [
                'title' => ['style' => 'text-input'],
                'doc' => ['style' => 'file-input'],
            ],
            ['show_history' => '1'],
        );
        $this->pages->setSectionField($list, 'list_columns', json_encode([
            ['field' => $keys['title'], 'label' => 'Title'],
            ['field' => $keys['doc'], 'label' => 'Doc', 'format' => 'image'],
        ], JSON_THROW_ON_ERROR));
        $this->pages->invalidatePageScopedCaches();
        $this->uploadsOf((int) $form->getId());
        $token = $this->loginAsQaUser();
        $path = (string) tempnam(sys_get_temp_dir(), 'qa_lc_media_');
        file_put_contents($path, "schema-bytes\n");
        $this->client->request(
            'POST',
            '/cms-api/v1/forms/submit',
            [
                'page_id' => (string) $page->getId(),
                'section_id' => (string) $form->getId(),
                'title' => 'Schema media',
            ],
            ['doc' => new UploadedFile($path, 'note.txt', null, null, true)],
            ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_Authorization' => 'Bearer ' . $token],
        );
        $created = $this->assertEnvelopeSuccess($this->decode($this->client->getResponse()));
        $recordId = self::coerceInt($created['record_id'] ?? null);

        $state = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        $values = self::asArray(self::asArray(self::asList($state['rows'] ?? null)[0] ?? null)['values'] ?? null);
        self::assertArrayHasKey($keys['doc'], $values);
        self::assertArrayHasKey('files', self::asArray($values[$keys['doc']]));
        $this->assertLastResponseMatches('responses/frontend/form_list_state');

        $admin = $this->loginAsQaAdmin();
        $table = $this->em->find(DataTable::class, $tableId);
        self::assertNotNull($table);
        $tableName = (string) $table->getName();
        $base = sprintf('/cms-api/v1/admin/data/tables/%s/records/%d', $tableName, $recordId);

        $this->assertEnvelopeSuccess($this->jsonRequest('GET', $base . '/history', null, $admin));
        $this->assertLastResponseMatches('responses/admin/data/record_history');

        $this->assertEnvelopeSuccess($this->jsonRequest('GET', $base . '/versions/1', null, $admin));
        $this->assertLastResponseMatches('responses/admin/data/record_version');

        $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['title' => 'Schema media 2'],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => 1,
        ], $token));

        $this->assertEnvelopeSuccess($this->jsonRequest('GET', $base . '/compare?from=1&to=2', null, $admin));
        $this->assertLastResponseMatches('responses/admin/data/record_compare');

        $this->assertEnvelopeSuccess($this->jsonRequest('POST', $base . '/restore', [
            'version' => 1,
            'expected_version' => 2,
            'dry_run' => true,
        ], $admin));
        $this->assertLastResponseMatches('responses/admin/data/record_restore');
    }

    private function assertAdminSection(string $token, Page $page, Section $section): void
    {
        $this->assertEnvelopeSuccess($this->jsonRequest(
            'GET',
            sprintf('/cms-api/v1/admin/pages/%d/sections/%d', $page->getId(), $section->getId()),
            null,
            $token,
        ));
        $this->assertLastResponseMatches('responses/admin/sections/section');
    }

    /**
     * @return array<string, mixed>
     */
    private function pagePayload(Page $page, string $query = ''): array
    {
        return $this->jsonRequest('GET', '/cms-api/v1/pages/by-keyword/' . $page->getKeyword() . ($query === '' ? '' : '?' . $query));
    }

    private function doneAction(): string
    {
        return json_encode([[
            'id' => 'done',
            'label' => 'Mark done',
            'confirm' => 'Mark this entry as done?',
            'set' => ['status' => 'done'],
            'when' => ['mode' => 'and', 'children' => [['column' => 'status', 'operator' => 'eq', 'value' => 'open']]],
        ]], JSON_THROW_ON_ERROR);
    }

    /** Validates the last response of the client, the whole envelope, against a published schema. */
    private function assertLastResponseMatches(string $schema): void
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = self::asObject(json_decode($content, false, 512, JSON_THROW_ON_ERROR), 'The response is not a JSON object');

        $errors = $this->service(JsonSchemaValidationService::class)->validate($decoded, $schema);
        self::assertSame([], $errors, sprintf("Response failed schema %s:\n%s", $schema, implode("\n", $errors)));
    }
}
