<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Exception\ServiceException;
use App\Service\CMS\DataRevision\DataRecordHistoryService;
use App\Service\CMS\Effective\EffectiveFormInput;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\BaseService;
use App\Service\Core\UserContextAwareService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Builds section-scoped file URLs and serves the current file of a record that
 * a list already resolved inside its boundary (F165).
 *
 * The caller ({@see FormListController::currentFile}) must check the boundary
 * first; this service never trusts a raw record id alone.
 */
class FormListCurrentFileService extends BaseService
{
    private const DOWNLOAD_ONLY_MIME_TYPES = [
        'text/html',
        'application/xhtml+xml',
        'text/xml',
        'application/xml',
        // F167: never inline SVG on the public current-file route (scriptable).
        'image/svg+xml',
    ];

    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function __construct(
        private readonly FormFileUploadService $uploads,
        private readonly DataRecordHistoryService $history,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserContextAwareService $userContext,
        private readonly DataAccessSecurityService $security,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row from {@see FormListBoundary::find()}
     * @return array{absolutePath: string, name: string, mime: string|null, disposition: string}
     */
    public function fileFromRow(FormListContext $ctx, array $row, string $fieldKey, int $index): array
    {
        if (!$this->sectionShowsFileField($ctx, $fieldKey)) {
            throw new ServiceException('File not found', Response::HTTP_NOT_FOUND);
        }
        // Page viewers need files_public; the record owner and table READ may
        // still open the file through this route (same people as /forms/files).
        if (!$this->isFilesPublic($fieldKey) && !$this->isOwnerOrTableReader($ctx, $row)) {
            throw new ServiceException('File not found', Response::HTTP_NOT_FOUND);
        }

        $raw = $row[$fieldKey] ?? null;
        if (!is_string($raw) || $raw === '') {
            // Boundary find may omit cell columns; load the stored value by key.
            $recordId = is_numeric($row['record_id'] ?? null) ? (int) $row['record_id'] : 0;
            $raw = $recordId > 0 ? $this->uploads->cellValue($recordId, $fieldKey) : null;
        }
        if (!is_string($raw) || $raw === '') {
            throw new ServiceException('File not found', Response::HTTP_NOT_FOUND);
        }

        $keys = FormFileUploadService::keysIn($raw);
        $key = $keys[$index] ?? null;
        if ($key === null) {
            throw new ServiceException('File not found', Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->uploads->resolveKey($key);
        if ($absolutePath === null) {
            throw new ServiceException('File not found', Response::HTTP_NOT_FOUND);
        }

        $mime = mime_content_type($absolutePath) ?: null;

        return [
            'absolutePath' => $absolutePath,
            'name' => $this->uploads->originalName($absolutePath),
            'mime' => $mime,
            'disposition' => $this->dispositionOf($mime),
        ];
    }

    /**
     * @return list<array{index: int, name: string, mime: string|null, size: int|null, url: string}>
     */
    public function filesPayload(FormListContext $ctx, int $recordId, string $fieldKey, ?string $cellValue): array
    {
        if (!FormFileUploadService::cellHoldsFiles($cellValue)) {
            return [];
        }

        $files = [];
        foreach ($this->history->describeFiles($cellValue) as $file) {
            if (!$file['available']) {
                continue;
            }
            $index = (int) $file['index'];
            $keys = FormFileUploadService::keysIn($cellValue);
            $absolute = isset($keys[$index]) ? $this->uploads->resolveKey($keys[$index]) : null;
            $mime = $absolute !== null ? (mime_content_type($absolute) ?: null) : null;
            $size = $absolute !== null ? (int) filesize($absolute) : null;
            $files[] = [
                'index' => $index,
                'name' => (string) $file['name'],
                'mime' => $mime,
                'size' => $size,
                'url' => $this->urlOf($ctx, $recordId, $fieldKey, $index),
            ];
        }

        return $files;
    }

    public function urlOf(FormListContext $ctx, int $recordId, string $fieldKey, int $index = 0): string
    {
        return sprintf(
            '/lists/%d/records/%d/files/%s?page_id=%d&index=%d',
            $ctx->config->sectionId,
            $recordId,
            rawurlencode($fieldKey),
            $ctx->pageId,
            $index,
        );
    }

    public static function isImageMime(?string $mime): bool
    {
        return $mime !== null && in_array($mime, self::IMAGE_MIME_TYPES, true);
    }

    private function sectionShowsFileField(FormListContext $ctx, string $fieldKey): bool
    {
        foreach ($ctx->config->columns as $column) {
            if ($column->field === $fieldKey
                && in_array($column->format, [FormListColumn::FORMAT_FILE, FormListColumn::FORMAT_IMAGE], true)
            ) {
                return true;
            }
        }

        $input = $ctx->form?->input($fieldKey);
        if ($input instanceof EffectiveFormInput && $input->isFile) {
            return true;
        }

        // A row template / entry-list / entry-record layout only exposes a file
        // when the template references that field ({{field}}, __url, __files) —
        // never because the table happens to have another file column.
        if ($ctx->template !== [] && $ctx->tableId !== null) {
            $tableName = $this->entityManager->getConnection()->fetchOne(
                'SELECT name FROM data_tables WHERE id = :id',
                ['id' => $ctx->tableId],
            );
            if (is_string($tableName)
                && $this->uploads->isFileInputField($fieldKey, $tableName)
                && $this->templateReferencesField($ctx->template, $fieldKey)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Templates use remapped input names (`{{portrait__url}}`); the route uses
     * field keys (`section_12`). Both must count as a reference.
     *
     * @param list<array<string, mixed>> $template
     */
    private function templateReferencesField(array $template, string $fieldKey): bool
    {
        $encoded = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }
        $needles = [$fieldKey];
        if (str_starts_with($fieldKey, 'section_')) {
            $sectionId = (int) substr($fieldKey, strlen('section_'));
            $name = $this->entityManager->getConnection()->fetchOne(
                'SELECT sft.content FROM sections_fields_translation sft
                 JOIN fields f ON f.id = sft.id_fields
                 WHERE sft.id_sections = :s AND f.name = :n LIMIT 1',
                ['s' => $sectionId, 'n' => 'name'],
            );
            if (is_string($name) && $name !== '') {
                $needles[] = $name;
            }
        }
        foreach ($needles as $needle) {
            if (str_contains($encoded, $needle)
                || str_contains($encoded, $needle . '__url')
                || str_contains($encoded, $needle . '__files')
                || str_contains($encoded, $needle . '__links')
            ) {
                return true;
            }
        }

        return false;
    }

    private function isFilesPublic(string $fieldKey): bool
    {
        if (!str_starts_with($fieldKey, 'section_')) {
            return false;
        }
        $sectionId = (int) substr($fieldKey, strlen('section_'));
        if ($sectionId <= 0) {
            return false;
        }

        $value = $this->entityManager->getConnection()->fetchOne(
            'SELECT sft.content FROM sections_fields_translation sft
             JOIN fields f ON f.id = sft.id_fields
             WHERE sft.id_sections = :section AND f.name = :name LIMIT 1',
            ['section' => $sectionId, 'name' => 'files_public'],
        );

        return $value === '1';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isOwnerOrTableReader(FormListContext $ctx, array $row): bool
    {
        $user = $this->userContext->getCurrentUser();
        if ($user === null || $ctx->tableId === null) {
            return false;
        }
        $userId = (int) $user->getId();
        $ownerId = is_numeric($row['id_users'] ?? null) ? (int) $row['id_users'] : null;
        if ($ownerId !== null && $ownerId === $userId) {
            return true;
        }

        return $this->security->hasPermission(
            $userId,
            'data_table',
            $ctx->tableId,
            DataAccessSecurityService::PERMISSION_READ,
        );
    }

    private function dispositionOf(?string $mime): string
    {
        if ($mime === null || in_array($mime, self::DOWNLOAD_ONLY_MIME_TYPES, true)) {
            return ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        }
        // image/svg+xml is in DOWNLOAD_ONLY; other image/* may still inline.
        if (self::isImageMime($mime) || ($mime !== 'image/svg+xml' && str_starts_with($mime, 'image/')) || $mime === 'application/pdf' || str_starts_with($mime, 'text/')) {
            return ResponseHeaderBag::DISPOSITION_INLINE;
        }

        return ResponseHeaderBag::DISPOSITION_ATTACHMENT;
    }
}
