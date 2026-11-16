<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Exception\ServiceException;
use App\Service\CMS\DataRevision\DataRecordHistoryService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record history in the admin data browser: list, view, compare, restore and
 * historical files. Access is the table UPDATE grant (contract 6); history is
 * never public.
 */
class AdminDataHistoryService extends BaseService
{
    public function __construct(
        private readonly DataService $dataService,
        private readonly DataRecordHistoryService $history,
        private readonly DataAccessSecurityService $security,
    ) {
    }

    /**
     * @return array{table_id: int, record_id: int}
     */
    public function assertCanRead(string $tableName, int $recordId, int $userId): array
    {
        return $this->assertAccess($tableName, $recordId, $userId, DataAccessSecurityService::PERMISSION_UPDATE);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, page_size: int, current_version: int}
     */
    public function versions(string $tableName, int $recordId, int $userId, int $page, int $pageSize): array
    {
        $this->assertCanRead($tableName, $recordId, $userId);
        $payload = $this->history->history($recordId, $page, min(max($pageSize, 1), 100));

        return [
            'items' => $this->listFilesWithUrls($tableName, $recordId, $payload['items'], null),
            'total' => $payload['total'],
            'page' => $payload['page'],
            'page_size' => $payload['page_size'],
            'current_version' => $payload['current_version'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function version(string $tableName, int $recordId, int $userId, int $version): array
    {
        $this->assertCanRead($tableName, $recordId, $userId);
        $payload = [
            'revision' => $this->history->revision($recordId, $version),
            'state' => $this->history->state($recordId, $version),
        ];

        return $this->withFileUrls($payload, $tableName, $recordId, $version);
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(string $tableName, int $recordId, int $userId, int $from, int $to): array
    {
        $this->assertCanRead($tableName, $recordId, $userId);

        return $this->withFileUrls($this->history->compare($recordId, $from, $to), $tableName, $recordId);
    }

    /**
     * @param list<string>|null $fieldKeys
     * @return array<string, mixed>
     */
    public function restore(
        string $tableName,
        int $recordId,
        int $userId,
        int $version,
        ?array $fieldKeys,
        ?int $expectedVersion,
        bool $dryRun,
    ): array {
        $access = $this->assertCanRead($tableName, $recordId, $userId);
        if (!$dryRun && ($expectedVersion === null || $expectedVersion < 1)) {
            throw new ServiceException(
                'The version of the entry you are changing is required (expected_version).',
                Response::HTTP_BAD_REQUEST,
                ['reason' => 'expected_version_required', 'record_id' => $recordId],
            );
        }

        if ($dryRun) {
            $plan = $this->dataService->previewRestore($access['table_id'], $recordId, $version, $fieldKeys, false);

            return ['dry_run' => true, 'restorable' => $plan->isRestorable()] + $plan->toClient($this->history);
        }

        $newVersion = $this->dataService->restoreRecord(
            $access['table_id'],
            $recordId,
            $version,
            $fieldKeys,
            false,
            RecordWriteContext::interactive(
                LookupService::DATA_REVISION_SOURCES_ADMIN,
                $expectedVersion,
            ),
        );

        return [
            'dry_run' => false,
            'record_id' => $recordId,
            'restored_from_version' => $version,
            'version' => $newVersion,
        ];
    }

    /**
     * @return array{absolutePath: string, name: string}
     */
    public function file(
        string $tableName,
        int $recordId,
        int $userId,
        int $version,
        string $fieldKey,
        int $languageId,
        int $index,
    ): array {
        $this->assertCanRead($tableName, $recordId, $userId);
        $file = $this->history->historicalFile($recordId, $version, $fieldKey, $languageId, $index);
        if ($file === null) {
            throw new ServiceException('File not found', Response::HTTP_NOT_FOUND);
        }

        return $file;
    }

    /**
     * @return array{table_id: int, record_id: int}
     */
    private function assertAccess(string $tableName, int $recordId, int $userId, int $permission): array
    {
        $table = $this->dataService->getDataTableByName($tableName);
        if ($table === null) {
            throw new ServiceException('Data table not found', Response::HTTP_NOT_FOUND);
        }
        $tableId = (int) $table->getId();
        if (!$this->security->hasPermission($userId, 'data_table', $tableId, $permission)) {
            throw new ServiceException('Forbidden', Response::HTTP_FORBIDDEN);
        }
        $row = $this->dataService->findRecordInTable($tableId, $recordId);
        if ($row === null) {
            throw new ServiceException('Record not found', Response::HTTP_NOT_FOUND);
        }

        return ['table_id' => $tableId, 'record_id' => $recordId];
    }

    /**
     * Attach historical-file URLs to file descriptions in a history payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withFileUrls(array $payload, string $tableName, int $recordId, ?int $defaultVersion = null): array
    {
        $state = $payload['state'] ?? null;
        if (is_array($state)) {
            $version = $this->asInt($state['version'] ?? $defaultVersion ?? 0);
            $fields = $state['fields'] ?? null;
            if (is_array($fields)) {
                $payload['state'] = $state + [
                    'fields' => $this->stateFilesWithUrls($tableName, $recordId, $fields, $version),
                ];
            }
        }

        foreach (['changes', 'items'] as $key) {
            $list = $payload[$key] ?? null;
            if (!is_array($list)) {
                continue;
            }
            $payload[$key] = $this->listFilesWithUrls($tableName, $recordId, $list, $defaultVersion);
        }

        return $payload;
    }

    /**
     * @param list<mixed>|array<mixed> $fields
     * @return list<array<string, mixed>>
     */
    private function stateFilesWithUrls(string $tableName, int $recordId, array $fields, int $version): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            $entry = $field;
            if (($entry['kind'] ?? '') === 'file' && is_array($entry['files'] ?? null)) {
                $fieldKey = $this->asString($entry['field_key'] ?? '');
                $languageId = $this->asInt($entry['language_id'] ?? 1);
                $entry['files'] = $this->attachUrls($tableName, $recordId, $fieldKey, $languageId, $entry['files'], $version);
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param list<mixed>|array<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function listFilesWithUrls(string $tableName, int $recordId, array $items, ?int $defaultVersion): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            $entry = $item;
            $version = $this->asInt($entry['version'] ?? $defaultVersion ?? 0);
            $fieldKey = $this->asString($entry['field_key'] ?? '');
            $languageId = $this->asInt($entry['language_id'] ?? 1);
            foreach (['before_files', 'after_files', 'files'] as $filesKey) {
                $files = $entry[$filesKey] ?? null;
                if (!is_array($files) || $version < 1) {
                    continue;
                }
                $entry[$filesKey] = $this->attachUrls(
                    $tableName,
                    $recordId,
                    $fieldKey,
                    $languageId,
                    $files,
                    $version,
                );
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param list<mixed>|array<mixed> $files
     * @return list<array<string, mixed>>
     */
    private function attachUrls(
        string $tableName,
        int $recordId,
        string $fieldKey,
        int $languageId,
        array $files,
        int $version,
    ): array {
        $out = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            $entry = $file;
            if (($entry['available'] ?? false) === true) {
                $key = $fieldKey !== '' ? $fieldKey : $this->asString($entry['field_key'] ?? '');
                $entry['url'] = sprintf(
                    '/admin/data/tables/%s/records/%d/versions/%d/files/%s?index=%d&file_language=%d',
                    rawurlencode($tableName),
                    $recordId,
                    $version,
                    rawurlencode($key),
                    $this->asInt($entry['index'] ?? 0),
                    $languageId,
                );
            }
            $out[] = $entry;
        }

        return $out;
    }
}
