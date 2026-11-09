<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\CMS;

use App\Exception\ServiceException;
use App\Service\Core\BaseService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service for handling form file uploads with proper validation and storage
 * ENTITY RULE - Uses association objects instead of primitive foreign keys
 *
 * Files are stored OUTSIDE the document root (`var/form-files/`) so the web
 * server never serves them; FormFileController delivers them with an access
 * check. Stored cell values keep the `uploads/form-files/...` shape as a
 * logical key (like `assets.file_path`), mapped to disk here.
 */
class FormFileUploadService extends BaseService
{
    private const UPLOAD_DIR = 'uploads/form-files/';
    private const STORAGE_ROOT = '/var/form-files/';
    private const ALLOWED_EXTENSIONS = [
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'ico',
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf',
        // Archives
        'zip', 'rar', '7z', 'tar', 'gz',
        // Other
        'csv', 'json', 'xml'
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB per file
    private const MAX_TOTAL_SIZE = 50 * 1024 * 1024; // 50MB total per form submission
    private const MAX_ZIP_SIZE = 500 * 1024 * 1024; // 500MB per "download all" archive

    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly FormFieldKeyResolver $formFieldKeyResolver,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Locate one stored file of a form record for delivery; `$index` picks one
     * file of a multi-file field. See {@see findRecordFiles()}.
     *
     * @return array{absolutePath: string, dataTableId: int, ownerId: int|null, public: bool}|null
     */
    public function findRecordFile(int $recordId, string $field, int $index = 0): ?array
    {
        $files = $this->findRecordFiles($recordId, $field);
        $absolutePath = $files['absolutePaths'][$index] ?? null;
        if ($files === null || $absolutePath === null) {
            return null;
        }

        return [
            'absolutePath' => $absolutePath,
            'dataTableId' => $files['dataTableId'],
            'ownerId' => $files['ownerId'],
            'public' => $files['public'],
        ];
    }

    /**
     * Locate every stored file of a form record's field.
     *
     * `$field` is the input name or its `section_<id>` key; it must be a
     * `file-input` of the record's form. Returns null when the record, field or
     * all its files are missing. `public` is the field's `files_public`
     * switch, read live on every call.
     *
     * @return array{absolutePaths: non-empty-list<string>, dataTableId: int, ownerId: int|null, public: bool}|null
     */
    public function findRecordFiles(int $recordId, string $field): ?array
    {
        $connection = $this->entityManager->getConnection();
        $row = $connection->fetchAssociative(
            'SELECT r.id_users, t.id AS table_id, t.name AS table_name FROM data_rows r'
            . ' JOIN data_tables t ON t.id = r.id_data_tables WHERE r.id = :id',
            ['id' => $recordId]
        );
        if ($row === false) {
            return null;
        }

        $tableName = is_scalar($row['table_name']) ? (string) $row['table_name'] : '';
        if (!$this->isFileInputField($field, $tableName)) {
            return null;
        }
        $fieldKey = $this->formFieldKeyResolver->getNameToFieldKey($tableName)[$field] ?? $field;

        $value = $connection->fetchOne(
            'SELECT c.value FROM data_cells c JOIN data_cols dc ON dc.id = c.id_data_cols'
            . " WHERE c.id_data_rows = :id AND dc.field_key = :key AND c.value <> '' LIMIT 1",
            ['id' => $recordId, 'key' => $fieldKey]
        );
        if (!is_string($value)) {
            return null;
        }

        $absolutePaths = $this->resolveCellValue($value);
        if ($absolutePaths === []) {
            return null;
        }

        $isPublic = $connection->fetchOne(
            'SELECT sft.content FROM sections_fields_translation sft JOIN fields f ON f.id = sft.id_fields'
            . " WHERE sft.id_sections = :section AND f.name = 'files_public' LIMIT 1",
            ['section' => (int) substr($fieldKey, strlen(FormFieldKeyResolver::FIELD_KEY_PREFIX))]
        );

        return [
            'absolutePaths' => $absolutePaths,
            'dataTableId' => is_numeric($row['table_id']) ? (int) $row['table_id'] : 0,
            'ownerId' => is_numeric($row['id_users']) ? (int) $row['id_users'] : null,
            'public' => $isPublic === '1',
        ];
    }

    /**
     * Every stored file of the given records of a form data table, for the
     * admin "download all" zip. Records of other tables are ignored.
     *
     * @param list<int> $recordIds
     * @return list<array{recordId: int, field: string, absolutePath: string}>
     */
    public function findTableFiles(string $tableName, array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT r.id AS record_id, dc.field_key, c.value FROM data_rows r'
            . ' JOIN data_tables t ON t.id = r.id_data_tables'
            . ' JOIN data_cells c ON c.id_data_rows = r.id'
            . ' JOIN data_cols dc ON dc.id = c.id_data_cols'
            . " WHERE t.name = :table AND r.id IN (:ids) AND c.value <> ''"
            . ' ORDER BY r.id, dc.field_key',
            ['table' => $tableName, 'ids' => $recordIds],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $keyToName = $this->formFieldKeyResolver->getFieldKeyToName($tableName);
        $files = [];
        foreach ($rows as $row) {
            $fieldKey = is_scalar($row['field_key']) ? (string) $row['field_key'] : '';
            if (!$this->isFileInputField($fieldKey, $tableName) || !is_string($row['value'])) {
                continue;
            }
            foreach ($this->resolveCellValue($row['value']) as $absolutePath) {
                $files[] = [
                    'recordId' => is_numeric($row['record_id']) ? (int) $row['record_id'] : 0,
                    'field' => $keyToName[$fieldKey] ?? $fieldKey,
                    'absolutePath' => $absolutePath,
                ];
            }
        }

        return $files;
    }

    /**
     * Zip the given files into a temporary archive and return its path; the
     * caller deletes it after sending. Entry names are made unique.
     *
     * @param list<array{entry: string, absolutePath: string}> $files
     * @throws ServiceException 413 when the files exceed the archive limit
     */
    public function buildZip(array $files): string
    {
        $total = 0;
        foreach ($files as $file) {
            $total += (int) filesize($file['absolutePath']);
        }
        if ($total > self::MAX_ZIP_SIZE) {
            throw new ServiceException(
                'Too many files to download at once (limit ' . (self::MAX_ZIP_SIZE / 1024 / 1024) . 'MB). Narrow the selection.',
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE
            );
        }

        $zipPath = (string) tempnam(sys_get_temp_dir(), 'form-files-');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new ServiceException('Unable to create the zip archive', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $used = [];
        foreach ($files as $file) {
            $entry = $file['entry'];
            for ($n = 2; isset($used[$entry]); $n++) {
                $entry = preg_replace('/(\.[^.\/]*)?$/', '_' . $n . '$1', $file['entry'], 1) ?? $file['entry'] . '_' . $n;
            }
            $used[$entry] = true;
            $zip->addFile($file['absolutePath'], $entry);
        }
        $zip->close();

        return $zipPath;
    }

    /** File name as uploaded: strips the `<timestamp>_<random>_` storage prefix. */
    public function originalName(string $path): string
    {
        return preg_replace('/^\d+_[0-9a-f]{8}_/', '', basename($path)) ?? basename($path);
    }

    /**
     * Resolve a stored cell value (one key or a JSON list of keys) to the
     * files that exist on disk.
     *
     * @return list<string>
     */
    private function resolveCellValue(string $value): array
    {
        $decoded = json_decode($value, true);
        $paths = is_array($decoded) ? array_values($decoded) : [$value];
        $resolved = [];
        foreach ($paths as $path) {
            $absolutePath = is_string($path) ? $this->resolveForDelivery($path) : null;
            if ($absolutePath !== null) {
                $resolved[] = $absolutePath;
            }
        }

        return $resolved;
    }

    /**
     * Map a stored `uploads/form-files/...` key to its file on disk, refusing
     * anything that escapes the storage root. Null when unsafe or missing.
     */
    private function resolveForDelivery(string $logicalPath): ?string
    {
        if (!str_starts_with($logicalPath, self::UPLOAD_DIR) || str_contains($logicalPath, '..')) {
            return null;
        }

        $real = realpath($this->absolutePath($logicalPath));
        $root = realpath($this->projectDir . self::STORAGE_ROOT);
        if ($real === false || $root === false || !is_file($real)
            || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        return $real;
    }

    private function absolutePath(string $logicalPath): string
    {
        return $this->projectDir . self::STORAGE_ROOT . substr($logicalPath, strlen(self::UPLOAD_DIR));
    }

    /**
     * Process uploaded files from form data
     *
     * @param array<string, mixed> $uploadedFiles Array of UploadedFile objects keyed by field name
     * @param int $userId The user ID
     * @param int $sectionId The section ID
     * @return array<string, mixed> Array with processed file data keyed by field name
     * @throws ServiceException
     */
    public function processUploadedFiles(array $uploadedFiles, int $userId, int $sectionId): array
    {
        $processedFiles = [];
        $totalSize = 0;

        foreach ($uploadedFiles as $fieldName => $files) {
            if (!is_array($files)) {
                $files = [$files];
            }

            $filePaths = [];
            foreach ($files as $file) {
                if (!$file instanceof UploadedFile) {
                    continue;
                }

                // Validate file
                $this->validateFile($file);

                // Check total size
                $totalSize += $file->getSize();
                if ($totalSize > self::MAX_TOTAL_SIZE) {
                    throw new ServiceException(
                        'Total file size exceeds limit of ' . (self::MAX_TOTAL_SIZE / 1024 / 1024) . 'MB',
                        Response::HTTP_BAD_REQUEST
                    );
                }

                // Generate secure filename and store file
                $filePath = $this->storeFile($file, $userId, $sectionId, $fieldName);
                $filePaths[] = $filePath;
            }

            if (!empty($filePaths)) {
                $processedFiles[$fieldName] = count($filePaths) === 1 ? $filePaths[0] : $filePaths;
            }
        }

        return $processedFiles;
    }

    /**
     * Store a single uploaded file
     *
     * @param UploadedFile $file The uploaded file
     * @param int $userId The user ID
     * @param int $sectionId The section ID
     * @param string $fieldName The field name
     * @return string The stored file path
     * @throws ServiceException
     */
    private function storeFile(UploadedFile $file, int $userId, int $sectionId, string $fieldName): string
    {
        // Generate secure filename
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $safeName = $this->generateSafeFilename($originalName, $extension);

        // Create directory structure: uploads/form-files/user_{userId}/section_{sectionId}/field_{fieldName}/
        $directory = sprintf(
            '%s%suser_%d/section_%d/field_%s/',
            $this->projectDir,
            self::STORAGE_ROOT,
            $userId,
            $sectionId,
            $this->sanitizeFieldName($fieldName)
        );

        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new ServiceException(
                    'Failed to create upload directory',
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        }

        // Move file to final location
        try {
            $file->move($directory, $safeName);
        } catch (\Exception $e) {
            throw new ServiceException(
                'Failed to save uploaded file: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // Return the logical key stored in the record
        return sprintf(
            '%suser_%d/section_%d/field_%s/%s',
            self::UPLOAD_DIR,
            $userId,
            $sectionId,
            $this->sanitizeFieldName($fieldName),
            $safeName
        );
    }

    /**
     * Validate an uploaded file
     *
     * @param UploadedFile $file The file to validate
     * @throws ServiceException
     */
    private function validateFile(UploadedFile $file): void
    {
        // Check if file was uploaded successfully
        if (!$file->isValid()) {
            throw new ServiceException(
                'File upload failed: ' . $file->getErrorMessage(),
                Response::HTTP_BAD_REQUEST
            );
        }

        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new ServiceException(
                'File size exceeds limit of ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB',
                Response::HTTP_BAD_REQUEST
            );
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new ServiceException(
                'File type not allowed. Allowed types: ' . implode(', ', self::ALLOWED_EXTENSIONS),
                Response::HTTP_BAD_REQUEST
            );
        }

        // Additional security check: verify file content matches extension
        $this->validateFileContent($file, $extension);
    }

    /**
     * Validate file content matches the extension
     *
     * @param UploadedFile $file The file to validate
     * @param string $extension The file extension
     * @throws ServiceException
     */
    private function validateFileContent(UploadedFile $file, string $extension): void
    {
        $mimeType = $file->getMimeType();

        // Basic MIME type validation based on extension
        $allowedMimeTypes = [
            // Images
            'jpg' => ['image/jpeg', 'image/jpg'],
            'jpeg' => ['image/jpeg', 'image/jpg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'svg' => ['image/svg+xml', 'text/plain'],
            'bmp' => ['image/bmp'],
            'tiff' => ['image/tiff'],
            'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'],
            // Documents
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'txt' => ['text/plain'],
            'rtf' => ['application/rtf', 'text/rtf'],
            // Archives
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'rar' => ['application/x-rar-compressed', 'application/x-rar'],
            '7z' => ['application/x-7z-compressed'],
            'tar' => ['application/x-tar'],
            'gz' => ['application/gzip', 'application/x-gzip'],
            // Other
            'csv' => ['text/csv', 'application/csv', 'text/plain'],
            'json' => ['application/json', 'text/plain'],
            'xml' => ['application/xml', 'text/xml', 'text/plain']
        ];

        if (!isset($allowedMimeTypes[$extension]) || !in_array($mimeType, $allowedMimeTypes[$extension])) {
            throw new ServiceException(
                'File content does not match the file extension',
                Response::HTTP_BAD_REQUEST
            );
        }
    }

    /**
     * Generate a safe filename
     *
     * @param string $originalName The original filename
     * @param string $extension The file extension
     * @return string The safe filename
     */
    private function generateSafeFilename(string $originalName, string $extension): string
    {
        // Remove path components and dangerous characters
        $safeName = basename($originalName);
        $safeName = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', pathinfo($safeName, PATHINFO_FILENAME));

        // Generate unique filename to prevent conflicts
        $timestamp = time();
        $random = bin2hex(random_bytes(4));

        return sprintf('%d_%s_%s.%s', $timestamp, $random, $safeName, $extension);
    }

    /**
     * Sanitize field name for use in directory structure
     *
     * @param string $fieldName The field name
     * @return string The sanitized field name
     */
    private function sanitizeFieldName(string $fieldName): string
    {
        // Replace non-alphanumeric characters with underscores
        $sanitized = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $fieldName) ?? '';
        // Remove multiple consecutive underscores
        $sanitized = preg_replace('/_+/', '_', $sanitized) ?? '';
        // Remove leading/trailing underscores
        return trim($sanitized, '_');
    }

    /**
     * Delete files associated with a record
     *
     * @param array<array-key, mixed> $fileData Array of file paths or arrays of file paths
     * @throws ServiceException
     */
    public function deleteFiles(array $fileData): void
    {
        foreach ($fileData as $fieldName => $filePaths) {
            if (!is_array($filePaths)) {
                $filePaths = [$filePaths];
            }

            foreach ($filePaths as $filePath) {
                if (is_string($filePath) && str_starts_with($filePath, self::UPLOAD_DIR) && !str_contains($filePath, '..')) {
                    $fullPath = $this->absolutePath($filePath);
                    if (file_exists($fullPath)) {
                        if (!unlink($fullPath)) {
                            // Log warning but don't throw exception for cleanup failures
                            $this->logger->warning("Failed to delete file: {$fullPath}");
                        }
                    }
                }
            }
        }
    }

    /**
     * Extract file data from form data for cleanup
     *
     * @param array<string, mixed> $formData The form data containing file information
     * @return array<string, mixed> Array of file paths keyed by field name
     */
    public function extractFileData(array $formData): array
    {
        $fileData = [];

        foreach ($formData as $fieldName => $fieldValue) {
            // Check if field value looks like a file path (contains upload directory)
            if (is_string($fieldValue) && str_contains($fieldValue, self::UPLOAD_DIR)) {
                $fileData[$fieldName] = $fieldValue;
            } elseif (is_array($fieldValue)) {
                // Check if array contains file paths
                $hasFilePaths = false;
                foreach ($fieldValue as $value) {
                    if (is_string($value) && str_contains($value, self::UPLOAD_DIR)) {
                        $hasFilePaths = true;
                        break;
                    }
                }
                if ($hasFilePaths) {
                    $fileData[$fieldName] = $fieldValue;
                }
            }
        }

        return $fileData;
    }

    /**
     * Whether a submitted name (or stored `section_<id>` key) belongs to a
     * `file-input` section of the form whose data table is `$formTableName`.
     */
    public function isFileInputField(string $fieldName, string $formTableName): bool
    {
        return $this->formFieldKeyResolver->isFileInput($formTableName, $fieldName);
    }
}
