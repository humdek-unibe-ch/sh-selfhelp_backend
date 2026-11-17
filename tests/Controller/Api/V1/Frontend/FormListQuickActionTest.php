<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Frontend;

use App\Entity\Action;
use App\Entity\DataTable;
use App\Entity\Page;
use App\Entity\Section;
use App\Entity\User;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\ActionFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\InteractsWithFormLists;
use App\Tests\Support\QaWebTestCase;
use PHPUnit\Framework\Attributes\Group as PhpUnitGroup;

/**
 * A quick action is one click that changes a record the way the author defined
 * it. These tests state what it may never do: write anything the author did not
 * define, run on a record that does not qualify, take a record from its owner or
 * from its table, or start the form's actions after the request was refused.
 *
 * The table carries a real action on the "updated" trigger (an immediate e-mail),
 * so a side effect is something the tests can count.
 */
#[PhpUnitGroup('form-list')]
#[PhpUnitGroup('security')]
final class FormListQuickActionTest extends QaWebTestCase
{
    use InteractsWithFormLists;

    private const WHEN_OPEN = ['mode' => 'and', 'children' => [['column' => 'status', 'operator' => 'eq', 'value' => 'open']]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFormLists();
    }

    public function testAnAcceptedActionWritesOneRevisionAndRunsTheTablesActionOnce(): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_accept');
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();

        $data = $this->assertEnvelopeSuccess($this->act($token, $page, $list, $mine, 'done', 1));

