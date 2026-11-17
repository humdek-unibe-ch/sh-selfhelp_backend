<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Golden;

use App\Entity\Page;
use App\Entity\Section;
use App\Service\Core\LookupService;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * The participant workflow of an entry-form-list over the real endpoints: add,
 * edit, act with a quick action, read the history, restore and delete. The list
 * state is read after every step, so what a visitor sees is what was written.
 */
#[PhpUnitGroup('golden')]
#[PhpUnitGroup('form-list')]
final class FormListWorkflowTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const SUBMIT = '/cms-api/v1/forms/submit';
    private const UPDATE = '/cms-api/v1/forms/update';
    private const DELETE = '/cms-api/v1/forms/delete';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testAParticipantAddsEditsActsRestoresAndDeletesAnEntry(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lw_flow', [
            'show_history' => '1',
            'quick_actions' => json_encode([[
                'id' => 'done',
                'label' => 'Mark done',
                'confirm' => 'Mark this entry as done?',
                'set' => ['status' => 'done'],
                'when' => ['mode' => 'and', 'children' => [['column' => 'status', 'operator' => 'eq', 'value' => 'open']]],
            ]], JSON_THROW_ON_ERROR),
        ]);
        $token = $this->loginAsQaUser();

        // Add.
        $created = $this->assertEnvelopeSuccess($this->submit($token, $page, $form, ['title' => 'Write report', 'status' => 'open']));
        $recordId = self::coerceInt($created['record_id'] ?? null);
        self::assertSame(1, $created['version'] ?? null);
        $state = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        self::assertSame(['Write report'], $this->titles($state), 'The new entry is in the list at once (the write clears the cached state).');
        $row = $this->listOf($state, 'rows')[0];
        self::assertSame(1, $row['version']);
        self::assertSame(['done'], $row['quick_actions'], 'The action is offered while its condition holds.');
        self::assertTrue($row['can_view_history']);

        // Edit.
        $updated = $this->assertEnvelopeSuccess($this->update($token, $page, $form, $recordId, ['title' => 'Write the report', 'status' => 'open'], 1));
        self::assertSame(2, $updated['version'] ?? null);
        self::assertSame(['Write the report'], $this->titles($this->assertEnvelopeSuccess($this->state($page, $list, '', $token))));

        // A save based on an old version is refused and changes nothing.
        $stale = $this->update($token, $page, $form, $recordId, ['title' => 'Lost edit', 'status' => 'open'], 1);
        self::assertSame(409, $stale['status'] ?? null);
        self::assertSame(['Write the report'], $this->titles($this->assertEnvelopeSuccess($this->state($page, $list, '', $token))));

        // Quick action: based on the version the visitor saw.
        $staleAction = $this->action($token, $page, $list, $recordId, 'done', 1);
        self::assertSame(409, $staleAction['status'] ?? null);
        self::assertSame('stale_version', $this->at($staleAction, 'data')['reason'] ?? null);

        $acted = $this->assertEnvelopeSuccess($this->action($token, $page, $list, $recordId, 'done', 2));
        self::assertSame(3, $acted['version'] ?? null);
        self::assertSame('done', $acted['action'] ?? null);
        self::assertSame('done', $this->cell($recordId, 'status'));
        self::assertSame('Write the report', $this->cell($recordId, 'title'), 'The action assigns only what it names.');

        $again = $this->action($token, $page, $list, $recordId, 'done', 3);
        self::assertSame(409, $again['status'] ?? null);
        self::assertSame('action_not_available', $this->at($again, 'data')['reason'] ?? null, 'The condition is checked on the stored record.');
        $after = $this->listOf($this->assertEnvelopeSuccess($this->state($page, $list, '', $token)), 'rows')[0];
        self::assertSame([], $after['quick_actions']);
        self::assertSame(3, $after['version']);

        $unknown = $this->action($token, $page, $list, $recordId, 'nope', 3);
        self::assertSame(404, $unknown['status'] ?? null);

        // History: three versions, readable and comparable; a restore is a new version.
        $history = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $this->url($list, '/records/' . $recordId . '/history', $page), null, $token));
        self::assertSame(3, $history['total'] ?? null);
        self::assertSame(3, $history['current_version'] ?? null);

        $version = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $this->url($list, '/records/' . $recordId . '/versions/1', $page), null, $token));
        $first = [];
        foreach ($this->listOf($this->at($version, 'state'), 'fields') as $field) {
            $first[self::coerceString($field['field_key'])] = $field['value'];
        }
        ksort($first);
        self::assertSame(['status' => 'open', 'title' => 'Write report'], $first, 'Version 1 is the record as it was added.');

        $compare = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $this->url($list, '/records/' . $recordId . '/compare', $page, 'from=1&to=3'), null, $token));
        $changed = array_column($this->listOf($compare, 'changes'), 'field_key');
        sort($changed);
        self::assertSame(['status', 'title'], $changed);

        $preview = $this->assertEnvelopeSuccess($this->restore($token, $page, $list, $recordId, ['version' => 1, 'dry_run' => true]));
        self::assertTrue($preview['dry_run'] ?? null);
        self::assertSame(3, $this->versionOf($recordId), 'A preview writes nothing.');

        $withoutVersion = $this->restore($token, $page, $list, $recordId, ['version' => 1]);
        self::assertSame(400, $withoutVersion['status'] ?? null, 'A restore needs the version the person saw.');
        $staleRestore = $this->restore($token, $page, $list, $recordId, ['version' => 1, 'expected_version' => 2]);
        self::assertSame(409, $staleRestore['status'] ?? null);

        $restored = $this->assertEnvelopeSuccess($this->restore($token, $page, $list, $recordId, ['version' => 1, 'expected_version' => 3]));
        self::assertSame(4, $restored['version'] ?? null);
        self::assertSame('Write report', $this->cell($recordId, 'title'));
        self::assertSame('open', $this->cell($recordId, 'status'));

        // Delete through the list.
        $staleDelete = $this->delete($token, $page, $list, $recordId, 3);
        self::assertSame(409, $staleDelete['status'] ?? null);
        $deleted = $this->assertEnvelopeSuccess($this->delete($token, $page, $list, $recordId, 4));
        self::assertSame(5, $deleted['version'] ?? null);
        $empty = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        self::assertSame(0, $this->at($empty, 'pagination')['total']);
        self::assertSame([], $this->listOf($empty, 'rows'));
        self::assertSame(
            ['create', 'update', 'update', 'restore', 'delete'],
            $this->db->fetchFirstColumn(
                'SELECT op.lookup_code FROM data_revisions r JOIN lookups op ON op.id = r.id_operations WHERE r.id_data_rows = :id ORDER BY r.version',
                ['id' => $recordId],
            ),
            'Every change, including the action and the restore, is a revision.',
        );
        self::assertSame(
            ['form', 'form', 'quick_action'],
            array_slice($this->db->fetchFirstColumn(
                'SELECT src.lookup_code FROM data_revisions r JOIN lookups src ON src.id = r.id_sources WHERE r.id_data_rows = :id ORDER BY r.version',
                ['id' => $recordId],
            ), 0, 3),
            'The history says through which door each change came.',
        );
        self::assertSame(
            1,
            self::coerceInt($this->db->fetchOne('SELECT restored_from_version FROM data_revisions WHERE id_data_rows = :id AND version = 4', ['id' => $recordId])),
            'A restore names the version it restored.',
        );
        self::assertGreaterThan(0, $tableId);
    }

    public function testTheListDecidesWhichOperationsItsFormMayDo(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lw_switches', ['allow_create' => '0', 'allow_update' => '0', 'allow_delete' => '0']);
        $token = $this->loginAsQaUser();
        $row = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);

        $create = $this->submit($token, $page, $form, ['title' => 'New', 'status' => 'open']);
        self::assertSame(403, $create['status'] ?? null);
        self::assertSame('operation_disabled', $this->at($create, 'data')['reason'] ?? null);

        $update = $this->update($token, $page, $form, $row, ['title' => 'Changed', 'status' => 'open'], null);
        self::assertSame(403, $update['status'] ?? null);
        self::assertSame('Mine', $this->cell($row, 'title'));

        $delete = $this->delete($token, $page, $list, $row, null);
        self::assertSame(403, $delete['status'] ?? null);
        self::assertFalse($this->isDeleted($row));

        $state = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        $rowPayload = $this->listOf($state, 'rows')[0];
        self::assertFalse($rowPayload['can_update']);
        self::assertFalse($rowPayload['can_delete']);
        self::assertFalse($this->at($state, 'capabilities')['create']);
    }

    public function testAListWritesOnlyIntoItsOwnTable(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lw_mismatch');
        $other = $this->grid->table('qa_lw_other_table', ['title' => 'text', 'status' => 'text']);
        $this->pages->setSectionField($list, 'data_table', (string) $other);
        $token = $this->loginAsQaUser();

        $create = $this->submit($token, $page, $form, ['title' => 'New', 'status' => 'open']);
        self::assertSame(400, $create['status'] ?? null, 'The inline form writes into another table than the list shows.');
        self::assertSame('table_mismatch', $this->at($create, 'data')['reason'] ?? null);
        self::assertSame(0, self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM data_rows WHERE id_data_tables IN (:a, :b)', ['a' => $tableId, 'b' => $other])));
    }

    public function testAnOwnEntriesListNeverLetsAVisitorTouchSomeoneElsesEntry(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lw_foreign', [
            'show_history' => '1',
            'quick_actions' => json_encode([['id' => 'done', 'label' => 'Done', 'set' => ['status' => 'done']]], JSON_THROW_ON_ERROR),
        ]);
        $theirs = $this->grid->row($tableId, $this->userId('qa.admin@selfhelp.test'), ['title' => 'Theirs', 'status' => 'open']);
        $token = $this->loginAsQaUser();

        self::assertSame(404, $this->update($token, $page, $form, $theirs, ['title' => 'Mine now', 'status' => 'open'], null)['status'] ?? null, 'The record is not in the visitor\'s dataset.');
        self::assertSame(404, $this->delete($token, $page, $list, $theirs, null)['status'] ?? null, 'The record is not in the visitor\'s dataset.');
        self::assertSame(404, $this->action($token, $page, $list, $theirs, 'done', 1)['status'] ?? null, 'The record is not in the visitor\'s dataset.');
        self::assertSame(404, $this->jsonRequest('GET', $this->url($list, '/records/' . $theirs . '/history', $page), null, $token)['status'] ?? null);
        self::assertSame(404, $this->jsonRequest('GET', $this->url($list, '/records/' . $theirs . '/versions/1', $page), null, $token)['status'] ?? null);
        self::assertSame(404, $this->restore($token, $page, $list, $theirs, ['version' => 1, 'expected_version' => 1])['status'] ?? null);

        self::assertSame('Theirs', $this->cell($theirs, 'title'));
        self::assertFalse($this->isDeleted($theirs));
        self::assertSame(1, $this->versionOf($theirs));
    }

    public function testHistoryIsOnlyOfferedWhereTheListShowsIt(): void
    {
        [$page, $list, $tableId, $form] = $this->formList('qa_lw_nohistory');
        $token = $this->loginAsQaUser();
        $created = $this->assertEnvelopeSuccess($this->submit($token, $page, $form, ['title' => 'One', 'status' => 'open']));
        $recordId = self::coerceInt($created['record_id'] ?? null);

        $row = $this->listOf($this->assertEnvelopeSuccess($this->state($page, $list, '', $token)), 'rows')[0];
        self::assertFalse($row['can_view_history']);
        self::assertFalse($this->at($this->assertEnvelopeSuccess($this->state($page, $list, '', $token)), 'capabilities')['history']);

        $history = $this->jsonRequest('GET', $this->url($list, '/records/' . $recordId . '/history', $page), null, $token);
        self::assertSame(403, $history['status'] ?? null);
        self::assertSame('history_disabled', $this->at($history, 'data')['reason'] ?? null);
        self::assertGreaterThan(0, $tableId);
    }

    public function testAGuestCannotWriteThroughAListOfOwnEntries(): void
    {
        [$page, $list, , $form] = $this->formList('qa_lw_guest', [], openAccess: true);

        $create = $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => ['title' => 'Anonymous', 'status' => 'open'],
        ]);
        self::assertContains($create['status'] ?? null, [401, 403]);
        self::assertSame(0, self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM data_rows r JOIN data_tables t ON t.id = r.id_data_tables WHERE t.name = :n', ['n' => (string) $form->getId()])));
        self::assertGreaterThan(0, (int) $list->getId());
    }

    // -- helpers ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function submit(string $token, Page $page, Section $form, array $values): array
    {
        return $this->jsonRequest('POST', self::SUBMIT, [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => $values,
        ], $token);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function update(string $token, Page $page, Section $form, int $recordId, array $values, ?int $expectedVersion): array
    {
        $body = [
            'page_id' => (int) $page->getId(),
            'section_id' => (int) $form->getId(),
            'form_data' => $values,
            'update_based_on' => ['record_id' => $recordId],
        ];
        if ($expectedVersion !== null) {
            $body['expected_version'] = $expectedVersion;
        }

        return $this->jsonRequest('PUT', self::UPDATE, $body, $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $token, Page $page, Section $list, int $recordId, ?int $expectedVersion): array
    {
        $body = ['record_id' => $recordId, 'page_id' => (int) $page->getId(), 'section_id' => (int) $list->getId()];
        if ($expectedVersion !== null) {
            $body['expected_version'] = $expectedVersion;
        }

        return $this->jsonRequest('DELETE', self::DELETE, $body, $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function action(string $token, Page $page, Section $list, int $recordId, string $action, int $expectedVersion): array
    {
        return $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/actions/' . $action,
            ['page_id' => (int) $page->getId(), 'expected_version' => $expectedVersion],
            $token,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function restore(string $token, Page $page, Section $list, int $recordId, array $body): array
    {
        return $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/restore',
            ['page_id' => (int) $page->getId()] + $body,
            $token,
        );
    }

    private function url(Section $list, string $path, Page $page, string $query = ''): string
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

    private function versionOf(int $recordId): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    private function isDeleted(int $recordId): bool
    {
        $deletedId = $this->service(LookupService::class)->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);

        return self::coerceInt($this->db->fetchOne('SELECT id_action_trigger_types FROM data_rows WHERE id = :id', ['id' => $recordId])) === $deletedId;
    }
}
