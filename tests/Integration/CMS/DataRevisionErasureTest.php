<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\User;
use App\Service\Auth\UserContextService;
use App\Service\CMS\DataRevision\DataRevisionService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\LookupService;
use App\Service\Core\UserDataCleanerService;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Erasing data erases its history and the files that history kept; nothing else.
 *
 * History is retained until someone erases data on purpose (a user's data, a data
 * table, a deleted column). These tests prove what an erasure removes (revisions,
 * changes, values, and every stored file the data or its history named, wherever
 * it was uploaded) and what it must not touch (other people's records and their
 * files, a file another value still names, the content of a record somebody else
 * owns and this user only edited).
 */
#[Group('form-list')]
final class DataRevisionErasureTest extends QaKernelTestCase
{
    private DataService $dataService;
    private DataRevisionService $revisions;
    private UserDataCleanerService $cleaner;
    private DataTableService $tables;
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
        $this->cleaner = $this->service(UserDataCleanerService::class);
        $this->tables = $this->service(DataTableService::class);
        $this->files = $this->service(FormFileUploadService::class);
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

    public function testErasingAnOwnerRemovesTheirRecordsHistoryAndEveryFileTheHistoryKept(): void
    {
        [$owner, $ownerEmail] = $this->newUser('owner');
        [$editor, $editorEmail] = $this->newUser('editor');
        [$bystander, $bystanderEmail] = $this->newUser('bystander');

        // The owner's record: three versions and the file moves twice. The second
        // file was uploaded by the editor, so it lives in the EDITOR's directory.
        $this->actAs($ownerEmail);
        $first = $this->putFile($owner, 'first.txt');
        $recordId = $this->save('qa_er_owner', ['title' => 'T1', 'doc' => $first]);
        $this->actAs($editorEmail);
        $second = $this->putFile($editor, 'second.txt');
        $this->save('qa_er_owner', ['doc' => $second], $recordId);
        $this->actAs($ownerEmail);
        $third = $this->putFile($owner, 'third.txt');
        $this->save('qa_er_owner', ['title' => 'T3', 'doc' => $third], $recordId);

        // Things that must survive: the editor's own record and file, and a third
        // person's record that the owner edited.
        $this->actAs($editorEmail);
        $kept = $this->putFile($editor, 'kept.txt');
        $keptRecord = $this->save('qa_er_owner', ['title' => 'kept', 'doc' => $kept]);
        $this->actAs($bystanderEmail);
        $otherRecord = $this->save('qa_er_owner', ['title' => 'other']);
        $this->actAs($ownerEmail);
        $this->save('qa_er_owner', ['title' => 'other, edited by the owner'], $otherRecord);

        self::assertSame(3, $this->revisionCount($recordId));
        self::assertGreaterThan(0, $this->valueCount($recordId));

        $removal = $this->cleaner->removeData($owner);
        $removedFiles = $this->cleaner->finishAfterCommit($owner, $removal);
        $this->em->clear();

        self::assertSame(3, $removal->removed['data_revisions']);
        self::assertGreaterThan(0, $removal->removed['data_revision_changes']);
        self::assertGreaterThan(0, $removal->removed['data_revision_values']);
        self::assertSame(1, $removal->removed['data_revision_actor_links'], 'The owner\'s edit of someone else\'s record is detached, not deleted.');
        self::assertSame(1, $removal->removed['data_rows'], 'The owner owned one record.');

        // The owner's record has no history left in any table.
        self::assertSame(0, $this->revisionCount($recordId));
        self::assertSame(0, $this->changeCount($recordId));
        self::assertSame(0, $this->valueCount($recordId));
        self::assertFalse($this->db->fetchOne('SELECT id FROM data_rows WHERE id = :id', ['id' => $recordId]));

        // Every file the record or its history named is gone, wherever it was stored ...
        self::assertFileDoesNotExist($this->absolute($first));
        self::assertFileDoesNotExist($this->absolute($second), 'The editor\'s upload onto the owner\'s record is part of the owner\'s data.');
        self::assertFileDoesNotExist($this->absolute($third));
        self::assertSame(3, $removedFiles);
        // ... the editor's own file and record are untouched ...
        self::assertFileExists($this->absolute($kept));
        self::assertSame(1, $this->revisionCount($keptRecord));
        self::assertSame($kept, $this->currentValue($keptRecord, 'doc'));
        // ... and the third person's record keeps its content and history, only the name is detached.
        self::assertSame(2, $this->revisionCount($otherRecord));
        self::assertSame(2, $this->versionOf($otherRecord));
        $edit = $this->db->fetchAssociative('SELECT id_users, id_impersonating_users FROM data_revisions WHERE id_data_rows = :id AND version = 2', ['id' => $otherRecord]);
        self::assertIsArray($edit);
        self::assertNull($edit['id_users'], 'The erased person is no longer named on the history of somebody else\'s record.');
        self::assertSame('other, edited by the owner', $this->revisions->stateAt($otherRecord, 2)['title|1']->value, 'What they wrote stays: it belongs to the other record.');
        self::assertSame('other', $this->revisions->stateAt($otherRecord, 1)['title|1']->value);
    }

