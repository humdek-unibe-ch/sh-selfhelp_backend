<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

/**
 * What restoring an earlier version of a record would do, and what stands in the
 * way. It is computed against the record's CURRENT schema and files, never from
 * the old ones: a restore is a new write that must be valid today.
 *
 * Nothing is applied partially. Either every change is possible and the restore
 * runs, or `blockers` lists each field that cannot be restored and why, so the
 * person can resolve them (restore only the fields that can be restored, or fix
 * the form) instead of getting a silently incomplete record.
 *
 * `changes` carry raw values for the writer; {@see toClient()} is the shape that
 * leaves the server (values cut at the preview size, files named, never keyed).
 */
final readonly class RestorePlan
{
    public const REASON_FIELD_MISSING = 'field_missing';
    public const REASON_LANGUAGE_MISSING = 'language_missing';
    public const REASON_FILE_UNAVAILABLE = 'file_unavailable';
    public const REASON_INVALID_VALUE = 'invalid_value';

    /**
     * @param list<array{column_id: int, language_id: int, field_key: string, field_label: ?string, action: 'set'|'remove', before: ?string, after: ?string}> $changes
     * @param list<array{field_key: string, field_label: ?string, language_id: int, reason: string, detail: ?string}> $blockers
     */
    public function __construct(
        public int $recordId,
        public int $fromVersion,
        public int $currentVersion,
        public array $changes,
        public array $blockers,
    ) {
    }

    public function isRestorable(): bool
    {
        return $this->blockers === [];
    }

    /**
     * The same plan with more reasons it cannot be applied (the rules of the form as it is now).
     *
     * @param list<array{field_key: string, field_label: ?string, language_id: int, reason: string, detail: ?string}> $blockers
     */
    public function withBlockers(array $blockers): self
    {
        return new self($this->recordId, $this->fromVersion, $this->currentVersion, $this->changes, [...$this->blockers, ...$blockers]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toClient(DataRecordHistoryService $describer): array
    {
        $changes = [];
        foreach ($this->changes as $change) {
            $item = [
                'field_key' => $change['field_key'],
                'field_label' => $change['field_label'],
                'language_id' => $change['language_id'],
                'action' => $change['action'],
            ];

            $beforeFiles = $describer->describeFiles($change['before']);
            $afterFiles = $describer->describeFiles($change['after']);
            if ($beforeFiles !== [] || $afterFiles !== []) {
                $item += ['kind' => 'file', 'before' => null, 'after' => null, 'before_files' => $beforeFiles, 'after_files' => $afterFiles];
            } else {
                $item += [
                    'kind' => 'value',
                    'before' => $describer->cut($change['before']),
                    'after' => $describer->cut($change['after']),
                ];
            }
            $changes[] = $item;
        }

        return [
            'record_id' => $this->recordId,
            'from_version' => $this->fromVersion,
            'current_version' => $this->currentVersion,
            'restorable' => $this->isRestorable(),
            'changes' => $changes,
            'blockers' => $this->blockers,
        ];
    }
}
