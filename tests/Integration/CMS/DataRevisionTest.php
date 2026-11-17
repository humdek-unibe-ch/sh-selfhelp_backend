<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\CMS\DataRevision\DataRevisionService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The history of a record and the version protocol, against the real save path
 * and the real database: every persisted change is one revision, a save that
 * changes nothing is none, a stale save is refused with nothing written, and
 * storage stays proportional to what changed.
 */
#[Group('form-list')]
final class DataRevisionTest extends QaKernelTestCase
{
    private DataService $dataService;
    private DataRevisionService $revisions;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataService = $this->service(DataService::class);
        $this->revisions = $this->service(DataRevisionService::class);
        $this->db = $this->em->getConnection();
    }

    public function testCreateWritesVersionOneWithEveryValueTheActorAndTheAuditLink(): void
    {
        $actor = $this->actAs(QaBaselineFixture::QA_USER_EMAIL);

        $recordId = $this->save('qa_rev_create', ['title' => 'Alpha', 'body' => 'Text']);

        self::assertSame(1, $this->versionOf($recordId));
        $revision = $this->revisionRow($recordId, 1);
        self::assertSame('create', $revision['operation']);
        self::assertSame('form', $revision['source']);
        self::assertSame($actor, $this->asInt($revision['id_users']));
        self::assertNull($revision['id_impersonating_users']);
        self::assertSame(0, $this->asInt($revision['is_checkpoint']));
        self::assertNotNull($revision['id_transactions'], 'The revision is linked to the audit event of the same write.');
        self::assertSame(
            'data_tables',
            $this->db->fetchOne('SELECT table_name FROM transactions WHERE id = :id', ['id' => $revision['id_transactions']]),
        );

        $changes = $this->revisions->revisionChanges($recordId, 1);
        self::assertSame(['body', 'title'], array_column($changes, 'field_key'));
        self::assertSame(['added', 'added'], array_column($changes, 'change'));
        self::assertSame(['Text', 'Alpha'], array_column($changes, 'after'));
        self::assertSame([null, null], array_column($changes, 'before'));
    }

    public function testUpdateStoresOnlyWhatChangedAndMovesTheVersionByOne(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_rev_update', ['title' => 'A', 'body' => 'B']);

        $this->save('qa_rev_update', ['title' => 'A2', 'body' => 'B'], $recordId);

        self::assertSame(2, $this->versionOf($recordId));
        self::assertSame('update', $this->revisionRow($recordId, 2)['operation']);
        $changes = $this->revisions->revisionChanges($recordId, 2);
        self::assertCount(1, $changes, 'The unchanged body is not part of the revision.');
        self::assertSame('title', $changes[0]['field_key']);
        self::assertSame('A', $changes[0]['before']);
        self::assertSame('A2', $changes[0]['after']);
        self::assertSame('changed', $changes[0]['change']);

        // Values stored: A, B, A2 - the unchanged B is stored once.
        self::assertSame(3, $this->valueCount($recordId));
    }

    public function testASaveThatChangesNothingCreatesNoRevisionButIsStillAudited(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_rev_noop', ['title' => 'Same']);
        $auditBefore = $this->auditCount();

        $this->save('qa_rev_noop', ['title' => 'Same'], $recordId);

        self::assertSame(1, $this->versionOf($recordId));
        self::assertSame(1, $this->revisionCount($recordId));
        self::assertSame($auditBefore + 1, $this->auditCount(), 'The attempt is still an audit event; it is not a content version.');
    }

    public function testAStaleSaveIsRefusedWithConflictAndNothingIsWritten(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_rev_stale', ['title' => 'v1']);
        $this->save('qa_rev_stale', ['title' => 'v2'], $recordId, expectedVersion: 1);
        self::assertSame(2, $this->versionOf($recordId));

        try {
            $this->save('qa_rev_stale', ['title' => 'lost'], $recordId, expectedVersion: 1);
            self::fail('A save based on version 1 must be refused when the record is at version 2.');
        } catch (ServiceException $e) {
            self::assertSame(409, $e->getCode());
            $data = $e->getData();
            self::assertIsArray($data);
            self::assertSame('stale_version', $data['reason']);
            self::assertSame(1, $data['expected_version']);
            self::assertSame(2, $data['current_version']);
            self::assertSame($recordId, $data['record_id']);
        }

        self::assertSame(2, $this->versionOf($recordId), 'The version did not move.');
        self::assertSame(2, $this->revisionCount($recordId), 'No revision was written.');
        self::assertSame('v2', $this->currentValue($recordId, 'title'), 'The stored data is untouched.');
    }

    public function testASaveBasedOnTheCurrentVersionSucceedsAndAWriterWithoutOneStillAdvancesTheVersion(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_rev_expected', ['title' => 'a']);

        $this->save('qa_rev_expected', ['title' => 'b'], $recordId, expectedVersion: 1);
        $this->save('qa_rev_expected', ['title' => 'c'], $recordId);

        self::assertSame(3, $this->versionOf($recordId));
        self::assertSame('c', $this->currentValue($recordId, 'title'));
    }

    public function testADeleteIsRecordedAndTheHistoryOutlivesIt(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_rev_delete', ['title' => 'doomed']);
        $tableId = $this->tableId('qa_rev_delete');

        $this->dataService->deleteData($tableId, $recordId, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 1));

        self::assertSame(2, $this->versionOf($recordId));
        self::assertSame('delete', $this->revisionRow($recordId, 2)['operation']);
        self::assertSame([], $this->revisions->revisionChanges($recordId, 2), 'A delete changes no value.');
        self::assertSame('doomed', $this->revisions->stateAt($recordId, 2)['title|1']->value, 'The content is still readable from the history.');

        // A delete based on a stale version is refused too.
        $other = $this->save('qa_rev_delete', ['title' => 'other']);
        $this->save('qa_rev_delete', ['title' => 'other2'], $other);
        try {
            $this->dataService->deleteData($tableId, $other, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 1));
            self::fail('The stale delete must be refused.');
        } catch (ServiceException $e) {
            self::assertSame(409, $e->getCode());
        }
        self::assertSame(2, $this->versionOf($other), 'The record was not deleted.');
    }

    public function testARecordFromBeforeTheHistoryGetsABaselineTheFirstTimeItChanges(): void
    {
        $grid = new DataGridFactory($this->db);
        $tableId = $grid->table('qa_rev_legacy', ['title' => 'text', 'body' => 'text']);
        $recordId = $grid->row($tableId, null, ['title' => 'Old title', 'body' => 'Old body']);
        self::assertSame(0, $this->revisionCount($recordId));

        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $this->save('qa_rev_legacy', ['title' => 'New title'], $recordId);

        self::assertSame(2, $this->versionOf($recordId));
        $baseline = $this->revisionRow($recordId, 1);
        self::assertSame('baseline', $baseline['operation']);
        self::assertSame('system', $baseline['source']);
        self::assertNull($baseline['id_users'], 'No earlier authorship is invented.');
        self::assertSame(1, $this->asInt($baseline['is_checkpoint']));
        self::assertSame('update', $this->revisionRow($recordId, 2)['operation']);

        $first = $this->revisions->stateAt($recordId, 1);
        self::assertSame('Old title', $first['title|1']->value);
        self::assertSame('Old body', $first['body|1']->value);
        $second = $this->revisions->stateAt($recordId, 2);
        self::assertSame('New title', $second['title|1']->value);
        self::assertSame('Old body', $second['body|1']->value);
    }

    public function testARevertReusesTheStoredValueInsteadOfStoringItAgain(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_rev_revert', ['title' => 'First']);
        $this->save('qa_rev_revert', ['title' => 'Second'], $recordId);
        $this->save('qa_rev_revert', ['title' => 'First'], $recordId);

        self::assertSame(3, $this->versionOf($recordId));
        self::assertSame(2, $this->valueCount($recordId), 'First and Second: the reverted value is the stored one.');
        self::assertSame(1, $this->valueCountOf($recordId, 'First'));

        $third = $this->revisions->revisionChanges($recordId, 3);
        self::assertSame('Second', $third[0]['before']);
        self::assertSame('First', $third[0]['after']);
    }

    public function testAnUnchangedLargeFieldIsStoredOnceAcrossManyEditsAndEveryVersionRebuilds(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        // 512 KiB: far beyond the 64 KiB preview and any "small" value, yet light enough
        // that the debug SQL log of this test (it logs every parameter) stays small.
        $large = str_repeat('0123456789abcdef', 32768);
        $recordId = $this->save('qa_rev_large', ['attachment' => $large, 'status' => 'S0']);

        $expected = [1 => 'S0'];
        for ($i = 1; $i <= 25; $i++) {
            $this->save('qa_rev_large', ['status' => 'S' . $i], $recordId);
            $expected[$i + 1] = 'S' . $i;
        }

        self::assertSame(26, $this->versionOf($recordId));
        self::assertSame(26, $this->revisionCount($recordId));
        self::assertSame(1, $this->valueCountOf($recordId, $large), 'The large value that never changed is stored exactly once.');
        $sum = $this->db->fetchOne('SELECT SUM(byte_length) FROM data_revision_values WHERE id_data_rows = :id', ['id' => $recordId]);
        self::assertIsNumeric($sum);
        $stored = (int) $sum;
        self::assertLessThan(strlen($large) + 1024, $stored, 'History bytes are the large value once plus small status values.');

        // A checkpoint at version 20 lists the whole state by reference: no new value is stored for it.
        $checkpoint = $this->revisionRow($recordId, DataRevisionService::CHECKPOINT_EVERY);
        self::assertSame(1, $this->asInt($checkpoint['is_checkpoint']));
        self::assertSame(
            2,
            $this->asInt($this->db->fetchOne(
                'SELECT COUNT(*) FROM data_revision_changes WHERE id_data_revisions = :id AND is_snapshot = 1',
                ['id' => $checkpoint['id']],
            )),
            'The checkpoint holds one reference per field.',
        );

        foreach ($expected as $version => $status) {
            $state = $this->revisions->stateAt($recordId, $version);
            self::assertSame($status, $state['status|1']->value, "status at version $version");
            self::assertSame($large, $state['attachment|1']->value, "attachment at version $version");
        }
    }

    public function testTranslatedFieldsKeepTheirLanguage(): void
    {
        $language = $this->asInt($this->db->fetchOne('SELECT id FROM languages WHERE id <> 1 ORDER BY id LIMIT 1'));
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_rev_lang', [
            'headline' => [['language_id' => 1, 'value' => 'Hello'], ['language_id' => $language, 'value' => 'Hallo']],
        ]);

        $this->save('qa_rev_lang', [
            'headline' => [['language_id' => $language, 'value' => 'Hallo Welt']],
        ], $recordId);

        $changes = $this->revisions->revisionChanges($recordId, 2);
        self::assertCount(1, $changes);
        self::assertSame($language, $changes[0]['language_id']);
        self::assertSame('Hallo', $changes[0]['before']);
        self::assertSame('Hallo Welt', $changes[0]['after']);

        $state = $this->revisions->stateAt($recordId, 2);
        self::assertSame('Hello', $state['headline|1']->value);
        self::assertSame('Hallo Welt', $state['headline|' . $language]->value);
        self::assertSame('Hallo', $this->revisions->stateAt($recordId, 1)['headline|' . $language]->value);
    }

    public function testADigestCollisionCanNeverServeWrongContent(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_rev_collision', ['title' => 'seed']);

        // A stored value with the digest and length of "target!!" but other bytes.
        $this->db->executeStatement(
            'INSERT INTO data_revision_values (id_data_rows, content_hash, byte_length, encoding_version, content) VALUES (:r, :h, :l, 1, :c)',
            ['r' => $recordId, 'h' => hash('sha256', 'target!!'), 'l' => 8, 'c' => 'IMPOSTOR'],
        );

        $this->save('qa_rev_collision', ['title' => 'target!!'], $recordId);

        $changes = $this->revisions->revisionChanges($recordId, 2);
        self::assertSame('target!!', $changes[0]['after'], 'The bytes are compared; a matching digest alone is not reuse.');
        self::assertSame('target!!', $this->revisions->stateAt($recordId, 2)['title|1']->value);
    }

    public function testValuesAreNeverSharedBetweenRecords(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $first = $this->save('qa_rev_scope', ['title' => 'shared text']);
        $second = $this->save('qa_rev_scope', ['title' => 'shared text']);

        self::assertSame(1, $this->valueCountOf($first, 'shared text'));
        self::assertSame(1, $this->valueCountOf($second, 'shared text'));
        $ids = array_map($this->asInt(...), $this->db->fetchFirstColumn('SELECT id FROM data_revision_values WHERE id_data_rows IN (:a, :b)', ['a' => $first, 'b' => $second]));
        self::assertCount(2, array_unique($ids), 'Erasing one record can never take content another record relies on.');
    }

    public function testTheHistoryAndTheDataCommitAndRollBackTogether(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $this->db->beginTransaction();
        $recordId = $this->save('qa_rev_rollback', ['title' => 'never committed']);
        self::assertSame(1, $this->revisionCount($recordId));
        $this->db->rollBack();

        self::assertSame(0, $this->revisionCount($recordId));
        self::assertFalse($this->db->fetchOne('SELECT id FROM data_rows WHERE id = :id', ['id' => $recordId]));
        self::assertSame(0, $this->asInt($this->db->fetchOne('SELECT COUNT(*) FROM data_revision_values WHERE id_data_rows = :id', ['id' => $recordId])));
    }

    public function testHistoryIsPagedNewestFirstAndVersionsCanBeCompared(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);
        $recordId = $this->save('qa_rev_history', ['title' => 'T1', 'body' => 'B1']);
        $this->save('qa_rev_history', ['title' => 'T2'], $recordId);
        $this->save('qa_rev_history', ['body' => 'B3'], $recordId);
        $this->save('qa_rev_history', ['title' => 'T4', 'body' => 'B4'], $recordId);

        $firstPage = $this->revisions->history($recordId, 1, 3);
        self::assertSame(4, $firstPage['total']);
        self::assertSame([4, 3, 2], array_column($firstPage['items'], 'version'));
        self::assertSame(2, $firstPage['items'][0]['changed_fields']);
        self::assertSame('update', $firstPage['items'][0]['operation']);
        $actor = $firstPage['items'][0]['actor'];
        self::assertIsArray($actor);
        self::assertSame($this->userId(QaBaselineFixture::QA_USER_EMAIL), $actor['id']);

        $secondPage = $this->revisions->history($recordId, 2, 3);
        self::assertSame([1], array_column($secondPage['items'], 'version'));

        $diff = $this->revisions->compare($recordId, 1, 4);
        $byKey = array_column($diff, null, 'field_key');
        self::assertSame(['T1', 'T4'], [$byKey['title']['before'], $byKey['title']['after']]);
        self::assertSame(['B1', 'B4'], [$byKey['body']['before'], $byKey['body']['after']]);
        self::assertSame([], $this->revisions->compare($recordId, 4, 4));

        $this->expectException(ServiceException::class);
        $this->revisions->stateAt($recordId, 99);
    }

    public function testEveryWriterNamesItsDoorAndAJobOrAnImportDoesNotWaitForAVersion(): void
    {
        $this->actAs(QaBaselineFixture::QA_USER_EMAIL);

        // An import writes the record; the history says so.
        $recordId = $this->saveThrough('qa_rev_doors', ['title' => 'Imported'], new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_IMPORT));
        self::assertSame('import', $this->revisionRow($recordId, 1)['source']);

        // Somebody else moves the record on. A job that never read it writes anyway: it compares no
        // version, takes the record lock and advances the version, and the history names both doors.
        $this->saveThrough('qa_rev_doors', ['title' => 'By hand'], new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_ADMIN), recordId: $recordId);
        $this->saveThrough('qa_rev_doors', ['title' => 'By a job'], new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_JOB), recordId: $recordId);
        self::assertSame(3, $this->versionOf($recordId));
        self::assertSame('admin', $this->revisionRow($recordId, 2)['source']);
        self::assertSame('job', $this->revisionRow($recordId, 3)['source']);
        self::assertSame('By a job', $this->currentValue($recordId, 'title'));
        self::assertSame(['import', 'admin', 'job'], array_map(
            static fn (array $item): mixed => $item['source'],
            array_reverse($this->revisions->history($recordId, 1, 10)['items']),
        ), 'The history lists the door of every change.');

        // A caller that names no door: a person saving is a form, everything else is the platform.
        $byPerson = $this->saveThrough('qa_rev_doors', ['title' => 'A person'], null);
        self::assertSame('form', $this->revisionRow($byPerson, 1)['source']);
        $byPlatform = $this->saveThrough('qa_rev_doors', ['title' => 'The platform'], null, LookupService::TRANSACTION_BY_BY_SYSTEM);
        self::assertSame('system', $this->revisionRow($byPlatform, 1)['source']);
        $byCron = $this->saveThrough('qa_rev_doors', ['title' => 'A cron job'], null, LookupService::TRANSACTION_BY_BY_CRON_JOB);
        self::assertSame('system', $this->revisionRow($byCron, 1)['source']);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * A write through a named door (or none), with no version to compare unless the context carries one.
     *
     * @param array<string, mixed> $data
     */
    private function saveThrough(
        string $table,
        array $data,
        ?RecordWriteContext $context,
        string $transactionBy = LookupService::TRANSACTION_BY_BY_USER,
        ?int $recordId = null,
    ): int {
        $result = $this->dataService->saveData(
            $table,
            $data + ['trigger_type' => LookupService::ACTION_TRIGGER_TYPES_FINISHED],
            $transactionBy,
            $recordId !== null ? ['record_id' => $recordId] : null,
            false,
            null,
            $context,
        );
        self::assertIsInt($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(string $table, array $data, ?int $recordId = null, ?int $expectedVersion = null): int
    {
        $payload = $data + ['trigger_type' => LookupService::ACTION_TRIGGER_TYPES_FINISHED];
        $result = $this->dataService->saveData(
            $table,
            $payload,
            LookupService::TRANSACTION_BY_BY_USER,
            $recordId !== null ? ['record_id' => $recordId] : null,
            false,
            null,
            $expectedVersion !== null ? new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, $expectedVersion) : null,
        );
        self::assertIsInt($result);

        return $result;
    }

    /** Sign the test in as a QA user; returns the user id. */
    private function actAs(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        // The user context remembers the first user it resolved; forget it.
        $context = $this->service(UserContextService::class);
        $reflection = new \ReflectionClass($context);
        foreach (['userResolved' => false, 'cachedUser' => null] as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($context, $value);
            }
        }

        return (int) $user->getId();
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }

    private function tableId(string $name): int
    {
        return $this->asInt($this->db->fetchOne('SELECT id FROM data_tables WHERE name = :n', ['n' => $name]));
    }

    private function versionOf(int $recordId): int
    {
        return $this->asInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    private function revisionCount(int $recordId): int
    {
        return $this->asInt($this->db->fetchOne('SELECT COUNT(*) FROM data_revisions WHERE id_data_rows = :id', ['id' => $recordId]));
    }

    private function valueCount(int $recordId): int
    {
        return $this->asInt($this->db->fetchOne('SELECT COUNT(*) FROM data_revision_values WHERE id_data_rows = :id', ['id' => $recordId]));
    }

    private function valueCountOf(int $recordId, string $content): int
    {
        return $this->asInt($this->db->fetchOne(
            'SELECT COUNT(*) FROM data_revision_values WHERE id_data_rows = :id AND content_hash = :h AND BINARY content = BINARY :c',
            ['id' => $recordId, 'h' => hash('sha256', $content), 'c' => $content],
        ));
    }

    private function auditCount(): int
    {
        return $this->asInt($this->db->fetchOne('SELECT COUNT(*) FROM transactions'));
    }

    private function currentValue(int $recordId, string $fieldKey): ?string
    {
        $value = $this->db->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols dc ON dc.id = c.id_data_cols WHERE c.id_data_rows = :id AND dc.field_key = :k AND c.id_languages = 1',
            ['id' => $recordId, 'k' => $fieldKey],
        );

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionRow(int $recordId, int $version): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT r.*, op.lookup_code AS operation, src.lookup_code AS source FROM data_revisions r'
            . ' JOIN lookups op ON op.id = r.id_operations JOIN lookups src ON src.id = r.id_sources'
            . ' WHERE r.id_data_rows = :id AND r.version = :v',
            ['id' => $recordId, 'v' => $version],
        );
        self::assertIsArray($row, "revision $version of record $recordId");

        return $row;
    }
}
