<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Entity\DataTable;
use App\Service\CMS\DataColumnService;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\LookupService;
use App\Tests\Support\QaKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A plugin stores uploads as core form files without a form: a file for one
 * cell of an existing record (also for a guest), a column declared with the
 * value type `file`, and a cell that keeps the key inside a richer answer. The
 * core then delivers, lists and erases these files like a form's.
 */
#[Group('form-files')]
final class PluginFileColumnTest extends QaKernelTestCase
{
    private const TABLE = 'qa_plugin_files';

    private DataService $data;
    private FormFileUploadService $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->data = $this->service(DataService::class);
        $this->files = $this->service(FormFileUploadService::class);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->files->storageRoot() . '/guest');
        parent::tearDown();
    }

    public function testAGuestUploadForAPluginColumnIsDeliveredListedAndErasedWithItsTable(): void
    {
        $recordId = $this->save(['qa_note' => 'started']);
        $table = $this->table();
        $tableId = (int) $table->getId();

        $key = $this->files->storeRecordFile($this->upload('track.gpx', '<?xml version="1.0"?><gpx version="1.1"><trk/></gpx>'), $tableId, $recordId, 'qa_track', null);
        self::assertStringStartsWith(FormFileUploadService::UPLOAD_DIR . 'guest/table_' . $tableId . '/record_' . $recordId . '/field_qa_track/', $key);
        self::assertTrue($this->files->isRecordFileKey($key, $tableId, $recordId));
        self::assertFalse($this->files->isRecordFileKey($key, $tableId, $recordId + 1), 'another record may not claim the file');
        self::assertFalse($this->files->isRecordFileKey($key, $tableId + 1, $recordId), 'another table may not claim the file');
        self::assertFalse($this->files->isRecordFileKey(str_replace('/field_', '/../field_', $key), $tableId, $recordId));

        $this->service(DataColumnService::class)->markFileColumns($table, ['qa_track']);
        $answer = json_encode(['key' => $key, 'pointCount' => 3], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->save(['qa_track' => $answer], $recordId);

        $this->em->clear();
        $columns = $this->service(DataTableService::class)->getColumns(self::TABLE);
        self::assertIsArray($columns);
        $isFile = array_column($columns, 'isFile', 'fieldKey');
        self::assertTrue($isFile['qa_track'], 'the declared file column is a file column');
        self::assertFalse($isFile['qa_note']);

        self::assertSame([$key], FormFileUploadService::keysIn($answer), 'the key is found inside the answer object');
        $found = $this->files->findRecordFiles($recordId, 'qa_track');
        self::assertNotNull($found);
        self::assertCount(1, $found['absolutePaths']);
        self::assertNull($found['ownerId']);
        self::assertCount(1, $this->files->findTableFiles(self::TABLE, [$recordId]), 'the zip download includes it');
        $path = $found['absolutePaths'][0];

        $this->service(DataTableService::class)->deleteDataTable(self::TABLE);

        self::assertFileDoesNotExist($path, 'deleting the table erases the file');
    }

    public function testARefusedTypeIsNotStored(): void
    {
        $recordId = $this->save(['qa_note' => 'started']);

        $this->expectExceptionMessage('File type not allowed');
        $this->files->storeRecordFile($this->upload('run.exe', 'MZ'), (int) $this->table()->getId(), $recordId, 'qa_track', null);
    }

    /**
     * @param array<string, string> $data
     */
    private function save(array $data, ?int $recordId = null): int
    {
        $result = $this->data->saveData(
            self::TABLE,
            $data + ['trigger_type' => LookupService::ACTION_TRIGGER_TYPES_UPDATED],
            LookupService::TRANSACTION_BY_BY_SYSTEM,
            $recordId !== null ? ['record_id' => $recordId] : null,
            false,
        );
        self::assertIsInt($result);

        return $result;
    }

    private function table(): DataTable
    {
        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => self::TABLE]);
        self::assertInstanceOf(DataTable::class, $table);

        return $table;
    }

    private function upload(string $name, string $body): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'qa_');
        file_put_contents($tmp, $body);

        return new UploadedFile($tmp, $name, null, null, true);
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
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