    public function testAFileThatASurvivingValueStillNamesIsNotDeleted(): void
    {
        [$owner, $ownerEmail] = $this->newUser('copy_owner');
        [$other, $otherEmail] = $this->newUser('copy_other');

        // The file lives in the OTHER person's directory, so removing the owner's own
        // directory (an existing rule) cannot hide what the key-based removal does.
        $shared = $this->putFile($other, 'shared.txt');
        $this->actAs($ownerEmail);
        $this->save('qa_er_copy', ['doc' => $shared]);
        $this->actAs($otherEmail);
        // e.g. a duplicated page whose sample rows were exported and imported again
        $copyRecord = $this->save('qa_er_copy', ['doc' => $shared]);

        $removal = $this->cleaner->removeData($owner);
        $removedFiles = $this->cleaner->finishAfterCommit($owner, $removal);

        self::assertSame([$shared], $removal->fileKeys, 'The erased record named the file.');
        self::assertSame(0, $removedFiles, 'A key that a surviving cell still names is never deleted.');
        self::assertFileExists($this->absolute($shared));
        self::assertSame($shared, $this->currentValue($copyRecord, 'doc'));

        // Once nothing names it any more, the same removal deletes it.
        $this->db->executeStatement('DELETE FROM data_rows WHERE id = :id', ['id' => $copyRecord]);
        self::assertSame(1, $this->files->deleteUnreferencedFiles([$shared]));
        self::assertFileDoesNotExist($this->absolute($shared));
    }

    public function testAFileThatOnlyTheHistoryNamesIsKeptWhileTheRecordLivesAndRemovedWithIt(): void
    {
        [$owner, $ownerEmail] = $this->newUser('hist_owner');
        [$uploader] = $this->newUser('hist_uploader');
        $this->actAs($ownerEmail);
        $old = $this->putFile($uploader, 'old.txt');
        $new = $this->putFile($uploader, 'new.txt');
        $recordId = $this->save('qa_er_hist', ['doc' => $old]);
        $this->save('qa_er_hist', ['doc' => $new], $recordId);
        $tableId = $this->tableId('qa_er_hist');

        // Replacing a file and soft-deleting the record keep every file: the history may restore it.
        self::assertFileExists($this->absolute($old), 'Replacing a file does not delete the old one.');
        $this->dataService->deleteData($tableId, $recordId, false, new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_FORM, 2));
        self::assertFileExists($this->absolute($old));
        self::assertFileExists($this->absolute($new), 'A soft delete does not delete files.');
        self::assertSame(0, $this->files->deleteUnreferencedFiles([$old]), 'The record still lives in the history: its old file is reachable.');
        self::assertFileExists($this->absolute($old));

