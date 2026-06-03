<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\Exception\ServiceException;
use App\Service\CMS\FormFileUploadService;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * P0 security coverage for {@see FormFileUploadService} — the accept path plus
 * every documented reject path (extension, MIME/extension mismatch, oversize,
 * path-traversal filename) and the cleanup helpers.
 *
 * Files are written under a dedicated `qa` user/section subtree of
 * `public/uploads/form-files/` and removed in tearDown, so the test never leaks
 * real uploaded files (plan "no real file storage").
 */
final class FormFileUploadServiceTest extends QaKernelTestCase
{
    private const QA_USER_ID = 999_001;
    private const QA_SECTION_ID = 888_001;
    private const FIELD = 'qa_file';

    private FormFileUploadService $service;
    private string $projectDir;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(FormFileUploadService::class);
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->projectDir = is_string($projectDir) ? $projectDir : '';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->removeDir(sprintf('%s/public/uploads/form-files/user_%d', $this->projectDir, self::QA_USER_ID));

        parent::tearDown();
    }

    public function testValidTextFileIsStoredThenDeleted(): void
    {
        $file = $this->makeUpload('qa_notes.txt', "qa text content\n");

        $processed = $this->service->processUploadedFiles([self::FIELD => $file], self::QA_USER_ID, self::QA_SECTION_ID);

        self::assertArrayHasKey(self::FIELD, $processed);
        $relativePath = $processed[self::FIELD];
        self::assertIsString($relativePath);
        self::assertStringContainsString('uploads/form-files/user_' . self::QA_USER_ID, $relativePath);
        self::assertStringNotContainsString('..', $relativePath, 'Stored path must not contain traversal segments.');

        // Public side effect: the file exists on disk.
        $full = $this->projectDir . '/public/' . $relativePath;
        self::assertFileExists($full);

        // Cleanup helper actually removes it.
        $this->service->deleteFiles([self::FIELD => $relativePath]);
        self::assertFileDoesNotExist($full);
    }

    public function testDisallowedExtensionIsRejected(): void
    {
        $file = $this->makeUpload('qa_evil.exe', 'MZ binary-ish');

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_BAD_REQUEST);
        $this->service->processUploadedFiles([self::FIELD => $file], self::QA_USER_ID, self::QA_SECTION_ID);
    }

    public function testMimeExtensionMismatchIsRejected(): void
    {
        // Plain text content but a .png extension -> content/extension mismatch.
        $file = $this->makeUpload('qa_fake_image.png', "this is not really a png\n");

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_BAD_REQUEST);
        $this->service->processUploadedFiles([self::FIELD => $file], self::QA_USER_ID, self::QA_SECTION_ID);
    }

    public function testOversizedFileIsRejected(): void
    {
        // 11 MB sparse file exceeds the 10 MB per-file limit.
        $path = $this->tempPath('qa_big.txt');
        $handle = fopen($path, 'w');
        self::assertIsResource($handle);
        fseek($handle, 11 * 1024 * 1024 - 1);
        fwrite($handle, "\0");
        fclose($handle);
        $file = new UploadedFile($path, 'qa_big.txt', null, null, true);

        $this->expectException(ServiceException::class);
        $this->expectExceptionCode(Response::HTTP_BAD_REQUEST);
        $this->service->processUploadedFiles([self::FIELD => $file], self::QA_USER_ID, self::QA_SECTION_ID);
    }

    public function testPathTraversalFilenameIsNeutralised(): void
    {
        $file = $this->makeUpload('../../../../etc/passwd.txt', "qa traversal attempt\n");

        $processed = $this->service->processUploadedFiles([self::FIELD => $file], self::QA_USER_ID, self::QA_SECTION_ID);

        $relativePath = $processed[self::FIELD] ?? '';
        self::assertIsString($relativePath);
        self::assertStringNotContainsString('..', $relativePath, 'Traversal must be stripped from the stored path.');
        self::assertStringNotContainsString('etc/passwd', $relativePath);
        // The file must land inside the sanitized field directory only.
        self::assertStringContainsString('user_' . self::QA_USER_ID . '/section_' . self::QA_SECTION_ID, $relativePath);
        self::assertFileExists($this->projectDir . '/public/' . $relativePath);
    }

    public function testExtractFileDataPicksOnlyUploadPaths(): void
    {
        $fileData = $this->service->extractFileData([
            'qa_answer' => 'plain text value',
            'qa_file' => 'uploads/form-files/user_1/section_2/field_qa_file/abc.txt',
            'qa_files' => [
                'uploads/form-files/user_1/section_2/field_qa_files/a.txt',
                'not-a-file',
            ],
        ]);

        self::assertArrayNotHasKey('qa_answer', $fileData);
        self::assertArrayHasKey('qa_file', $fileData);
        self::assertArrayHasKey('qa_files', $fileData);
    }

    public function testIsFileInputFieldHeuristic(): void
    {
        self::assertTrue($this->service->isFileInputField('qa_file', self::QA_SECTION_ID));
        self::assertTrue($this->service->isFileInputField('profile_FILE_upload', self::QA_SECTION_ID));
        self::assertFalse($this->service->isFileInputField('qa_answer', self::QA_SECTION_ID));
    }

    // -- helpers ------------------------------------------------------------

    private function makeUpload(string $originalName, string $contents): UploadedFile
    {
        $path = $this->tempPath('src_' . bin2hex(random_bytes(4)));
        file_put_contents($path, $contents);

        return new UploadedFile($path, $originalName, null, null, true);
    }

    private function tempPath(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/qa_upload_' . bin2hex(random_bytes(4)) . '_' . basename($suffix);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
