<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\CMS\Admin;

use App\Service\CMS\Admin\AssetStorageService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * How an asset's file changes on disk: staged next to its place, swapped in
 * with one rename, and then either kept or undone depending on whether the
 * database change committed. The file and the row must never disagree, so an
 * undone swap brings the old bytes back (or removes a file that never had a
 * row), and nothing temporary is left behind either way.
 */
final class AssetStorageSwapTest extends TestCase
{
    private const UUID = '3f2c9a52-6a43-4d3e-9c1a-5b7e2d0f8a11';

    private string $root;
    private AssetStorageService $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qa-asset-swap-' . bin2hex(random_bytes(6));
        $this->storage = new AssetStorageService($this->root, new NullLogger());
    }

    protected function tearDown(): void
    {
        foreach ($this->filesInStorage() as $file) {
            @unlink($this->root . '/' . $file);
        }
        @rmdir($this->root);
    }

    public function testASwapReplacesTheBytesAndKeepingItLeavesNothingElse(): void
    {
        $this->put(self::UUID, 'old bytes');

        $swap = $this->storage->swapIn($this->storage->stage($this->upload('new bytes'), self::UUID), self::UUID);
        self::assertSame('new bytes', file_get_contents($this->storage->absolutePath(self::UUID)));

        $swap->keep();

        self::assertSame('new bytes', file_get_contents($this->storage->absolutePath(self::UUID)));
        self::assertSame([self::UUID], $this->filesInStorage(), 'No staged or backup file is left');
    }

    public function testUndoingASwapBringsTheOldBytesBack(): void
    {
        $this->put(self::UUID, 'old bytes');

        $swap = $this->storage->swapIn($this->storage->stage($this->upload('new bytes'), self::UUID), self::UUID);
        self::assertSame('new bytes', file_get_contents($this->storage->absolutePath(self::UUID)));

        $swap->undo();

        self::assertSame('old bytes', file_get_contents($this->storage->absolutePath(self::UUID)));
        self::assertSame([self::UUID], $this->filesInStorage(), 'No staged or backup file is left');
    }

    public function testUndoingASwapOfAnAssetWithoutAFileRemovesTheNewFile(): void
    {
        $swap = $this->storage->swapIn($this->storage->stage($this->upload('new bytes'), self::UUID), self::UUID);
        self::assertFileExists($this->storage->absolutePath(self::UUID));

        $swap->undo();

        self::assertSame([], $this->filesInStorage(), 'A file whose row never committed must not stay');
    }

    public function testAStagedFileIsNotTheAssetsFileUntilItIsSwappedIn(): void
    {
        $this->put(self::UUID, 'old bytes');

        $staged = $this->storage->stage($this->upload('new bytes'), self::UUID);

        self::assertSame('old bytes', file_get_contents($this->storage->absolutePath(self::UUID)), 'Staging changes nothing a reader can see');
        self::assertNull($this->storage->resolveForDelivery(basename($staged)), 'A staged file is not deliverable under its temporary name');

        $this->storage->discardStaged($staged);

        self::assertFileDoesNotExist($staged);
        self::assertSame('old bytes', file_get_contents($this->storage->absolutePath(self::UUID)));
    }

    public function testASwapThatCannotBePutInPlaceLeavesTheOldBytesAndNoBackup(): void
    {
        $this->put(self::UUID, 'old bytes');

        try {
            $this->storage->swapIn($this->root . '/missing-staged-file', self::UUID);
            self::fail('A missing staged file cannot be swapped in');
        } catch (\RuntimeException $e) {
            self::assertSame('Unable to store the file of the asset', $e->getMessage());
        }

        self::assertSame('old bytes', file_get_contents($this->storage->absolutePath(self::UUID)));
        self::assertSame([self::UUID], $this->filesInStorage(), 'The backup made on the way is removed again');
    }

    public function testDeletingAFileThatIsAlreadyGoneIsNotAnError(): void
    {
        $this->put(self::UUID, 'bytes');

        $this->storage->delete(self::UUID);
        self::assertSame([], $this->filesInStorage());

        $this->storage->delete(self::UUID);
        self::assertSame([], $this->filesInStorage());
    }

    private function put(string $uuid, string $bytes): void
    {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
        file_put_contents($this->storage->absolutePath($uuid), $bytes);
    }

    private function upload(string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qa_swap');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'qa-swap.png', null, null, true);
    }

    /**
     * Every file in the storage directory, hidden temporary ones included.
     *
     * @return list<string>
     */
    private function filesInStorage(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        return array_values(array_diff(scandir($this->root) ?: [], ['.', '..']));
    }
}
