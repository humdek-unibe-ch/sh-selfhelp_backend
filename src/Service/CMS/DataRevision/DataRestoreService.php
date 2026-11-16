<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

use App\Service\CMS\DataQuery\DataColValueType;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\BaseService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Plans the restore of an earlier version of a record ({@see RestorePlan}) and
 * writes the planned cells.
 *
 * It decides nothing about WHO may restore: that is the caller's access check
 * (the page, the list occurrence, the table grant, the record's owner). It also
 * does not own the transaction, the record lock or the version: `DataService`
 * runs {@see apply()} inside its write protocol, so a restore is just another
 * write: it takes the lock, refuses a stale `expected_version`, appends one
 * `restore` revision and never rewinds or deletes the history it restores from.
 *
 * What a restore is: the fields of the chosen version are written back; fields
 * the record has now but that version did not have are removed; the owner, the
 * trigger type and every other field of the record stay as they are. A value is
 * written only if it is valid for TODAY's schema: the column must still exist,
 * the language must still exist, a typed column must be able to read the value,
 * and any file the value names must still be on disk.
 */
final class DataRestoreService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DataRevisionService $revisions,
        private readonly FormFileUploadService $files,
    ) {
    }

    /**
     * What restoring `$fromVersion` would do to the record as it is now.
     *
     * @param list<string>|null $fieldKeys restore only these fields (an explicit choice, never a default); null: every field that differs
     * @throws \App\Exception\ServiceException 404 when the record or the version does not exist
     */
    public function plan(int $recordId, int $fromVersion, ?array $fieldKeys = null): RestorePlan
    {
        $db = $this->connection();
        $tableId = $db->fetchOne('SELECT id_data_tables FROM data_rows WHERE id = :id', ['id' => $recordId]);
        if ($tableId === false || $tableId === null) {
            $this->throwNotFound('Record not found');
        }

        $currentVersion = $this->revisions->currentVersion($recordId);
        $source = $this->revisions->stateAt($recordId, $fromVersion);
        $live = $this->revisions->liveState($recordId);
        $columns = $this->columnsByKey($this->asInt($tableId));
        $languages = $this->languageIds();
        $selected = $fieldKeys === null ? null : array_flip($fieldKeys);

        $changes = [];
        $blockers = [];
        foreach ($source as $identity => $field) {
            if ($selected !== null && !isset($selected[$field->key])) {
                continue;
            }
            $current = $live[$identity] ?? null;
            if ($current !== null && $current->value === $field->value) {
                continue;
            }

            $blocker = $this->blockerFor($field, $columns[$field->key] ?? null, $languages);
            if ($blocker !== null) {
                $blockers[] = $blocker;
                continue;
            }

            $changes[] = [
                'column_id' => $columns[$field->key]['id'],
                'language_id' => $field->languageId,
                'field_key' => $field->key,
                'field_label' => $field->label,
                'action' => 'set',
                'before' => $current?->value,
                'after' => $field->value,
            ];
        }

        // What the record has now but that version did not have goes away.
        foreach ($live as $identity => $field) {
            if (isset($source[$identity]) || ($selected !== null && !isset($selected[$field->key]))) {
                continue;
            }
            $column = $columns[$field->key] ?? null;
            if ($column === null) {
                continue; // a cell without a column cannot exist
            }
            $changes[] = [
                'column_id' => $column['id'],
                'language_id' => $field->languageId,
                'field_key' => $field->key,
                'field_label' => $field->label,
                'action' => 'remove',
                'before' => $field->value,
                'after' => null,
            ];
        }

        return new RestorePlan($recordId, $fromVersion, $currentVersion, $changes, $blockers);
    }

    /**
     * Write the planned cells of a restorable plan. Runs inside the caller's
     * transaction, after it took the record lock.
     *
     * Plain statements, not the ORM: removing a `DataCell` through the ORM cascades
     * to its row and its column (`DataCell` maps ManyToOne with cascade REMOVE).
     */
    public function apply(RestorePlan $plan): void
    {
        $db = $this->connection();
        foreach ($plan->changes as $change) {
            if ($change['action'] === 'remove') {
                $db->executeStatement(
                    'DELETE FROM data_cells WHERE id_data_rows = :row AND id_data_cols = :col AND id_languages = :lang',
                    ['row' => $plan->recordId, 'col' => $change['column_id'], 'lang' => $change['language_id']],
                );
                continue;
            }

            $db->executeStatement(
                'INSERT INTO data_cells (id_data_rows, id_data_cols, id_languages, value) VALUES (:row, :col, :lang, :value)'
                . ' ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [
                    'row' => $plan->recordId,
                    'col' => $change['column_id'],
                    'lang' => $change['language_id'],
                    'value' => $change['after'] ?? '',
                ],
            );
        }
    }

    /**
     * @param array{id: int, type: string}|null $column the column of the field in today's schema
     * @param array<int, true> $languages
     * @return array{field_key: string, field_label: ?string, language_id: int, reason: string, detail: ?string}|null
     */
    private function blockerFor(RevisionField $field, ?array $column, array $languages): ?array
    {
        $blocker = static fn (string $reason, ?string $detail = null): array => [
            'field_key' => $field->key,
            'field_label' => $field->label,
            'language_id' => $field->languageId,
            'reason' => $reason,
            'detail' => $detail,
        ];

        if ($column === null) {
            return $blocker(RestorePlan::REASON_FIELD_MISSING);
        }
        if (!isset($languages[$field->languageId])) {
            return $blocker(RestorePlan::REASON_LANGUAGE_MISSING);
        }
        if (!DataColValueType::reads($column['type'], $field->value)) {
            return $blocker(RestorePlan::REASON_INVALID_VALUE, $column['type']);
        }
        foreach (FormFileUploadService::keysIn($field->value) as $key) {
            if ($this->files->resolveKey($key) === null) {
                return $blocker(RestorePlan::REASON_FILE_UNAVAILABLE, FormFileUploadService::nameOfKey($key));
            }
        }

        return null;
    }

    /**
     * @return array<string, array{id: int, type: string}>
     */
    private function columnsByKey(int $tableId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT dc.id, dc.field_key, COALESCE(t.lookup_code, \'text\') AS type'
            . ' FROM data_cols dc LEFT JOIN lookups t ON t.id = dc.id_value_type'
            . ' WHERE dc.id_data_tables = :t',
            ['t' => $tableId],
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$this->asString($row['field_key'])] = ['id' => $this->asInt($row['id']), 'type' => $this->asString($row['type'])];
        }

        return $columns;
    }

    /**
     * @return array<int, true>
     */
    private function languageIds(): array
    {
        $ids = [];
        foreach ($this->connection()->fetchFirstColumn('SELECT id FROM languages') as $id) {
            $ids[$this->asInt($id)] = true;
        }

        return $ids;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
