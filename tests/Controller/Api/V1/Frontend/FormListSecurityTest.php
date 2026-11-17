<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Page;
use App\Entity\Section;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * What a list must never do, whoever asks and however the request is shaped:
 * show a column the author did not choose, act on a record the visitor may not
 * see or change, take a request for another page or another section, or let a
 * client decide what an action writes. Every case states the attack and checks
 * that nothing leaked and nothing changed.
 */
#[PhpUnitGroup('form-list')]
#[PhpUnitGroup('security')]
final class FormListSecurityTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testASharedListShowsOnlyTheColumnsTheAuthorChose(): void
    {
        [$page, $list, $tableId] = $this->formList('qa_lsec_columns', ['own_entries_only' => '0', 'list_columns' => '[{"field":"title"}]']);
        $this->grid->row($tableId, $this->userId('qa.admin@selfhelp.test'), ['title' => 'Public', 'status' => 'secret-status']);
        $token = $this->loginAsQaUser();

        $state = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));

        self::assertSame(['Public'], $this->titles($state));
        self::assertStringNotContainsString('secret-status', json_encode($state, JSON_THROW_ON_ERROR), 'A column that is not configured never leaves the server.');
        self::assertStringNotContainsString('section_', (string) json_encode($state['rows'] ?? []), 'No internal column key is exposed.');
    }

    public function testSomeoneElsesRecordCannotBeOpenedThroughTheUrl(): void
    {
        [$page, $list, $tableId] = $this->formList('qa_lsec_foreign_record');
        $theirs = $this->grid->row($tableId, $this->userId('qa.admin@selfhelp.test'), ['title' => 'Theirs', 'status' => 'open']);
        $token = $this->loginAsQaUser();

        $envelope = $this->state($page, $list, 'list.record=' . $theirs, $token);

        self::assertSame(404, $envelope['status'] ?? null);
        self::assertStringNotContainsString('Theirs', json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    public function testARecordOfAnotherTableCannotBeOpenedThroughTheUrl(): void
    {
        [$page, $list] = $this->formList('qa_lsec_other_table');
        $otherTable = $this->grid->table('qa_lsec_other_table_data', ['title' => 'text']);
        $stray = $this->grid->row($otherTable, $this->userId('qa.user@selfhelp.test'), ['title' => 'Stray']);
        $token = $this->loginAsQaUser();

        $envelope = $this->state($page, $list, 'list.record=' . $stray, $token);

        self::assertSame(404, $envelope['status'] ?? null, 'A record number is only meaningful inside the list\'s own table.');
    }

    public function testAListIsOnlyServedOnAPageThatHoldsIt(): void
    {
        [$pageA, $listA] = $this->formList('qa_lsec_page_a');
        [$pageB] = $this->formList('qa_lsec_page_b');
        $token = $this->loginAsQaUser();

        $envelope = $this->jsonRequest('GET', '/cms-api/v1/lists/' . $listA->getId() . '/state?page_id=' . $pageB->getId(), null, $token);

        self::assertSame(403, $envelope['status'] ?? null, 'A list is never read through another page\'s access.');
        $this->assertEnvelopeSuccess($this->state($pageA, $listA, '', $token));
    }

    public function testAPageTheVisitorMayNotOpenGivesNoList(): void
    {
        [$page, $list] = $this->formList('qa_lsec_private');
        $this->db->executeStatement('DELETE FROM page_acl_groups WHERE id_pages = :page', ['page' => $page->getId()]);
        $this->pages->invalidatePageScopedCaches();
        $token = $this->loginAsQaUser();

        $envelope = $this->state($page, $list, '', $token);

        self::assertSame(404, $envelope['status'] ?? null);
    }

    public function testAListThatDoesNotExistAnswersLikeOneOnAnotherPage(): void
    {
        [$page] = $this->formList('qa_lsec_unknown');
        $token = $this->loginAsQaUser();

        self::assertSame(
            403,
            $this->jsonRequest('GET', '/cms-api/v1/lists/99999999/state?page_id=' . $page->getId(), null, $token)['status'] ?? null,
            'Nobody can find out whether a list exists on a page they cannot read it through.',
        );
    }

    public function testAClientCannotChooseWhatAQuickActionWrites(): void
    {
        [$page, $list, $tableId] = $this->formList('qa_lsec_mass_assign', [
            'quick_actions' => json_encode([['id' => 'done', 'label' => 'Done', 'set' => ['status' => 'done']]], JSON_THROW_ON_ERROR),
        ]);
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();

        $envelope = $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $mine . '/actions/done',
            ['page_id' => (int) $page->getId(), 'expected_version' => 1, 'set' => ['title' => 'Hacked'], 'id_users' => 1],
            $token,
        );

        self::assertSame(400, $envelope['status'] ?? null, 'The request carries nothing but the page and the version it was based on.');
        self::assertSame('Mine', $this->cell($mine, 'title'));
        self::assertSame('open', $this->cell($mine, 'status'));
    }

    public function testASharedListNeedsTheTablesUpdatePermissionToChangeSomeoneElsesRecord(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lsec_shared_write', [
            'own_entries_only' => '0',
            'list_columns' => '[{"field":"title"},{"field":"status"}]',
            'quick_actions' => json_encode([['id' => 'done', 'label' => 'Done', 'set' => ['status' => 'done']]], JSON_THROW_ON_ERROR),
        ]);
        $theirs = $this->grid->row($tableId, $this->userId('qa.admin@selfhelp.test'), ['title' => 'Theirs', 'status' => 'open']);
        $token = $this->loginAsQaUser();

        $update = $this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['title' => 'Mine now', 'status' => 'open'],
            'update_based_on' => ['record_id' => $theirs],
        ], $token);
        self::assertSame(403, $update['status'] ?? null, 'Seeing a record on a shared list does not allow changing it.');

        $action = $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $theirs . '/actions/done',
            ['page_id' => (int) $page->getId(), 'expected_version' => 1],
            $token,
        );
        self::assertSame(403, $action['status'] ?? null, 'A quick action is an update and needs the same permission.');

        $delete = $this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', [
            'record_id' => $theirs,
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $list->getId(),
        ], $token);
        self::assertSame(403, $delete['status'] ?? null);

        self::assertSame('Theirs', $this->cell($theirs, 'title'));
        self::assertSame('open', $this->cell($theirs, 'status'));
        self::assertSame(1, self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $theirs])));
    }

    public function testHistoryShowsNothingAboutColumnsTheListDoesNotShow(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lsec_history_hidden', [
            'show_history' => '1',
            'list_columns' => '[{"field":"title"}]',
        ]);
        $token = $this->loginAsQaUser();
        $recordId = self::coerceInt($this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['title' => 'Visible title', 'status' => 'private-status-1'],
        ], $token))['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['title' => 'Visible title', 'status' => 'private-status-2'],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => 1,
        ], $token));

        $history = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $this->listUrl($list, $page, '/records/' . $recordId . '/history'), null, $token));
        $version1 = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $this->listUrl($list, $page, '/records/' . $recordId . '/versions/1'), null, $token));
        $compare = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $this->listUrl($list, $page, '/records/' . $recordId . '/compare', 'from=1&to=2'), null, $token));

        foreach ([$history, $version1, $compare] as $answer) {
            self::assertStringNotContainsString('private-status', json_encode($answer, JSON_THROW_ON_ERROR), 'A value of a column the list does not show never appears in the history.');
        }
        self::assertSame([], $this->listOf($compare, 'changes'), 'The only change was in a hidden column.');
        self::assertSame(1, $history['total'] ?? null, 'The edit that touched only a hidden column is not an event the reader may see.');
        self::assertSame(['title'], array_column($this->listOf($this->at($version1, 'state'), 'fields'), 'field_key'));
    }

    public function testRestoringNeverBringsBackAColumnTheListDoesNotShow(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lsec_restore_hidden', [
            'show_history' => '1',
            'list_columns' => '[{"field":"title"}]',
        ]);
        $token = $this->loginAsQaUser();
        $recordId = self::coerceInt($this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['title' => 'First', 'status' => 'kept-1'],
        ], $token))['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/forms/update', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['title' => 'Second', 'status' => 'kept-2'],
            'update_based_on' => ['record_id' => $recordId],
            'expected_version' => 1,
        ], $token));

        $restored = $this->assertEnvelopeSuccess($this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/restore',
            ['page_id' => (int) $page->getId(), 'version' => 1, 'expected_version' => 2],
            $token,
        ));

        self::assertSame(3, $restored['version'] ?? null);
        self::assertSame('First', $this->cell($recordId, 'title'));
        self::assertSame('kept-2', $this->cell($recordId, 'status'), 'The restore only touched what the list shows.');
    }

    // -- the form payload is untrusted --------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function rowMetadata(): iterable
    {
        yield 'the owner' => ['id_users', '1'];
        yield 'the trigger type id' => ['id_action_trigger_types', '1'];
        yield 'the trigger type name' => ['triggerType', 'deleted'];
        yield 'the table' => ['id_data_tables', '1'];
        yield 'the record number' => ['record_id', '1'];
        yield 'the entry date' => ['entry_date', '2000-01-01 00:00:00'];
        yield 'the version' => ['version', '99'];
        yield 'the timestamp' => ['timestamp', '2000-01-01 00:00:00'];
        yield 'the row id' => ['id', '1'];
        yield 'the user name' => ['user_name', 'admin'];
    }

    #[DataProvider('rowMetadata')]
    public function testAFormPayloadCannotSetTheOwnerOrAnyRowMetadataOnAnyRoute(string $key, string $value): void
    {
        [$page, , $tableId, $form] = $this->formList('qa_lsec_meta');
        $watchers = $this->watchTable($tableId, 'qa_lsec_meta');
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Original', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        $before = $this->footprintOf($tableId, $watchers);
        $common = ['page_id' => (int) $page->getId(), 'section_id' => (int) $form->getId()];

        $answers = [
            'a JSON submit' => $this->jsonRequest('POST', '/cms-api/v1/forms/submit', $common + ['form_data' => ['title' => 'New', $key => $value]], $token),
            'a JSON update' => $this->jsonRequest('PUT', '/cms-api/v1/forms/update', $common + ['form_data' => ['title' => 'Changed', $key => $value], 'update_based_on' => ['record_id' => $mine], 'expected_version' => 1], $token),
            'a multipart submit' => $this->multipart('POST', '/cms-api/v1/forms/submit', $common + ['title' => 'New', $key => $value], $token),
            'a multipart update' => $this->multipart('PUT', '/cms-api/v1/forms/update', $common + ['title' => 'Changed', $key => $value, 'update_based_on' => json_encode(['record_id' => $mine], JSON_THROW_ON_ERROR), 'expected_version' => '1'], $token),
        ];

        foreach ($answers as $how => $envelope) {
            self::assertSame(400, $envelope['status'] ?? null, $how . ': ' . json_encode($envelope, JSON_THROW_ON_ERROR));
            self::assertSame('protected_field', $this->at($envelope, 'data')['reason'] ?? null, $how);
        }
        self::assertSame($before, $this->footprintOf($tableId, $watchers), 'Nothing was written, no owner changed, no revision was made and no action was scheduled.');
    }

    public function testAGuestCannotSetTheOwnerOfAnEntryEither(): void
    {
        [$page, $form] = $this->pages->createFormPage('qa_lsec_guest_meta', openAccess: true);

        $envelope = $this->jsonRequest('POST', '/cms-api/v1/forms/submit', [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['qa_answer' => 'x', 'id_users' => $this->userId('qa.admin@selfhelp.test')],
        ]);

        self::assertSame(400, $envelope['status'] ?? null, json_encode($envelope, JSON_THROW_ON_ERROR));
        self::assertSame('protected_field', $this->at($envelope, 'data')['reason'] ?? null);
        self::assertSame(0, self::coerceInt($this->db->fetchOne(
            'SELECT COUNT(*) FROM data_rows r JOIN data_tables t ON t.id = r.id_data_tables WHERE t.name = :name',
            ['name' => (string) $form->getId()],
        )), 'A guest\'s refused entry is not stored under anyone.');
    }

    public function testAnInputTheFormDoesNotDeclareIsRefusedOnEveryRoute(): void
    {
        [$page, , $tableId, $form, $keys] = $this->declaredList('qa_lsec_unknown_input', [
            'title' => ['style' => 'text-input'],
            'status' => ['style' => 'text-input'],
        ]);
        // A column that holds data but has no input in the form: the form cannot write it.
        $this->grid->column($tableId, 'legacy_column');
        $watchers = $this->watchTable($tableId, 'qa_lsec_unknown_input');
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), [$keys['title'] => 'Original', $keys['status'] => 'open', 'legacy_column' => 'kept']);
        $token = $this->loginAsQaUser();
        $before = $this->footprintOf($tableId, $watchers);
        $common = ['page_id' => (int) $page->getId(), 'section_id' => (int) $form->getId()];

        foreach (['ghost', 'section_999999999', 'legacy_column'] as $name) {
            $answers = [
                'a JSON submit' => $this->jsonRequest('POST', '/cms-api/v1/forms/submit', $common + ['form_data' => [$keys['title'] => 'New', $name => 'x']], $token),
                'a JSON update' => $this->jsonRequest('PUT', '/cms-api/v1/forms/update', $common + ['form_data' => [$keys['title'] => 'Changed', $name => 'x'], 'update_based_on' => ['record_id' => $mine], 'expected_version' => 1], $token),
                'a multipart submit' => $this->multipart('POST', '/cms-api/v1/forms/submit', $common + [$keys['title'] => 'New', $name => 'x'], $token),
                'a multipart update' => $this->multipart('PUT', '/cms-api/v1/forms/update', $common + [$keys['title'] => 'Changed', $name => 'x', 'update_based_on' => json_encode(['record_id' => $mine], JSON_THROW_ON_ERROR), 'expected_version' => '1'], $token),
            ];
            foreach ($answers as $how => $envelope) {
                self::assertSame(400, $envelope['status'] ?? null, $name . ' by ' . $how . ': ' . json_encode($envelope, JSON_THROW_ON_ERROR));
                self::assertSame('unknown_field', $this->at($envelope, 'data')['reason'] ?? null, $name . ' by ' . $how);
            }
        }
        self::assertSame($before, $this->footprintOf($tableId, $watchers));
    }

    // -- the dataset of the list bounds every way of writing --------------------------

    public function testTheFixedBoundaryHoldsForEveryWayOfChangingAnEntry(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lsec_boundary', [
            'list_fixed_filter' => '{"mode":"and","children":[{"column":"status","operator":"eq","value":"open"}]}',
            'show_history' => '1',
            'quick_actions' => json_encode([['id' => 'done', 'label' => 'Done', 'set' => ['status' => 'done']]], JSON_THROW_ON_ERROR),
        ]);
        $watchers = $this->watchTable($tableId, 'qa_lsec_boundary');
        $owner = $this->userId('qa.user@selfhelp.test');
        $outside = $this->grid->row($tableId, $owner, ['title' => 'Outside', 'status' => 'closed']);
        $token = $this->loginAsQaUser();
        $before = $this->footprintOf($tableId, $watchers);
        $common = ['page_id' => (int) $page->getId()];
        $url = '/cms-api/v1/lists/' . $list->getId() . '/records/' . $outside;

        $answers = [
            'an update by record number' => $this->jsonRequest('PUT', '/cms-api/v1/forms/update', $common + ['section_id' => (int) $form->getId(), 'form_data' => ['title' => 'Changed'], 'update_based_on' => ['record_id' => $outside], 'expected_version' => 1], $token),
            'an update that selects by a field' => $this->jsonRequest('PUT', '/cms-api/v1/forms/update', $common + ['section_id' => (int) $form->getId(), 'form_data' => ['title' => 'Changed'], 'update_based_on' => ['title' => 'Outside'], 'expected_version' => 1], $token),
            'a delete' => $this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', $common + ['section_id' => (int) $list->getId(), 'record_id' => $outside, 'expected_version' => 1], $token),
            'a quick action' => $this->jsonRequest('POST', $url . '/actions/done', $common + ['expected_version' => 1], $token),
            'a restore' => $this->jsonRequest('POST', $url . '/restore', $common + ['version' => 1, 'expected_version' => 1], $token),
            'the history' => $this->jsonRequest('GET', $url . '/history?page_id=' . $page->getId(), null, $token),
            'a version' => $this->jsonRequest('GET', $url . '/versions/1?page_id=' . $page->getId(), null, $token),
            'a comparison' => $this->jsonRequest('GET', $url . '/compare?page_id=' . $page->getId() . '&from=1&to=1', null, $token),
        ];

        foreach ($answers as $how => $envelope) {
            self::assertSame(404, $envelope['status'] ?? null, $how . ' reached a record outside the list: ' . json_encode($envelope, JSON_THROW_ON_ERROR));
        }
        self::assertSame($before, $this->footprintOf($tableId, $watchers), 'A record outside the dataset is neither changed nor read.');
    }

    public function testAnEntryInsideTheBoundaryMayLeaveItAndThenIsOutOfReach(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lsec_transition', [
            'list_fixed_filter' => '{"mode":"and","children":[{"column":"status","operator":"eq","value":"open"}]}',
        ]);
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Inside', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        $update = static fn (string $status, int $version): array => [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['status' => $status],
            'update_based_on' => ['record_id' => $mine],
            'expected_version' => $version,
        ];

        $left = $this->jsonRequest('PUT', '/cms-api/v1/forms/update', $update('closed', 1), $token);
        self::assertSame(200, $left['status'] ?? null, 'Closing an open entry is the intended transition: it is judged by where the entry is, not where it goes.');
        self::assertSame('closed', $this->cell($mine, 'status'));

        $again = $this->jsonRequest('PUT', '/cms-api/v1/forms/update', $update('open', 2), $token);
        self::assertSame(404, $again['status'] ?? null, 'Once outside, the list cannot reach it any more.');
        self::assertSame('closed', $this->cell($mine, 'status'));
    }

    // -- the page's access is one right: select -----------------------------------------

    public function testAParticipantWhoMaySeeThePageUsesEveryOfferedOperationOnTheirOwnEntries(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lsec_select_only', [
            'show_history' => '1',
            'quick_actions' => json_encode([['id' => 'done', 'label' => 'Done', 'set' => ['status' => 'done']]], JSON_THROW_ON_ERROR),
        ]);
        $this->selectOnly($page);
        $token = $this->loginAsQaUser();
        $common = ['page_id' => (int) $page->getId()];

        $created = $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/forms/submit', $common + ['section_id' => (int) $form->getId(), 'form_data' => ['title' => 'Mine', 'status' => 'open']], $token));
        $recordId = self::coerceInt($created['record_id'] ?? null);
        $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        $this->assertEnvelopeSuccess($this->jsonRequest('PUT', '/cms-api/v1/forms/update', $common + ['section_id' => (int) $form->getId(), 'form_data' => ['title' => 'Edited'], 'update_based_on' => ['record_id' => $recordId], 'expected_version' => 1], $token));
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/actions/done', $common + ['expected_version' => 2], $token));
        $this->assertEnvelopeSuccess($this->jsonRequest('GET', $this->listUrl($list, $page, '/records/' . $recordId . '/history'), null, $token));
        $this->assertEnvelopeSuccess($this->jsonRequest('POST', '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/restore', $common + ['version' => 1, 'expected_version' => 3], $token));
        self::assertSame('Mine', $this->cell($recordId, 'title'));
        $this->assertEnvelopeSuccess($this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', $common + ['section_id' => (int) $list->getId(), 'record_id' => $recordId, 'expected_version' => 4], $token));

        self::assertSame(1, self::coerceInt($this->db->fetchOne('SELECT acl_select FROM page_acl_groups WHERE id_pages = :p LIMIT 1', ['p' => $page->getId()])));
        self::assertSame(0, self::coerceInt($this->db->fetchOne('SELECT acl_insert + acl_update + acl_delete FROM page_acl_groups WHERE id_pages = :p LIMIT 1', ['p' => $page->getId()])), 'The group holds the select right only.');
        self::assertSame($tableId, self::coerceInt($this->db->fetchOne('SELECT id_data_tables FROM data_rows WHERE id = :id', ['id' => $recordId])));
    }

    public function testAParticipantWhoLostTheSelectRightReachesNothingOfTheList(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lsec_revoked', [
            'show_history' => '1',
            'quick_actions' => json_encode([['id' => 'done', 'label' => 'Done', 'set' => ['status' => 'done']]], JSON_THROW_ON_ERROR),
        ]);
        $watchers = $this->watchTable($tableId, 'qa_lsec_revoked');
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        $this->db->executeStatement('UPDATE page_acl_groups SET acl_select = 0 WHERE id_pages = :p', ['p' => $page->getId()]);
        $this->pages->invalidatePageScopedCaches();
        $before = $this->footprintOf($tableId, $watchers);
        $common = ['page_id' => (int) $page->getId()];
        $url = '/cms-api/v1/lists/' . $list->getId() . '/records/' . $mine;

        $answers = [
            'the state' => $this->state($page, $list, '', $token),
            'a submit' => $this->jsonRequest('POST', '/cms-api/v1/forms/submit', $common + ['section_id' => (int) $form->getId(), 'form_data' => ['title' => 'New']], $token),
            'an update' => $this->jsonRequest('PUT', '/cms-api/v1/forms/update', $common + ['section_id' => (int) $form->getId(), 'form_data' => ['title' => 'Changed'], 'update_based_on' => ['record_id' => $mine], 'expected_version' => 1], $token),
            'a delete' => $this->jsonRequest('DELETE', '/cms-api/v1/forms/delete', $common + ['section_id' => (int) $list->getId(), 'record_id' => $mine, 'expected_version' => 1], $token),
            'a quick action' => $this->jsonRequest('POST', $url . '/actions/done', $common + ['expected_version' => 1], $token),
            'the history' => $this->jsonRequest('GET', $url . '/history?page_id=' . $page->getId(), null, $token),
            'a restore' => $this->jsonRequest('POST', $url . '/restore', $common + ['version' => 1, 'expected_version' => 1], $token),
        ];

        foreach ($answers as $how => $envelope) {
            self::assertContains($envelope['status'] ?? null, [403, 404], $how . ': ' . json_encode($envelope, JSON_THROW_ON_ERROR));
        }
        self::assertSame($before, $this->footprintOf($tableId, $watchers));
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * A page the group may see and nothing else: no insert, update or delete bit.
     */
    private function selectOnly(Page $page): void
    {
        $this->db->executeStatement('UPDATE page_acl_groups SET acl_select = 1, acl_insert = 0, acl_update = 0, acl_delete = 0 WHERE id_pages = :p', ['p' => $page->getId()]);
        $this->pages->invalidatePageScopedCaches();
    }

    /**
     * @param array<string, mixed> $fields the flat fields of a multipart body
     * @return array<string, mixed>
     */
    private function multipart(string $method, string $uri, array $fields, string $token): array
    {
        $this->client->request($method, $uri, $fields, [], ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_Authorization' => 'Bearer ' . $token]);

        return $this->decode($this->client->getResponse());
    }

    private function listUrl(Section $list, Page $page, string $path, string $query = ''): string
    {
        return '/cms-api/v1/lists/' . $list->getId() . $path . '?page_id=' . $page->getId() . ($query === '' ? '' : '&' . $query);
    }

    private function cell(int $recordId, string $key): ?string
    {
        $value = $this->db->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols WHERE c.id_data_rows = :row AND col.field_key = :key AND c.id_languages = 1',
            ['row' => $recordId, 'key' => $key],
        );

        return $value === false ? null : self::coerceString($value);
    }
}
