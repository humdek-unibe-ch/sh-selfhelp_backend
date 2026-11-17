<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The history of every change to a record of the generic data store, and the
 * version protocol that stops two saves from overwriting each other.
 *
 * Writing. `DataService` is the only writer of the store. Inside the same
 * database transaction as the change it:
 *  1. takes the record lock and reads the version ({@see lockVersion()}),
 *  2. refuses a stale save ({@see assertExpectedVersion()}, HTTP 409),
 *  3. changes the cells,
 *  4. records the change ({@see recordChange()}): the version moves forward by
 *     exactly one with a single conditional statement, and one revision with its
 *     field changes is stored. A change that changes nothing stores nothing.
 * The record lock serialises the writers of one record, so a second save of the
 * same version waits, then finds the version moved and is refused. Failure at
 * any step rolls back the data, the version and the history together.
 *
 * Storage. A revision names who, when, how and through which door. Its changes
 * name the field by its stable key (with the label it had) and point at
 * immutable values stored once per record; unchanged fields cost nothing, and
 * a value that comes back (a revert) reuses the stored one. A value whose digest
 * matches is reused only after the bytes are compared. Every
 * {@see self::CHECKPOINT_EVERY} versions a checkpoint also lists the whole state
 * (references, not copies), so a past state is rebuilt from the nearest
 * checkpoint, never from the beginning. Records that existed before history
 * began get a `baseline` revision the first time they change; no earlier
 * authorship is invented.
 *
 * Limit, stated plainly: reuse works on whole values. A one-character edit
 * inside a 10 MB value stores the changed 10 MB value again.
 */
final class DataRevisionService extends BaseService
{
    /** A checkpoint (full state by reference) is written at every Nth version. */
    public const CHECKPOINT_EVERY = 20;

    /** Version of the storage format; stored on every revision. */
    public const SCHEMA_VERSION = 1;

    /** A value sent to a client is cut at this many bytes and flagged as truncated. */
    public const VALUE_PREVIEW_BYTES = 65536;

    public const MAX_PAGE_SIZE = 100;