        self::assertSame(['record_id' => $mine, 'version' => 2, 'action' => 'done'], $data);
        self::assertSame('done', $this->cell($mine, 'status'));
        self::assertSame('Mine', $this->cell($mine, 'title'), 'Only the column the author assigned changes.');
        self::assertSame(1, $this->jobsOf($watcher), 'The table\'s own action runs once, as it does for an edit.');
        self::assertSame(['quick_action'], $this->sourcesAfterFirstVersion($mine), 'The history records that the change came from a quick action.');
    }

    public function testAStaleVersionIsRefusedAndNothingRuns(): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_stale');
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        $before = $this->snapshot($mine, $watcher);

        $envelope = $this->act($token, $page, $list, $mine, 'done', 7);

        self::assertSame(409, $envelope['status'] ?? null);
        self::assertSame('stale_version', $this->at($envelope, 'data')['reason'] ?? null);
        self::assertSame($before, $this->snapshot($mine, $watcher), 'A refused action leaves the record, its history and the jobs as they were.');
    }

    public function testAConditionThatNoLongerHoldsIsRefusedAndNothingRuns(): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_condition');
        $finished = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Finished', 'status' => 'done']);
        $token = $this->loginAsQaUser();
        $before = $this->snapshot($finished, $watcher);

        // The client may believe the action is offered; the stored record decides.
        $envelope = $this->act($token, $page, $list, $finished, 'done', 1);

        self::assertSame(409, $envelope['status'] ?? null);
        self::assertSame('action_not_available', $this->at($envelope, 'data')['reason'] ?? null);
        self::assertSame($before, $this->snapshot($finished, $watcher));
    }

    public function testAnUnknownActionIsRefusedAndNothingRuns(): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_unknown');
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        $before = $this->snapshot($mine, $watcher);

        $envelope = $this->act($token, $page, $list, $mine, 'reopen', 1);

        self::assertSame(404, $envelope['status'] ?? null);
        self::assertSame('unknown_action', $this->at($envelope, 'data')['reason'] ?? null);
        self::assertSame($before, $this->snapshot($mine, $watcher));
    }

    public function testSomeoneElsesRecordAnswersLikeAMissingOneAndNothingRuns(): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_foreign');
        $theirs = $this->grid->row($tableId, $this->userId('qa.admin@selfhelp.test'), ['title' => 'Theirs', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        $before = $this->snapshot($theirs, $watcher);

        $envelope = $this->act($token, $page, $list, $theirs, 'done', 1);
        $missing = $this->act($token, $page, $list, $theirs + 1000, 'done', 1);

        $status = $envelope['status'] ?? null;
        self::assertSame(404, $status);
        self::assertSame($missing['status'] ?? null, $status, 'The answer does not tell a foreign record from a missing one.');
        self::assertSame($before, $this->snapshot($theirs, $watcher));
    }

    public function testARecordOfAnotherTableAnswersLikeAMissingOne(): void
    {
        [$page, $list, , $watcher] = $this->listWithWatcher('qa_lqa_table');
        $otherTable = $this->grid->table('qa_lqa_table_other', ['title' => 'text', 'status' => 'text']);
        $stray = $this->grid->row($otherTable, $this->userId('qa.user@selfhelp.test'), ['title' => 'Stray', 'status' => 'open']);
        $token = $this->loginAsQaUser();

        $envelope = $this->act($token, $page, $list, $stray, 'done', 1);

        self::assertSame(404, $envelope['status'] ?? null, 'A record number only means something inside the list\'s own table.');
        self::assertSame('open', $this->cell($stray, 'status'));
        self::assertSame(0, $this->jobsOf($watcher));
    }

    public function testADeletedRecordAnswersLikeAMissingOne(): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_deleted');
        $gone = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Gone', 'status' => 'open'], null, LookupService::ACTION_TRIGGER_TYPES_DELETED);
        $token = $this->loginAsQaUser();

        $envelope = $this->act($token, $page, $list, $gone, 'done', 1);

        self::assertSame(404, $envelope['status'] ?? null);
        self::assertSame('open', $this->cell($gone, 'status'));
        self::assertSame(0, $this->jobsOf($watcher));
    }

    public function testAllowUpdateOffStillRunsQuickActions(): void
    {
        // "Allow edit" only gates the form modal; quick actions stay available
        // (foreign-table admin lists turn edit off and still approve / mark done).
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_readonly', ['allow_update' => '0']);
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();

        $data = $this->assertEnvelopeSuccess($this->act($token, $page, $list, $mine, 'done', 1));

        self::assertSame(2, $data['version'] ?? null);
        self::assertSame('done', $this->cell($mine, 'status'));
        self::assertSame(1, $this->jobsOf($watcher));
        $state = $this->assertEnvelopeSuccess($this->state($page, $list, '', $token));
        $row = $this->listOf($state, 'rows')[0] ?? [];
        self::assertFalse($row['can_update'] ?? true, 'Allow edit stays off.');
        self::assertSame([], $row['quick_actions'] ?? null, 'The action no longer applies after status changed.');
    }

    public function testAGuestIsAskedToSignInAndNothingRuns(): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_guest', [], openAccess: true);
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $before = $this->snapshot($mine, $watcher);

        $envelope = $this->act(null, $page, $list, $mine, 'done', 1);

        self::assertContains($envelope['status'] ?? null, [401, 403]);
        self::assertSame($before, $this->snapshot($mine, $watcher));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function forgedBodies(): iterable
    {
        yield 'a patch of values' => [['set' => ['title' => 'Hacked']]];
        yield 'another owner' => [['id_users' => 1]];
        yield 'another table' => [['id_data_tables' => 1, 'table' => 'users']];
        yield 'a record id in the body' => [['record_id' => 1]];
        yield 'another action definition' => [['action' => ['set' => ['status' => 'hacked']]]];
        yield 'a version of the wrong type' => [['expected_version' => 'latest']];
        yield 'a version below one' => [['expected_version' => 0]];
        yield 'a negative version' => [['expected_version' => -1]];
        yield 'a version that is a fraction' => [['expected_version' => 1.5]];
        yield 'a version sent as text, which would be coerced' => [['expected_version' => '1']];
        yield 'a version that is a boolean' => [['expected_version' => true]];
        yield 'a version that is a list' => [['expected_version' => [1]]];
        yield 'a version that is null' => [['expected_version' => null]];
    }

    public function testAnActionThatNamesNoVersionIsRefusedAndNothingRuns(): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_unversioned');
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        $before = $this->snapshot($mine, $watcher);

        $envelope = $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $mine . '/actions/done',
            ['page_id' => (int) $page->getId()],
            $token,
        );

        self::assertSame(400, $envelope['status'] ?? null);
        self::assertStringContainsString('expected_version', json_encode($envelope, JSON_THROW_ON_ERROR), 'The refusal names the missing version.');
        self::assertSame($before, $this->snapshot($mine, $watcher), 'An action is always taken from a version the person has seen.');
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forgedBodies')]
    public function testAForgedBodyIsRefusedBeforeAnythingHappens(array $extra): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_forged');
        $mine = $this->grid->row($tableId, $this->userId('qa.user@selfhelp.test'), ['title' => 'Mine', 'status' => 'open']);
        $token = $this->loginAsQaUser();
        $before = $this->snapshot($mine, $watcher);

        $envelope = $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $mine . '/actions/done',
            $extra + ['page_id' => (int) $page->getId(), 'expected_version' => 1],
            $token,
        );

        self::assertSame(400, $envelope['status'] ?? null, (string) json_encode($extra));
        self::assertSame($before, $this->snapshot($mine, $watcher));
    }

    public function testAnEditorWhoActsOnSomeoneElsesRecordDoesNotBecomeItsOwner(): void
    {
        [$page, $list, $tableId, $watcher] = $this->listWithWatcher('qa_lqa_owner', ['own_entries_only' => '0']);
        $ownerId = $this->userId('qa.admin@selfhelp.test');
        $theirs = $this->grid->row($tableId, $ownerId, ['title' => 'Theirs', 'status' => 'open']);
        $this->grantTableUpdateToTheQaUser($tableId);
        $token = $this->loginAsQaUser();

        $data = $this->assertEnvelopeSuccess($this->act($token, $page, $list, $theirs, 'done', 1));

        self::assertSame(2, $data['version'] ?? null);
        self::assertSame('done', $this->cell($theirs, 'status'));
        self::assertSame($ownerId, self::coerceInt($this->db->fetchOne('SELECT id_users FROM data_rows WHERE id = :id', ['id' => $theirs])), 'The record stays with the person who wrote it.');
        self::assertSame(1, $this->jobsOf($watcher));
    }

    // -- fixtures ---------------------------------------------------------------

    /**
     * A participant list with the action "done" (status open -> done) and a real
     * action on the table that mails the acting user whenever a record is updated.
     *
     * @param array<string, string> $fields list fields that replace this fixture's
     * @return array{0: Page, 1: Section, 2: int, 3: Action}
     */
    private function listWithWatcher(string $prefix, array $fields = [], bool $openAccess = false): array
    {
        [$page, $list, $tableId] = $this->formList($prefix, $fields + [
            'quick_actions' => json_encode([[
                'id' => 'done',
                'label' => 'Mark done',
                'set' => ['status' => 'done'],
                'when' => self::WHEN_OPEN,
            ]], JSON_THROW_ON_ERROR),
        ], $openAccess);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'qa.user@selfhelp.test']);
        self::assertInstanceOf(User::class, $user);
        $user->setReceivesEmails(true);
        $this->em->flush();

        $table = $this->em->find(DataTable::class, $tableId);
        self::assertInstanceOf(DataTable::class, $table);
        $watcher = (new ActionFactory($this->em))->createAction(
            $table,
            LookupService::ACTION_TRIGGER_TYPES_UPDATED,
            ActionFactory::immediateEmailConfig('A record changed', 'A record of the list was updated.'),
            $prefix . '_watcher',
        );

        return [$page, $list, $tableId, $watcher];
    }

    private function grantTableUpdateToTheQaUser(int $tableId): void
    {
        $grants = new RoleDataAccessFactory(
            $this->em,
            $this->service(LookupService::class),
            $this->service(DataAccessSecurityService::class),
        );
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'qa.user@selfhelp.test']);
        self::assertInstanceOf(User::class, $user);
        $role = $grants->createRole('qa_lqa_updater');
        $grants->assignRoleToUser($user, $role);
        $grants->grantDataTableAccess($role, $tableId, DataAccessSecurityService::PERMISSION_READ | DataAccessSecurityService::PERMISSION_UPDATE);
    }

    // -- requests and readings ---------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function act(?string $token, Page $page, Section $list, int $recordId, string $action, int $expectedVersion): array
    {
        return $this->jsonRequest(
            'POST',
            '/cms-api/v1/lists/' . $list->getId() . '/records/' . $recordId . '/actions/' . $action,
            ['page_id' => (int) $page->getId(), 'expected_version' => $expectedVersion],
            $token,
        );
    }

    /**
     * Everything a refused request must leave alone: the record's values and
     * version, its revisions and the jobs of the table's action.
     *
     * @return array{values: array<string, string|null>, version: int, revisions: int, jobs: int}
     */
    private function snapshot(int $recordId, Action $watcher): array
    {
        return [
            'values' => ['title' => $this->cell($recordId, 'title'), 'status' => $this->cell($recordId, 'status')],
            'version' => self::coerceInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId])),
            'revisions' => self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM data_revisions WHERE id_data_rows = :id', ['id' => $recordId])),
            'jobs' => $this->jobsOf($watcher),
        ];
    }

    private function jobsOf(Action $action): int
    {
        return self::coerceInt($this->db->fetchOne('SELECT COUNT(*) FROM scheduled_jobs WHERE id_actions = :id', ['id' => $action->getId()]));
    }

    private function cell(int $recordId, string $key): ?string
    {
        $value = $this->db->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols col ON col.id = c.id_data_cols WHERE c.id_data_rows = :row AND col.field_key = :key AND c.id_languages = 1',
            ['row' => $recordId, 'key' => $key],
        );

        return $value === false ? null : self::coerceString($value);
    }

    /**
     * @return list<string> the sources of every revision after the first one
     */
    private function sourcesAfterFirstVersion(int $recordId): array
    {
        $sources = [];
        foreach ($this->db->fetchFirstColumn(
            'SELECT src.lookup_code FROM data_revisions r JOIN lookups src ON src.id = r.id_sources WHERE r.id_data_rows = :id AND r.version > 1 ORDER BY r.version',
            ['id' => $recordId],
        ) as $source) {
            $sources[] = self::coerceString($source);
        }

        return $sources;
    }
}
