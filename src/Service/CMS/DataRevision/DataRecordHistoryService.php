<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

use App\Service\CMS\FormFileUploadService;

/**
 * The history of a record as it leaves the server: the shapes the history,
 * compare, restore-preview and file endpoints return.
 *
 * It adds three things to {@see DataRevisionService}, which stores and rebuilds:
 *  - Field projection. A reader who may see only some fields passes their stable
 *    keys; every other field, and every event that touched nothing else, is left
 *    out. History can be more sensitive than the current values, so a field the
 *    reader cannot see now is never shown through its past.
 *  - Files named, never keyed. A value that names stored files is returned as
 *    `{index, name, available}` entries: the storage path is not part of any
 *    response. `available` is false once the file is gone (erased), so a client
 *    can say so instead of offering a dead link.
 *  - Historical file delivery ({@see historicalFile()}), authorised by record,
 *    version and field, never by knowing a path. It does not inherit the
 *    "files are public" switch of the current field: history is never public.
 *
 * Who may read a record's history (own record, table grant, the list's history
 * setting) is decided by the caller, as it is for the record itself.
 */
final class DataRecordHistoryService
{
    public function __construct(
        private readonly DataRevisionService $revisions,
        private readonly FormFileUploadService $files,
    ) {
    }

    /**
     * @param list<string>|null $allowedFieldKeys null: every field
     * @return array{items: list<array<string, mixed>>, total: int, page: int, page_size: int, current_version: int}
     */
    public function history(int $recordId, int $page = 1, int $pageSize = 20, ?array $allowedFieldKeys = null): array
    {
        return $this->revisions->history($recordId, $page, $pageSize, $allowedFieldKeys)
            + ['current_version' => $this->revisions->currentVersion($recordId)];
    }

    /**
     * One revision: who/when/how and the fields it changed.
     *
     * @param list<string>|null $allowedFieldKeys null: every field
     * @return array{revision: array<string, mixed>, changes: list<array<string, mixed>>}
     */
    public function revision(int $recordId, int $version, ?array $allowedFieldKeys = null): array
    {
        return [
            'revision' => $this->revisions->revisionHeader($recordId, $version, $allowedFieldKeys),
            'changes' => $this->describeChanges($this->revisions->revisionChanges($recordId, $version), $allowedFieldKeys),
        ];
    }

    /**
     * The difference between two versions of the record.
     *
     * @param list<string>|null $allowedFieldKeys null: every field
     * @return array{from_version: int, to_version: int, current_version: int, changes: list<array<string, mixed>>}
     */
    public function compare(int $recordId, int $fromVersion, int $toVersion, ?array $allowedFieldKeys = null): array
    {
        return [
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'current_version' => $this->revisions->currentVersion($recordId),
            'changes' => $this->describeChanges($this->revisions->compare($recordId, $fromVersion, $toVersion), $allowedFieldKeys),
        ];
    }

    /**
     * The record as it stood after a version.
     *
     * @param list<string>|null $allowedFieldKeys null: every field
     * @return array{version: int, current_version: int, fields: list<array<string, mixed>>}
     */
    public function state(int $recordId, int $version, ?array $allowedFieldKeys = null): array
    {
        $allowed = $allowedFieldKeys === null ? null : array_flip($allowedFieldKeys);
        $fields = [];
        foreach ($this->revisions->stateAt($recordId, $version) as $field) {
            if ($allowed !== null && !isset($allowed[$field->key])) {
                continue;
            }
            $files = $this->describeFiles($field->value);
            $fields[] = [
                'field_key' => $field->key,
                'field_label' => $field->label,
                'language_id' => $field->languageId,
                'kind' => $files !== [] ? 'file' : 'value',
                'value' => $files !== [] ? null : $this->cut($field->value),
                'files' => $files,
            ];
        }

        return ['version' => $version, 'current_version' => $this->revisions->currentVersion($recordId), 'fields' => $fields];
    }

    /**
     * A file a field held at an earlier version, for download.
     *
     * Null when the field, the language or the position holds no file, the field is
     * not visible to the reader, or the file has been erased. The record having no
     * such version is a 404.
     *
     * @param list<string>|null $allowedFieldKeys null: every field
     * @return array{absolutePath: string, name: string}|null
     * @throws \App\Exception\ServiceException 404 when the record has no such version
     */
    public function historicalFile(int $recordId, int $version, string $fieldKey, int $languageId, int $index, ?array $allowedFieldKeys = null): ?array
    {
        if ($allowedFieldKeys !== null && !in_array($fieldKey, $allowedFieldKeys, true)) {
            return null;
        }

        $field = $this->revisions->stateAt($recordId, $version)[RevisionField::identityOf($fieldKey, $languageId)] ?? null;
        $key = $field === null ? null : (FormFileUploadService::keysIn($field->value)[$index] ?? null);
        $absolutePath = $key === null ? null : $this->files->resolveKey($key);
        if ($key === null || $absolutePath === null) {
            return null;
        }

        return ['absolutePath' => $absolutePath, 'name' => FormFileUploadService::nameOfKey($key)];
    }

    /**
     * @return list<array{index: int, name: string, available: bool}>
     */
    public function describeFiles(?string $value): array
    {
        if (!FormFileUploadService::cellHoldsFiles($value)) {
            return [];
        }

        return $this->describeKeys(FormFileUploadService::keysIn($value));
    }

    /** A value as sent to a client: cut at the preview size. */
    public function cut(?string $value): ?string
    {
        if ($value === null || strlen($value) <= DataRevisionService::VALUE_PREVIEW_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, DataRevisionService::VALUE_PREVIEW_BYTES);
    }

    /**
     * @param list<array<string, mixed>> $changes as returned by {@see DataRevisionService}
     * @param list<string>|null $allowedFieldKeys
     * @return list<array<string, mixed>>
     */
    private function describeChanges(array $changes, ?array $allowedFieldKeys): array
    {
        $allowed = $allowedFieldKeys === null ? null : array_flip($allowedFieldKeys);
        $result = [];
        foreach ($changes as $change) {
            $fieldKey = is_string($change['field_key'] ?? null) ? $change['field_key'] : '';
            if ($allowed !== null && !isset($allowed[$fieldKey])) {
                continue;
            }

            $beforeKeys = $this->stringList($change['before_file_keys'] ?? null);
            $afterKeys = $this->stringList($change['after_file_keys'] ?? null);
            unset($change['before_file_keys'], $change['after_file_keys']);

            if ($beforeKeys !== [] || $afterKeys !== []) {
                $change['kind'] = 'file';
                $change['before'] = null;
                $change['after'] = null;
                $change['before_files'] = $this->describeKeys($beforeKeys);
                $change['after_files'] = $this->describeKeys($afterKeys);
            } else {
                $change['kind'] = 'value';
            }
            $result[] = $change;
        }

        return $result;
    }

    /**
     * @param list<string> $keys
     * @return list<array{index: int, name: string, available: bool}>
     */
    private function describeKeys(array $keys): array
    {
        $described = [];
        foreach ($keys as $index => $key) {
            $described[] = [
                'index' => $index,
                'name' => FormFileUploadService::nameOfKey($key),
                'available' => $this->files->resolveKey($key) !== null,
            ];
        }

        return $described;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