        // Explicit erasure of the data removes both, though they sit in another person's directory.
        $removal = $this->cleaner->removeData($owner);
        $this->cleaner->finishAfterCommit($owner, $removal);
        self::assertFileDoesNotExist($this->absolute($old));
        self::assertFileDoesNotExist($this->absolute($new));
    }

    public function testErasingAnEditorOnlyDetachesTheNameFromSomeoneElsesRecord(): void
    {
        [, $ownerEmail] = $this->newUser('rec_owner');
        [$editor, $editorEmail] = $this->newUser('rec_editor');

        $this->actAs($ownerEmail);
        $recordId = $this->save('qa_er_editor', ['title' => 'by owner']);
        $this->actAs($editorEmail);
        $this->save('qa_er_editor', ['title' => 'by editor'], $recordId);
        self::assertSame($editor, $this->revisionActor($recordId, 2));

        $removal = $this->cleaner->removeData($editor);
        $this->cleaner->finishAfterCommit($editor, $removal);
        $this->em->clear();

        self::assertSame(0, $removal->removed['data_revisions'], 'The editor owned no record.');
        self::assertSame(1, $removal->removed['data_revision_actor_links']);
        self::assertSame(2, $this->revisionCount($recordId), 'The owner\'s history is whole.');
        self::assertSame(2, $this->versionOf($recordId));
        self::assertNull($this->revisionActor($recordId, 2));
        self::assertSame('by editor', $this->currentValue($recordId, 'title'));
        self::assertSame('by editor', $this->revisions->stateAt($recordId, 2)['title|1']->value);
        self::assertSame('by owner', $this->revisions->stateAt($recordId, 1)['title|1']->value);
    }

    public function testDeletingATableErasesItsHistoryAndFilesAndNothingOfOtherTables(): void
    {
        [$owner, $ownerEmail] = $this->newUser('table_owner');
        $this->actAs($ownerEmail);
        $a1 = $this->putFile($owner, 'a1.txt');
        $a2 = $this->putFile($owner, 'a2.txt');
        $b1 = $this->putFile($owner, 'b1.txt');
        $record = $this->save('qa_er_table_gone', ['doc' => $a1]);
        $this->save('qa_er_table_gone', ['doc' => $a2], $record);
        $second = $this->save('qa_er_table_gone', ['doc' => $a1]);
        $survivor = $this->save('qa_er_table_stays', ['doc' => $b1]);
        $goneId = $this->tableId('qa_er_table_gone');
        self::assertGreaterThan(0, $this->valueCount($record) + $this->valueCount($second));

        self::assertTrue($this->tables->deleteDataTable('qa_er_table_gone'));
        $this->em->clear();

        self::assertSame(0, $this->scalarCount('SELECT COUNT(*) FROM data_revisions WHERE id_data_tables = :id', ['id' => $goneId]));
        self::assertSame(0, $this->valueCount($record) + $this->valueCount($second));
        self::assertFileDoesNotExist($this->absolute($a1));
        self::assertFileDoesNotExist($this->absolute($a2));
        self::assertFileExists($this->absolute($b1), 'Another table\'s file is untouched.');
        self::assertSame(1, $this->revisionCount($survivor));
        self::assertSame($b1, $this->currentValue($survivor, 'doc'));
    }

    public function testErasingATableReportsWhatItRemovedWithoutRelyingOnTheCascade(): void
    {
        [$owner, $ownerEmail] = $this->newUser('report_owner');
        $this->actAs($ownerEmail);
        $first = $this->putFile($owner, 'r1.txt');
        $second = $this->putFile($owner, 'r2.txt');
        $record = $this->save('qa_er_report', ['title' => 'a', 'doc' => $first]);
        $this->save('qa_er_report', ['doc' => $second], $record);
        $tableId = $this->tableId('qa_er_report');
        $values = $this->valueCount($record);

        $erased = $this->service(\App\Service\CMS\DataRevision\DataRevisionErasureService::class)->eraseTable($tableId);

        self::assertSame(2, $erased->revisions);
        self::assertSame(3, $erased->changes, 'Three field changes: title and doc on create, doc on update.');
        self::assertSame($values, $erased->values);
        self::assertEqualsCanonicalizing([$first, $second], $erased->fileKeys);
        // The records are still there (the caller deletes the table); their history is gone.
        self::assertSame(1, $this->scalarCount('SELECT COUNT(*) FROM data_rows WHERE id = :id', ['id' => $record]));
        self::assertSame(0, $this->revisionCount($record));
        self::assertSame(0, $this->valueCount($record));
    }

    public function testDeletingAColumnErasesThatFieldsHistoryAndFilesButKeepsTheOtherFields(): void
    {
        [$owner, $ownerEmail] = $this->newUser('col_owner');
        $this->actAs($ownerEmail);

        // 25 versions: a checkpoint exists at version 20, holding BOTH fields by reference.
        $keys = [];
        for ($i = 0; $i <= 24; $i++) {
            $keys[$i] = $this->putFile($owner, "doc$i.txt");
        }
        $recordId = $this->save('qa_er_cols', ['title' => 'T0', 'doc' => $keys[0]]);
        for ($i = 1; $i <= 24; $i++) {
            $this->save('qa_er_cols', $i % 2 === 0 ? ['doc' => $keys[$i], 'title' => "T$i"] : ['doc' => $keys[$i]], $recordId);
        }
        self::assertSame(25, $this->versionOf($recordId));
        self::assertSame(1, $this->scalarCount('SELECT COUNT(*) FROM data_revisions WHERE id_data_rows = :id AND is_checkpoint = 1', ['id' => $recordId]));
        self::assertGreaterThan(0, $this->changeCountOfField($recordId, 'doc'));
        $titleChanges = $this->changeCountOfField($recordId, 'title');

        $this->em->clear();
        self::assertSame(1, $this->tables->deleteColumns('qa_er_cols', ['doc']));
        $this->em->clear();

        // Deleting a column deletes that column's values - not the records that had one.
        self::assertSame(1, $this->scalarCount('SELECT COUNT(*) FROM data_rows WHERE id = :id', ['id' => $recordId]));
        self::assertSame('T24', $this->currentValue($recordId, 'title'));
        self::assertNull($this->currentValue($recordId, 'doc'));
        self::assertSame(0, $this->scalarCount(
            "SELECT COUNT(*) FROM data_cols WHERE id_data_tables = :t AND field_key = 'doc'",
            ['t' => $this->tableId('qa_er_cols')],
        ));

        self::assertSame(0, $this->changeCountOfField($recordId, 'doc'), 'No change, checkpoint entry included, names the deleted field.');
        self::assertSame($titleChanges, $this->changeCountOfField($recordId, 'title'), 'The other field\'s history is whole.');
        self::assertSame(25, $this->revisionCount($recordId), 'The revision headers stay as non-content audit metadata.');
        // Every value left belongs to the title.
        $left = $this->db->fetchFirstColumn('SELECT content FROM data_revision_values WHERE id_data_rows = :id', ['id' => $recordId]);
        self::assertNotSame([], $left);
        foreach ($left as $content) {
            self::assertIsString($content);
            self::assertMatchesRegularExpression('/^T\d+$/', $content, 'Only title values remain; no file key survives in the history.');
        }
        foreach ($keys as $key) {
            self::assertFileDoesNotExist($this->absolute($key));
        }

        // The history still reads: before and after the checkpoint.
        $atCheckpoint = $this->revisions->stateAt($recordId, 20);
        self::assertSame('T18', $atCheckpoint['title|1']->value, 'Version 20 holds the title set at version 19.');
        self::assertArrayNotHasKey('doc|1', $atCheckpoint);
        $latest = $this->revisions->stateAt($recordId, 25);
        self::assertSame('T24', $latest['title|1']->value);
        self::assertArrayNotHasKey('doc|1', $latest);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @return array{0: int, 1: string} user id and email
     */
    private function newUser(string $name): array
    {
        $email = sprintf('qa.erase.%s@selfhelp.test', $name);
        $user = $this->users->createUser($email, 'qa_erase_' . $name);

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

    private function revisionCount(int $recordId): int
    {
        return $this->scalarCount('SELECT COUNT(*) FROM data_revisions WHERE id_data_rows = :id', ['id' => $recordId]);
    }

    private function changeCount(int $recordId): int
    {
        return $this->scalarCount(
            'SELECT COUNT(*) FROM data_revision_changes ch JOIN data_revisions rv ON rv.id = ch.id_data_revisions WHERE rv.id_data_rows = :id',
            ['id' => $recordId],
        );
    }

    private function changeCountOfField(int $recordId, string $fieldKey): int
    {
        return $this->scalarCount(
            'SELECT COUNT(*) FROM data_revision_changes ch JOIN data_revisions rv ON rv.id = ch.id_data_revisions'
            . ' WHERE rv.id_data_rows = :id AND ch.field_key = :key',
            ['id' => $recordId, 'key' => $fieldKey],
        );
    }

    private function valueCount(int $recordId): int
    {
        return $this->scalarCount('SELECT COUNT(*) FROM data_revision_values WHERE id_data_rows = :id', ['id' => $recordId]);
    }

    private function revisionActor(int $recordId, int $version): ?int
    {
        $actor = $this->db->fetchOne('SELECT id_users FROM data_revisions WHERE id_data_rows = :id AND version = :v', ['id' => $recordId, 'v' => $version]);

        return $actor === null || $actor === false ? null : $this->asInt($actor);
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
     * @param array<string, mixed> $params
     */
    private function scalarCount(string $sql, array $params = []): int
    {
        return $this->asInt($this->db->fetchOne($sql, $params));
    }
}
