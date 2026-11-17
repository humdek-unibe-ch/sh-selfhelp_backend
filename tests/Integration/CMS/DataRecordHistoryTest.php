<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\User;
use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\CMS\DataRevision\DataRecordHistoryService;
use App\Service\CMS\DataRevision\DataRevisionService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataRevision\RestorePlan;
use App\Service\CMS\DataService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Reading a record's history and restoring an earlier version of it.
 *
 * A restore is a new, validated write: it appends a revision (never rewinds),
 * keeps the owner, needs the version the person saw, and applies nothing partially.
 * The history that clients receive hides fields the reader may not see and never
 * carries a storage path.
 */
#[Group('form-list')]
final class DataRecordHistoryTest extends QaKernelTestCase
{
    private DataService $dataService;
    private DataRevisionService $revisions;
    private DataRecordHistoryService $history;
    private FormFileUploadService $files;
    private UserFactory $users;
    private Connection $db;

    /** @var list<int> user ids whose upload directory the test created */
    private array $uploadDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataService = $this->service(DataService::class);
        $this->revisions = $this->service(DataRevisionService::class);
        $this->files = $this->service(FormFileUploadService::class);
        // Built by hand: until a controller injects it the container drops the (otherwise unused) service.
        $this->history = new DataRecordHistoryService($this->revisions, $this->files);
        $this->users = new UserFactory(
            $this->em,
            $this->service(UserPasswordHasherInterface::class),
            $this->service(LookupService::class),
        );
        $this->db = $this->em->getConnection();
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->uploadDirectories) as $userId) {
            $this->removeTree($this->files->storageRoot() . '/user_' . $userId);
        }
        parent::tearDown();
    }

    // -- restore --------------------------------------------------------

    public function testRestoreAppendsARevisionKeepsTheOwnerAndLeavesTheHistoryWhole(): void
    {
        [$owner, $ownerEmail] = $this->newUser('owner');
        [$editor, $editorEmail] = $this->newUser('editor');

        $this->actAs($ownerEmail);
        $recordId = $this->save('qa_hist_basic', ['title' => 'T1', 'note' => 'N1']);
        $this->actAs($editorEmail);
        $this->save('qa_hist_basic', ['title' => 'T2'], $recordId);
        $this->save('qa_hist_basic', ['note' => 'N3'], $recordId);
        $tableId = $this->tableId('qa_hist_basic');
        self::assertSame(3, $this->versionOf($recordId));

        $plan = $this->dataService->previewRestore($tableId, $recordId, 1, null, false);
        self::assertTrue($plan->isRestorable());
        self::assertSame(3, $plan->currentVersion);
        self::assertEqualsCanonicalizing(['title', 'note'], array_column($plan->changes, 'field_key'));
        self::assertSame(3, $this->versionOf($recordId), 'A preview writes nothing.');

        $version = $this->dataService->restoreRecord(
            $tableId,
            $recordId,
            1,
            null,
            false,
            new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 3),
        );
        $this->em->clear();

        self::assertSame(4, $version);
        self::assertSame(4, $this->versionOf($recordId));
        self::assertSame('T1', $this->currentValue($recordId, 'title'));
        self::assertSame('N1', $this->currentValue($recordId, 'note'));
        self::assertSame($owner, $this->ownerOf($recordId), 'A restore changes what the record says, not whose it is.');

        $items = $this->revisions->history($recordId)['items'];
        self::assertSame(['restore', 'update', 'update', 'create'], array_column($items, 'operation'));
        $top = $items[0];
        self::assertSame(4, $top['version']);
        self::assertSame(1, $top['restored_from_version']);
        self::assertSame(LookupService::DATA_REVISION_SOURCES_FORM, $top['source']);
        self::assertSame(2, $top['changed_fields']);
        self::assertIsArray($top['actor']);
        self::assertSame($editor, $top['actor']['id']);

        // Nothing was rewound: every earlier state can still be read.
        self::assertSame('T2', $this->revisions->stateAt($recordId, 2)['title|1']->value);
        self::assertSame('N3', $this->revisions->stateAt($recordId, 3)['note|1']->value);
        self::assertSame('T1', $this->revisions->stateAt($recordId, 4)['title|1']->value);
        self::assertSame('N1', $this->revisions->stateAt($recordId, 4)['note|1']->value);
    }

    public function testARestoreNeedsTheVersionThePersonSawAndRefusesAStaleOne(): void
    {
        [, $ownerEmail] = $this->newUser('stale_owner');
        $this->actAs($ownerEmail);
        $recordId = $this->save('qa_hist_stale', ['title' => 'T1']);
        $this->save('qa_hist_stale', ['title' => 'T2'], $recordId);
        $tableId = $this->tableId('qa_hist_stale');

        try {
            $this->dataService->restoreRecord($tableId, $recordId, 1, null, false, new RecordWriteContext());
            self::fail('A restore without expected_version must be refused.');
        } catch (ServiceException $e) {
            self::assertSame(400, $e->getCode());
        }

        try {
            $this->dataService->restoreRecord($tableId, $recordId, 1, null, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 1));
            self::fail('A restore based on an older version must be refused.');
        } catch (ServiceException $e) {
            self::assertSame(409, $e->getCode());
            $data = $e->getData();
            self::assertIsArray($data);
            self::assertSame('stale_version', $data['reason']);
            self::assertSame(1, $data['expected_version']);
            self::assertSame(2, $data['current_version']);
        }

        $this->em->clear();
        self::assertSame('T2', $this->currentValue($recordId, 'title'));
        self::assertSame(2, $this->versionOf($recordId));
        self::assertSame(2, $this->revisionCount($recordId));
    }

    public function testRestoringWhatTheRecordAlreadyHoldsCreatesNoRevision(): void
    {
        [, $ownerEmail] = $this->newUser('noop_owner');
        $this->actAs($ownerEmail);
        $recordId = $this->save('qa_hist_noop', ['title' => 'T1']);
        $this->save('qa_hist_noop', ['title' => 'T2'], $recordId);
        $tableId = $this->tableId('qa_hist_noop');

        $plan = $this->dataService->previewRestore($tableId, $recordId, 2, null, false);
        self::assertSame([], $plan->changes);

        $version = $this->dataService->restoreRecord($tableId, $recordId, 2, null, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2));

        self::assertSame(2, $version, 'Nothing differed: the version does not move.');
        self::assertSame(2, $this->revisionCount($recordId));
    }

    public function testARestoreRemovesWhatTheOldVersionDidNotHave(): void
    {
        [, $ownerEmail] = $this->newUser('remove_owner');
        $this->actAs($ownerEmail);
        $recordId = $this->save('qa_hist_remove', ['title' => 'A']);
        $this->save('qa_hist_remove', ['extra' => 'X'], $recordId);
        $tableId = $this->tableId('qa_hist_remove');

        $this->dataService->restoreRecord($tableId, $recordId, 1, null, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2));
        $this->em->clear();

        self::assertNull($this->currentValue($recordId, 'extra'), 'The field the old version did not have is gone again.');
        self::assertSame('A', $this->currentValue($recordId, 'title'));
        self::assertSame(1, $this->scalarCount('SELECT COUNT(*) FROM data_rows WHERE id = :id', ['id' => $recordId]), 'Removing a cell never removes the record.');

        $changes = $this->revisions->revisionChanges($recordId, 3);
        self::assertSame(['extra'], array_column($changes, 'field_key'));
        self::assertSame('removed', $changes[0]['change']);
        self::assertSame('X', $changes[0]['before']);
        self::assertSame('X', $this->revisions->stateAt($recordId, 2)['extra|1']->value, 'The removed value stays in the history.');
    }

    public function testARestoreThatCannotBeAppliedChangesNothingAndNamesWhatBlocksIt(): void
    {
        [$owner, $ownerEmail] = $this->newUser('blocked_owner');
        $this->actAs($ownerEmail);
        $old = $this->putFile($owner, 'old.txt');
        $new = $this->putFile($owner, 'new.txt');
        $recordId = $this->save('qa_hist_blocked', ['title' => 'not a number', 'doc' => $old, 'note' => 'N1', 'gone' => 'G1']);
        $this->save('qa_hist_blocked', ['title' => '42', 'doc' => $new, 'note' => 'N2', 'gone' => 'G2'], $recordId);
        $tableId = $this->tableId('qa_hist_blocked');

        // The world moved on after version 1: the column became numeric, the old
        // file was erased, and a column went away.
        $numberType = $this->service(LookupService::class)->getLookupIdByCode(LookupService::DATA_COL_VALUE_TYPES, LookupService::DATA_COL_VALUE_TYPES_NUMBER);
        self::assertNotNull($numberType);
        $this->db->executeStatement(
            "UPDATE data_cols SET id_value_type = :type WHERE id_data_tables = :t AND field_key = 'title'",
            ['type' => $numberType, 't' => $tableId],
        );
        unlink($this->absolute($old));
        $this->db->executeStatement("DELETE FROM data_cols WHERE id_data_tables = :t AND field_key = 'gone'", ['t' => $tableId]);
        $this->em->clear();

        $plan = $this->dataService->previewRestore($tableId, $recordId, 1, null, false);
        self::assertFalse($plan->isRestorable());
        $reasons = [];
        foreach ($plan->blockers as $blocker) {
            $reasons[$blocker['field_key']] = $blocker['reason'];
        }
        ksort($reasons);
        self::assertSame(
            ['doc' => RestorePlan::REASON_FILE_UNAVAILABLE, 'gone' => RestorePlan::REASON_FIELD_MISSING, 'title' => RestorePlan::REASON_INVALID_VALUE],
            $reasons,
        );
        self::assertSame(['note'], array_column($plan->changes, 'field_key'), 'What can be restored is still listed.');
        $fileBlocker = array_values(array_filter($plan->blockers, static fn (array $b): bool => $b['field_key'] === 'doc'));
        self::assertSame('old.txt', $fileBlocker[0]['detail'], 'The blocker names the file, not its storage key.');

        // All or nothing.
        try {
            $this->dataService->restoreRecord($tableId, $recordId, 1, null, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2));
            self::fail('A blocked restore must be refused.');
        } catch (ServiceException $e) {
            self::assertSame(422, $e->getCode());
            $data = $e->getData();
            self::assertIsArray($data);
            self::assertSame('restore_blocked', $data['reason']);
            self::assertIsArray($data['blockers']);
            self::assertCount(3, $data['blockers']);
        }
        $this->em->clear();
        self::assertSame('N2', $this->currentValue($recordId, 'note'));
        self::assertSame('42', $this->currentValue($recordId, 'title'));
        self::assertSame(2, $this->versionOf($recordId));

        // An explicit choice of the fields that can be restored is allowed.
        $version = $this->dataService->restoreRecord($tableId, $recordId, 1, ['note'], false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2));
        $this->em->clear();
        self::assertSame(3, $version);
        self::assertSame('N1', $this->currentValue($recordId, 'note'));
        self::assertSame('42', $this->currentValue($recordId, 'title'), 'A field outside the chosen subset is untouched.');
        self::assertSame($new, $this->currentValue($recordId, 'doc'));

        // What leaves the server names no storage path.
        $encoded = json_encode($plan->toClient($this->history), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('uploads/', $encoded);
        self::assertStringNotContainsString('user_' . $owner, $encoded);
    }

    public function testAnOwnerOnlyRestoreRefusesSomeoneElsesRecord(): void
    {
        [, $ownerEmail] = $this->newUser('own_only_owner');
        [, $strangerEmail] = $this->newUser('own_only_stranger');
        $this->actAs($ownerEmail);
        $recordId = $this->save('qa_hist_own', ['title' => 'T1']);
        $this->save('qa_hist_own', ['title' => 'T2'], $recordId);
        $tableId = $this->tableId('qa_hist_own');

        $this->actAs($strangerEmail);
        foreach (['preview', 'restore'] as $kind) {
            try {
                $kind === 'preview'
                    ? $this->dataService->previewRestore($tableId, $recordId, 1, null, true)
                    : $this->dataService->restoreRecord($tableId, $recordId, 1, null, true, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2));
                self::fail("A stranger's $kind of an own-only record must be refused.");
            } catch (ServiceException $e) {
                self::assertSame(403, $e->getCode());
            }
        }

        // A record of ANOTHER table is "not found" through this table.
        $this->actAs($ownerEmail);
        $this->save('qa_hist_other_table', ['title' => 'x']);
        try {
            $this->dataService->previewRestore($this->tableId('qa_hist_other_table'), $recordId, 1, null, true);
            self::fail('A record is only reachable through its own table.');
        } catch (ServiceException $e) {
            self::assertSame(404, $e->getCode());
        }

        $this->actAs($ownerEmail);
        $this->dataService->restoreRecord($tableId, $recordId, 1, null, true, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2));
        $this->em->clear();
        self::assertSame('T1', $this->currentValue($recordId, 'title'));
    }

    // -- reading the history -------------------------------------------

    public function testTheHistoryHidesFieldsTheReaderMayNotSeeAndNeverShowsAStoragePath(): void
    {
        [$owner, $ownerEmail] = $this->newUser('reader_owner');
        $this->actAs($ownerEmail);
        $first = $this->putFile($owner, 'first.txt');
        $second = $this->putFile($owner, 'second.txt');
        $recordId = $this->save('qa_hist_read', ['title' => 'T1', 'secret' => 'S1', 'doc' => $first]);
        $this->save('qa_hist_read', ['secret' => 'S2'], $recordId);
        $this->save('qa_hist_read', ['title' => 'T3', 'doc' => $second], $recordId);
        $allowed = ['title', 'doc'];

        // List: an event that touched only a hidden field is not shown at all.
        $all = $this->history->history($recordId, 1, 20, null);
        $visible = $this->history->history($recordId, 1, 20, $allowed);
        self::assertSame(3, $all['total']);
        self::assertSame([3, 2, 1], array_column($all['items'], 'version'));
        self::assertSame(3, $all['current_version']);
        self::assertSame(2, $visible['total']);
        self::assertSame([3, 1], array_column($visible['items'], 'version'));
        self::assertSame([2, 1, 3], array_column($all['items'], 'changed_fields'), 'Newest first: title and file, only the secret, then all three fields at create.');
        self::assertSame([2, 2], array_column($visible['items'], 'changed_fields'), 'The count covers only the fields the reader may see.');
        $none = $this->history->history($recordId, 1, 20, []);
        self::assertSame([1], array_column($none['items'], 'version'), 'A reader of no field still sees that the record was created, but nothing of what it held.');
        self::assertSame([0], array_column($none['items'], 'changed_fields'));

        // One revision, files named and never keyed.
        $revision = $this->history->revision($recordId, 3, $allowed);
        $byKey = [];
        foreach ($revision['changes'] as $change) {
            $byKey[$this->asString($change['field_key'])] = $change;
        }
        self::assertSame(['doc', 'title'], array_keys($byKey));
        self::assertSame('value', $byKey['title']['kind']);
        self::assertSame('T1', $byKey['title']['before']);
        self::assertSame('T3', $byKey['title']['after']);
        self::assertSame('file', $byKey['doc']['kind']);
        self::assertNull($byKey['doc']['before']);
        self::assertSame([['index' => 0, 'name' => 'first.txt', 'available' => true]], $byKey['doc']['before_files']);
        self::assertSame([['index' => 0, 'name' => 'second.txt', 'available' => true]], $byKey['doc']['after_files']);
        self::assertArrayNotHasKey('before_file_keys', $byKey['doc']);
        self::assertArrayNotHasKey('after_file_keys', $byKey['doc']);

        $compare = $this->history->compare($recordId, 1, 3, $allowed);
        self::assertSame(['doc', 'title'], $this->sortedKeys($compare['changes']));
        $state = $this->history->state($recordId, 1, $allowed);
        self::assertSame(['doc', 'title'], $this->sortedKeys($state['fields']));
        $allFields = $this->history->state($recordId, 1, null);
        self::assertSame(['doc', 'secret', 'title'], $this->sortedKeys($allFields['fields']));

        foreach ([$revision, $compare, $state, $visible, $all, $allFields] as $response) {
            $encoded = json_encode($response, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('uploads/', $encoded, 'No response names a storage path.');
            self::assertStringNotContainsString('form-files', $encoded);
        }

        self::assertSame(404, $this->statusOf(fn () => $this->history->revision($recordId, 99)));
        self::assertSame(404, $this->statusOf(fn () => $this->history->compare($recordId, 1, 99)));
    }

    public function testAFileOfAnEarlierVersionStaysDeliverableUntilItIsErased(): void
    {
        [$owner, $ownerEmail] = $this->newUser('file_owner');
        $this->actAs($ownerEmail);
        $first = $this->putFile($owner, 'first.txt');
        $second = $this->putFile($owner, 'second.txt');
        $recordId = $this->save('qa_hist_file', ['title' => 'T', 'secret' => 'S', 'doc' => $first]);
        $this->save('qa_hist_file', ['doc' => $second], $recordId);
        $allowed = ['title', 'doc'];

        // The current file is the second one; the first is history now.
        self::assertSame($second, $this->currentValue($recordId, 'doc'));
        $file = $this->history->historicalFile($recordId, 1, 'doc', 1, 0, $allowed);
        self::assertNotNull($file);
        self::assertSame('first.txt', $file['name']);
        self::assertSame('qa first.txt', file_get_contents($file['absolutePath']));

        // Only the field, version, language and position that exist are reachable.
        self::assertNull($this->history->historicalFile($recordId, 1, 'doc', 1, 1, $allowed), 'There is no second file.');
        self::assertNull($this->history->historicalFile($recordId, 1, 'doc', 2, 0, $allowed), 'There is no value in that language.');
        self::assertNull($this->history->historicalFile($recordId, 1, 'title', 1, 0, $allowed), 'A text field names no file.');
        self::assertNull($this->history->historicalFile($recordId, 1, 'secret', 1, 0, $allowed), 'A field the reader may not see is not reachable through its past.');
        self::assertSame(404, $this->statusOf(fn () => $this->history->historicalFile($recordId, 9, 'doc', 1, 0, $allowed)));

        // Once the file is erased the history says so and delivery stops.
        unlink($this->absolute($first));
        self::assertNull($this->history->historicalFile($recordId, 1, 'doc', 1, 0, $allowed));
        $state = $this->history->state($recordId, 1, $allowed);
        $doc = array_values(array_filter($state['fields'], static fn (array $f): bool => $f['field_key'] === 'doc'));
        self::assertSame([['index' => 0, 'name' => 'first.txt', 'available' => false]], $doc[0]['files']);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @return array{0: int, 1: string} user id and email
     */
    private function newUser(string $name): array
    {
        $email = sprintf('qa.history.%s@selfhelp.test', $name);
        $user = $this->users->createUser($email, 'qa_history_' . $name);

        return [(int) $user->getId(), $email];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(string $table, array $data, ?int $recordId = null): int
    {
        $result = $this->dataService->saveData(
            $table,
            $data + ['trigger_type' => LookupService::ACTION_TRIGGER_TYPES_FINISHED],
            LookupService::TRANSACTION_BY_BY_USER,
            $recordId !== null ? ['record_id' => $recordId] : null,
            false,
        );
        self::assertIsInt($result);

        return $result;
    }

    private function actAs(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $context = $this->service(UserContextService::class);
        $reflection = new \ReflectionClass($context);
        foreach (['userResolved' => false, 'cachedUser' => null] as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($context, $value);
            }
        }
    }

    /** Store a real file in the uploader's directory and return its logical key. */
    private function putFile(int $uploaderId, string $name): string
    {
        $this->uploadDirectories[] = $uploaderId;
        $key = sprintf('uploads/form-files/user_%d/section_900/field_doc/%s', $uploaderId, '1_aabbccdd_' . $name);
        $path = $this->absolute($key);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, 'qa ' . $name);

        return $key;
    }

    private function absolute(string $key): string
    {
        return $this->files->storageRoot() . '/' . substr($key, strlen('uploads/form-files/'));
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }

    private function tableId(string $name): int
    {
        return $this->asInt($this->db->fetchOne('SELECT id FROM data_tables WHERE name = :n', ['n' => $name]));
    }

    private function versionOf(int $recordId): int
    {
        return $this->asInt($this->db->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    private function ownerOf(int $recordId): int
    {
        return $this->asInt($this->db->fetchOne('SELECT id_users FROM data_rows WHERE id = :id', ['id' => $recordId]));
    }

    private function revisionCount(int $recordId): int
    {
        return $this->scalarCount('SELECT COUNT(*) FROM data_revisions WHERE id_data_rows = :id', ['id' => $recordId]);
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
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    private function sortedKeys(array $items): array
    {
        $keys = array_map(fn (array $item): string => $this->asString($item['field_key']), $items);
        sort($keys);

        return $keys;
    }

    /** HTTP status of the ServiceException a call raises (0 when it raises none). */
    private function statusOf(callable $call): int
    {
        try {
            $call();
        } catch (ServiceException $e) {
            return $e->getCode();
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function scalarCount(string $sql, array $params = []): int
    {
        return $this->asInt($this->db->fetchOne($sql, $params));
    }
}