    private const INSERT_CHUNK = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LookupService $lookupService,
        private readonly UserContextService $userContextService,
    ) {
    }

    // ------------------------------------------------------------------
    // Write protocol (inside the caller's open transaction)
    // ------------------------------------------------------------------

    /**
     * Take the record lock and return the record's current version. Two writers
     * of the same record queue here; the second one sees the version the first
     * one left.
     *
     * @throws ServiceException 404 when the record does not exist
     */
    public function lockVersion(int $recordId): int
    {
        return $this->lockRecord($recordId)->version;
    }

    /**
     * Take the record lock and return the record as it is under it: the latest
     * committed version, owner and trigger type. The guards of an interactive
     * write decide on this, not on an entity that was loaded before the lock.
     *
     * @throws ServiceException 404 when the record does not exist
     */
    public function lockRecord(int $recordId): LockedRecord
    {
        $this->assertInTransaction();
        $row = $this->connection()->fetchAssociative(
            'SELECT id_data_tables, version, id_users, id_action_trigger_types FROM data_rows WHERE id = :id FOR UPDATE',
            ['id' => $recordId],
        );
        if ($row === false) {
            $this->throwNotFound('Record not found');
        }

        return new LockedRecord(
            $recordId,
            $this->asInt($row['id_data_tables'] ?? null),
            $this->asInt($row['version'] ?? null),
            $this->asIntOrNull($row['id_users'] ?? null),
            $this->asIntOrNull($row['id_action_trigger_types'] ?? null),
        );
    }

    /**
     * Refuse a save that was based on an older version than the record has now.
     *
     * A person's request (`$context->interactive`) must name the version it saw: a
     * missing or impossible one is refused with 400 and never reaches the
     * comparison. A trusted system writer (job, import) has no draft to protect and
     * passes none; that is the only way to skip the comparison and a client cannot
     * choose it.
     *
     * @throws ServiceException 400 when an interactive write names no valid version, 409 with the expected and the current version
     */
    public function assertExpectedVersion(int $recordId, int $currentVersion, ?int $expectedVersion, bool $required = false): void
    {
        if ($required && ($expectedVersion === null || $expectedVersion < 1)) {
            throw new ServiceException(
                'The version of the entry you are changing is required (expected_version).',
                Response::HTTP_BAD_REQUEST,
                ['reason' => 'expected_version_required', 'record_id' => $recordId],
            );
        }
        if ($expectedVersion === null || $expectedVersion === $currentVersion) {
            return;
        }

        throw $this->conflict($recordId, $expectedVersion, $currentVersion);
    }

    /**
     * What the record holds right now, by stable field key and language.
     *
     * @return array<string, RevisionField> keyed by {@see RevisionField::identity()}
     */
    public function liveState(int $recordId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT c.id_data_cols AS col, c.id_languages AS lang, dc.field_key, dc.display_name, c.value'
            . ' FROM data_cells c JOIN data_cols dc ON dc.id = c.id_data_cols'
            . ' WHERE c.id_data_rows = :id ORDER BY dc.field_key, c.id_languages',
            ['id' => $recordId],
        );

        $state = [];
        foreach ($rows as $row) {
            $columnId = $this->asInt($row['col']);
            $key = $this->asString($row['field_key']);
            $field = new RevisionField(
                $key !== '' ? $key : 'col_' . $columnId,
                $this->asStringOrNull($row['display_name']),
                $this->asInt($row['lang']),
                $columnId,
                $this->asString($row['value']),
            );
            $state[$field->identity()] = $field;
        }

        return $state;
    }

    /**
     * Record the creation of a record (version 1).
     *
     * @param array<string, RevisionField> $after the cells the new record holds
     */
    public function recordCreated(int $recordId, int $tableId, array $after, RecordWriteContext $context, ?int $transactionId): void
    {
        $this->assertInTransaction();
        $revisionId = $this->insertRevision(
            $recordId,
            $tableId,
            1,
            LookupService::DATA_REVISION_OPERATIONS_CREATE,
            $context->source,
            $transactionId,
            null,
            false,
        );

        $entries = [];
        foreach ($after as $field) {
            $entries[] = ['field' => $field, 'before' => null, 'after' => $this->valueId($recordId, $field->value), 'snapshot' => false];
        }
        $this->insertEntries($revisionId, $entries);
    }

    /**
     * Record one persisted change and advance the record's version by one.
     *
     * Returns null, and writes nothing, when an update or restore changed no
     * field value. A delete is always recorded.
     *
     * @param array<string, RevisionField> $before the cells before the change
     * @param array<string, RevisionField> $after the cells after the change
     * @param string $operation one of the `DATA_REVISION_OPERATIONS_*` codes
     * @return int|null the new version of the record, null for "nothing changed"
     */
    public function recordChange(
        int $recordId,
        int $tableId,
        int $currentVersion,
        array $before,
        array $after,
        RecordWriteContext $context,
        ?int $transactionId,
        string $operation = LookupService::DATA_REVISION_OPERATIONS_UPDATE,
    ): ?int {
        $this->assertInTransaction();

        $changes = $this->diff($before, $after);
        if ($changes === [] && $operation !== LookupService::DATA_REVISION_OPERATIONS_DELETE) {
            return null;
        }

        // A record that predates the history gets its starting point first, with
        // the state it had before this change.
        if (!$this->hasRevisions($recordId)) {
            $this->writeBaseline($recordId, $tableId, $currentVersion, $before);
        }

        $next = $currentVersion + 1;
        $advanced = $this->connection()->executeStatement(
            'UPDATE data_rows SET version = :next WHERE id = :id AND version = :current',
            ['next' => $next, 'id' => $recordId, 'current' => $currentVersion],
        );
        if ($advanced !== 1) {
            // Cannot happen while the record lock is held; refusing is the safe answer if it ever does.
            throw $this->conflict($recordId, $currentVersion, $this->currentVersion($recordId));
        }

        $checkpoint = $next % self::CHECKPOINT_EVERY === 0;
        $revisionId = $this->insertRevision(
            $recordId,
            $tableId,
            $next,
            $operation,
            $context->source,
            $transactionId,
            $context->restoredFromVersion,
            $checkpoint,
        );

        $entries = [];
        foreach ($changes as $change) {
            $field = $change['after'] ?? $change['before'];
            if ($field === null) {
                continue;
            }
            $entries[] = [
                'field' => $field,
                'before' => $change['before'] !== null ? $this->valueId($recordId, $change['before']->value) : null,
                'after' => $change['after'] !== null ? $this->valueId($recordId, $change['after']->value) : null,
                'snapshot' => false,
            ];
        }
        if ($checkpoint) {
            foreach ($after as $field) {
                $entries[] = ['field' => $field, 'before' => null, 'after' => $this->valueId($recordId, $field->value), 'snapshot' => true];
            }
        }
        $this->insertEntries($revisionId, $entries);

        return $next;
    }

    // ------------------------------------------------------------------
    // Reading the history
    // ------------------------------------------------------------------

    public function currentVersion(int $recordId): int
    {
        $version = $this->connection()->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $recordId]);
        if ($version === false) {
            $this->throwNotFound('Record not found');
        }

        return $this->asInt($version);
    }

    /**
     * The current version of several records at once, by record id. A record that does not
     * exist has no entry. Read live (never from a cache), so the version a page offers to a
     * client as `expected_version` is the one the record has when the page is built.
     *
     * @param list<int> $recordIds
     * @return array<int, int> record id => version
     */
    public function currentVersions(array $recordIds): array
    {
        $recordIds = array_values(array_unique(array_filter($recordIds, static fn (int $id): bool => $id > 0)));
        if ($recordIds === []) {
            return [];
        }

        $versions = [];
        foreach ($this->connection()->fetchAllAssociative(
            'SELECT id, version FROM data_rows WHERE id IN (:ids)',
            ['ids' => $recordIds],
            ['ids' => ArrayParameterType::INTEGER],
        ) as $row) {
            $versions[$this->asInt($row['id'] ?? null)] = $this->asInt($row['version'] ?? null);
        }

        return $versions;
    }

    /**
     * One page of the record's history, newest first.
     *
     * With `$allowedFieldKeys` the page is what a reader who may see only those fields
     * may see: an update or restore that touched none of them is left out (it would
     * reveal that a hidden field changed, and by whom), and the changed-field count
     * covers only the visible fields. Creates, deletes and baselines are always shown.
     *
     * @param list<string>|null $allowedFieldKeys null: every field
     * @return array{items: list<array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public function history(int $recordId, int $page = 1, int $pageSize = 20, ?array $allowedFieldKeys = null): array
    {
        $page = max(1, $page);
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, $pageSize));

        $params = ['id' => $recordId];
        $types = [];
        $fieldFilter = '';
        $countExpression = 'SELECT COUNT(*) FROM data_revision_changes ch WHERE ch.id_data_revisions = r.id AND ch.is_snapshot = 0';
        if ($allowedFieldKeys !== null) {
            $params['allowed'] = $allowedFieldKeys === [] ? [''] : $allowedFieldKeys;
            $types['allowed'] = ArrayParameterType::STRING;
            $countExpression .= ' AND ch.field_key IN (:allowed)';
            $fieldFilter = ' AND (op.lookup_code IN (\'create\', \'delete\', \'baseline\') OR EXISTS ('
                . 'SELECT 1 FROM data_revision_changes vch WHERE vch.id_data_revisions = r.id AND vch.is_snapshot = 0 AND vch.field_key IN (:allowed)))';
        }

        $total = $this->asInt($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM data_revisions r JOIN lookups op ON op.id = r.id_operations WHERE r.id_data_rows = :id' . $fieldFilter,
            $params,
            $types,
        ));

        $rows = $this->connection()->fetchAllAssociative(
            $this->headerSelect($countExpression)
            . ' WHERE r.id_data_rows = :id' . $fieldFilter . ' ORDER BY r.version DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $pageSize, 'offset' => ($page - 1) * $pageSize],
            $types + ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->headerItem($row);
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    /**
     * Who, when, how of one revision (the header of {@see history()}).
     *
     * @param list<string>|null $allowedFieldKeys null: every field
     * @return array<string, mixed>
     * @throws ServiceException 404 when the record has no such version
     */
    public function revisionHeader(int $recordId, int $version, ?array $allowedFieldKeys = null): array
    {
        $countExpression = 'SELECT COUNT(*) FROM data_revision_changes ch WHERE ch.id_data_revisions = r.id AND ch.is_snapshot = 0';
        $params = ['id' => $recordId, 'version' => $version];
        $types = [];
        if ($allowedFieldKeys !== null) {
            $params['allowed'] = $allowedFieldKeys === [] ? [''] : $allowedFieldKeys;
            $types['allowed'] = ArrayParameterType::STRING;
            $countExpression .= ' AND ch.field_key IN (:allowed)';
        }

        $row = $this->connection()->fetchAssociative(
            $this->headerSelect($countExpression) . ' WHERE r.id_data_rows = :id AND r.version = :version',
            $params,
            $types,
        );
        if ($row === false) {
            $this->throwNotFound('Version not found');
        }

        return $this->headerItem($row);
    }

    private function headerSelect(string $changedFieldsExpression): string
    {
        return 'SELECT r.version, r.created_at, r.restored_from_version, r.is_checkpoint,'
            . ' op.lookup_code AS operation, src.lookup_code AS source,'
            . ' r.id_users, COALESCE(NULLIF(u.name, \'\'), u.user_name) AS user_name,'
            . ' r.id_impersonating_users, COALESCE(NULLIF(iu.name, \'\'), iu.user_name) AS impersonator_name,'
            . ' (' . $changedFieldsExpression . ') AS changed_fields'
            . ' FROM data_revisions r'
            . ' JOIN lookups op ON op.id = r.id_operations'
            . ' JOIN lookups src ON src.id = r.id_sources'
            . ' LEFT JOIN users u ON u.id = r.id_users'
            . ' LEFT JOIN users iu ON iu.id = r.id_impersonating_users';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function headerItem(array $row): array
    {
        return [
            'version' => $this->asInt($row['version']),
            'created_at' => $this->utc($row['created_at']),
            'operation' => $this->asString($row['operation']),
            'source' => $this->asString($row['source']),
            'actor' => $row['id_users'] !== null
                ? ['id' => $this->asInt($row['id_users']), 'name' => $this->asStringOrNull($row['user_name'])]
                : null,
            'impersonated_by' => $row['id_impersonating_users'] !== null
                ? ['id' => $this->asInt($row['id_impersonating_users']), 'name' => $this->asStringOrNull($row['impersonator_name'])]
                : null,
            'restored_from_version' => $row['restored_from_version'] !== null ? $this->asInt($row['restored_from_version']) : null,
            'changed_fields' => $this->asInt($row['changed_fields']),
            'is_checkpoint' => (bool) $row['is_checkpoint'],
        ];
    }

    /**
     * What one revision changed, field by field.
     *
     * @return list<array<string, mixed>>
     * @throws ServiceException 404 when the record has no such version
     */
    public function revisionChanges(int $recordId, int $version): array
    {
        $revisionId = $this->revisionId($recordId, $version);
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT ch.field_key, ch.field_label, ch.id_languages, ch.id_before_values, ch.id_after_values,'
            . ' bv.content AS before_content, av.content AS after_content'
            . ' FROM data_revision_changes ch'
            . ' LEFT JOIN data_revision_values bv ON bv.id = ch.id_before_values'
            . ' LEFT JOIN data_revision_values av ON av.id = ch.id_after_values'
            . ' WHERE ch.id_data_revisions = :rev AND ch.is_snapshot = 0 ORDER BY ch.field_key, ch.id_languages',
            ['rev' => $revisionId],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->changeItem(
                $this->asString($row['field_key']),
                $this->asStringOrNull($row['field_label']),
                $this->asInt($row['id_languages']),
                $row['before_content'] !== null ? $this->asString($row['before_content']) : null,
                $row['after_content'] !== null ? $this->asString($row['after_content']) : null,
            );
        }

        return $result;
    }

    /**
     * The fields as they stood after the given version, rebuilt from the nearest
     * checkpoint at or before it.
     *
     * @return array<string, RevisionField> keyed by {@see RevisionField::identity()}
     * @throws ServiceException 404 when the record has no such version
     */
    public function stateAt(int $recordId, int $version): array
    {
        $this->revisionId($recordId, $version);

        $checkpoint = $this->connection()->fetchAssociative(
            'SELECT id, version FROM data_revisions'
            . ' WHERE id_data_rows = :row AND is_checkpoint = 1 AND version <= :version ORDER BY version DESC LIMIT 1',
            ['row' => $recordId, 'version' => $version],
        );

        $state = [];
        $from = 0;
        if ($checkpoint !== false) {
            $from = $this->asInt($checkpoint['version']);
            $rows = $this->connection()->fetchAllAssociative(
                'SELECT ch.field_key, ch.field_label, ch.id_languages, ch.id_data_cols, v.content'
                . ' FROM data_revision_changes ch JOIN data_revision_values v ON v.id = ch.id_after_values'
                . ' WHERE ch.id_data_revisions = :rev AND ch.is_snapshot = 1',
                ['rev' => $this->asInt($checkpoint['id'])],
            );
            foreach ($rows as $row) {
                $field = $this->fieldFromRow($row);
                $state[$field->identity()] = $field;
            }
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT ch.field_key, ch.field_label, ch.id_languages, ch.id_data_cols, ch.id_after_values, v.content'
            . ' FROM data_revisions r'
            . ' JOIN data_revision_changes ch ON ch.id_data_revisions = r.id AND ch.is_snapshot = 0'
            . ' LEFT JOIN data_revision_values v ON v.id = ch.id_after_values'
            . ' WHERE r.id_data_rows = :row AND r.version > :from AND r.version <= :to ORDER BY r.version, ch.id',
            ['row' => $recordId, 'from' => $from, 'to' => $version],
        );
        foreach ($rows as $row) {
            $identity = RevisionField::identityOf($this->asString($row['field_key']), $this->asInt($row['id_languages']));
            if ($row['id_after_values'] === null) {
                unset($state[$identity]);
                continue;
            }
            $field = $this->fieldFromRow($row);
            $state[$field->identity()] = $field;
        }

        return $state;
    }

    /**
     * The difference between two versions of a record (from the older state to
     * the newer, or the other way round if asked so).
     *
     * @return list<array<string, mixed>>
     * @throws ServiceException 404 when the record lacks one of the versions
     */
    public function compare(int $recordId, int $fromVersion, int $toVersion): array
    {
        $result = [];
        foreach ($this->diff($this->stateAt($recordId, $fromVersion), $this->stateAt($recordId, $toVersion)) as $change) {
            $field = $change['after'] ?? $change['before'];
            if ($field === null) {
                continue;
            }
            $result[] = $this->changeItem(
                $field->key,
                $field->label,
                $field->languageId,
                $change['before']?->value,
                $change['after']?->value,
            );
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param array<string, RevisionField> $before
     * @param array<string, RevisionField> $after
     * @return list<array{before: ?RevisionField, after: ?RevisionField}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $identity => $field) {
            $old = $before[$identity] ?? null;
            if ($old === null || $old->value !== $field->value) {
                $changes[] = ['before' => $old, 'after' => $field];
            }
        }
        foreach ($before as $identity => $field) {
            if (!isset($after[$identity])) {
                $changes[] = ['before' => $field, 'after' => null];
            }
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function changeItem(string $key, ?string $label, int $languageId, ?string $before, ?string $after): array
    {
        [$beforeValue, $beforeCut] = $this->preview($before);
        [$afterValue, $afterCut] = $this->preview($after);

        return [
            'field_key' => $key,
            'field_label' => $label,
            'language_id' => $languageId,
            'change' => $before === null ? 'added' : ($after === null ? 'removed' : 'changed'),
            'before' => $beforeValue,
            'after' => $afterValue,
            'truncated' => $beforeCut || $afterCut,
            // Stored files the value names. Internal: {@see DataRecordHistoryService} turns
            // them into names and availability, a client never receives the keys.
            'before_file_keys' => $this->fileKeysOf($before),
            'after_file_keys' => $this->fileKeysOf($after),
        ];
    }

    /**
     * @return list<string>
     */
    private function fileKeysOf(?string $value): array
    {
        if (!FormFileUploadService::cellHoldsFiles($value)) {
            return [];
        }

        return FormFileUploadService::keysIn($value);
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function preview(?string $value): array
    {
        if ($value === null || strlen($value) <= self::VALUE_PREVIEW_BYTES) {
            return [$value, false];
        }

        return [mb_strcut($value, 0, self::VALUE_PREVIEW_BYTES), true];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fieldFromRow(array $row): RevisionField
    {
        return new RevisionField(
            $this->asString($row['field_key']),
            $this->asStringOrNull($row['field_label']),
            $this->asInt($row['id_languages']),
            $row['id_data_cols'] !== null ? $this->asInt($row['id_data_cols']) : null,
            $this->asString($row['content']),
        );
    }

    private function hasRevisions(int $recordId): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM data_revisions WHERE id_data_rows = :id LIMIT 1', ['id' => $recordId]) !== false;
    }

    private function revisionId(int $recordId, int $version): int
    {
        $id = $this->connection()->fetchOne(
            'SELECT id FROM data_revisions WHERE id_data_rows = :row AND version = :version',
            ['row' => $recordId, 'version' => $version],
        );
        if ($id === false) {
            $this->throwNotFound('Version not found');
        }

        return $this->asInt($id);
    }

    /**
     * The starting point of a record that existed before history began.
     *
     * @param array<string, RevisionField> $state
     */
    private function writeBaseline(int $recordId, int $tableId, int $version, array $state): void
    {
        $revisionId = $this->insertRevision(
            $recordId,
            $tableId,
            $version,
            LookupService::DATA_REVISION_OPERATIONS_BASELINE,
            LookupService::DATA_REVISION_SOURCES_SYSTEM,
            null,
            null,
            true,
            false,
        );

        $entries = [];
        foreach ($state as $field) {
            $entries[] = ['field' => $field, 'before' => null, 'after' => $this->valueId($recordId, $field->value), 'snapshot' => true];
        }
        $this->insertEntries($revisionId, $entries);
    }

    private function insertRevision(
        int $recordId,
        int $tableId,
        int $version,
        string $operation,
        string $source,
        ?int $transactionId,
        ?int $restoredFromVersion,
        bool $checkpoint,
        bool $withActor = true,
    ): int {
        $this->connection()->executeStatement(
            'INSERT INTO data_revisions (id_data_rows, id_data_tables, version, created_at, id_users, id_impersonating_users,'
            . ' id_operations, id_sources, id_transactions, restored_from_version, is_checkpoint, schema_version)'
            . ' VALUES (:row, :tbl, :version, :at, :actor, :impersonator, :operation, :source, :tx, :restored, :checkpoint, :schema)',
            [
                'row' => $recordId,
                'tbl' => $tableId,
                'version' => $version,
                'at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'actor' => $withActor ? $this->userContextService->getEffectiveUserId() : null,
                'impersonator' => $withActor ? $this->userContextService->getImpersonatedByUserId() : null,
                'operation' => $this->lookupId(LookupService::DATA_REVISION_OPERATIONS, $operation),
                'source' => $this->lookupId(LookupService::DATA_REVISION_SOURCES, $source),
                'tx' => $transactionId,
                'restored' => $restoredFromVersion,
                'checkpoint' => $checkpoint ? 1 : 0,
                'schema' => self::SCHEMA_VERSION,
            ],
        );

        return $this->asInt($this->connection()->lastInsertId());
    }

    /**
     * @param list<array{field: RevisionField, before: ?int, after: ?int, snapshot: bool}> $entries
     */
    private function insertEntries(int $revisionId, array $entries): void
    {
        foreach (array_chunk($entries, self::INSERT_CHUNK) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $index => $entry) {
                $placeholders[] = "(:rev$index, :col$index, :key$index, :label$index, :lang$index, :snap$index, :before$index, :after$index)";
                $params["rev$index"] = $revisionId;
                $params["col$index"] = $entry['field']->columnId;
                $params["key$index"] = $entry['field']->key;
                $params["label$index"] = $entry['field']->label !== null ? mb_substr($entry['field']->label, 0, 255) : null;
                $params["lang$index"] = $entry['field']->languageId;
                $params["snap$index"] = $entry['snapshot'] ? 1 : 0;
                $params["before$index"] = $entry['before'];
                $params["after$index"] = $entry['after'];
            }
            $this->connection()->executeStatement(
                'INSERT INTO data_revision_changes (id_data_revisions, id_data_cols, field_key, field_label, id_languages,'
                . ' is_snapshot, id_before_values, id_after_values) VALUES ' . implode(', ', $placeholders),
                $params,
            );
        }
    }

    /**
     * The id of the stored value with exactly this content for this record,
     * stored now when there is none. The digest finds candidates; the bytes
     * decide, so a collision can never hand out someone else's content. The
     * scope is the record, which the caller holds locked.
     */
    private function valueId(int $recordId, string $content): int
    {
        $hash = hash('sha256', $content);
        $length = strlen($content);

        $existing = $this->connection()->fetchOne(
            'SELECT id FROM data_revision_values'
            . ' WHERE id_data_rows = :row AND content_hash = :hash AND byte_length = :length AND BINARY content = BINARY :content'
            . ' LIMIT 1',
            ['row' => $recordId, 'hash' => $hash, 'length' => $length, 'content' => $content],
        );
        if ($existing !== false) {
            return $this->asInt($existing);
        }

        $this->connection()->executeStatement(
            'INSERT INTO data_revision_values (id_data_rows, content_hash, byte_length, encoding_version, content)'
            . ' VALUES (:row, :hash, :length, :encoding, :content)',
            ['row' => $recordId, 'hash' => $hash, 'length' => $length, 'encoding' => 1, 'content' => $content],
        );

        return $this->asInt($this->connection()->lastInsertId());
    }

    private function lookupId(string $type, string $code): int
    {
        $id = $this->lookupService->getLookupIdByCode($type, $code);
        if ($id === null) {
            throw new ServiceException("Lookup $type/$code is missing", Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->asInt($id);
    }

    private function conflict(int $recordId, int $expectedVersion, int $currentVersion): ServiceException
    {
        return new ServiceException(
            'This entry was changed by someone else after you opened it. Reload it to see the current version.',
            Response::HTTP_CONFLICT,
            [
                'reason' => 'stale_version',
                'record_id' => $recordId,
                'expected_version' => $expectedVersion,
                'current_version' => $currentVersion,
            ],
        );
    }

    private function utc(mixed $value): string
    {
        $text = $this->asString($value);
        try {
            return (new \DateTimeImmutable($text, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return $text;
        }
    }

    private function assertInTransaction(): void
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('The revision history is written inside the transaction of the data change.');
        }
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
