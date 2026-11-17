<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\DataTable;
use App\Entity\DataRow;
use App\Entity\DataCell;
use App\Entity\Language;
use App\Entity\Lookup;
use App\Exception\ServiceException;
use App\Repository\DataTableRepository;
use App\Service\Action\ActionContextBuilderService;
use App\Service\Action\ActionOrchestratorService;
use App\Service\CMS\CmsPreferenceService;
use App\Service\Core\TransactionService;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Auth\UserContextService;
use App\Service\Cache\Core\CacheService;
use App\Service\Core\UserContextAwareService;
use App\Repository\SectionRepository;
use App\Service\CMS\Common\DataTableFilterService;
use App\Service\CMS\DataQuery\DataQueryException;
use App\Service\CMS\DataRevision\DataRestoreService;
use App\Service\CMS\DataRevision\DataRevisionService;
use App\Service\CMS\DataRevision\LockedRecord;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataRevision\RestorePlan;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Core service for handling form data operations with transactions and validation
 * ENTITY RULE - Uses association objects instead of primitive foreign keys
 */
class DataService extends BaseService
{
    /**
     * Key under which hydrated legacy rows (the prefill of a record form, the rows of an entry
     * table) carry the record's current version. Underscore-prefixed, so no input name (they
     * start with a letter) can collide with it, and never part of the form data a client sends back.
     */
    public const RECORD_VERSION_KEY = '_version';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly DataTableRepository $dataTableRepository,
        private readonly LookupService $lookupService,
        private readonly UserContextService $userContextService,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly CacheService $cache,
        private readonly SectionRepository $sectionRepository,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly ActionContextBuilderService $actionContextBuilderService,
        private readonly ActionOrchestratorService $actionOrchestratorService,
        private readonly DataColumnService $dataColumnService,
        private readonly FormFieldKeyResolver $formFieldKeyResolver,
        private readonly LoggerInterface $logger,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly DataTableFilterService $dataTableFilterService,
        private readonly DataRevisionService $dataRevisionService,
        private readonly DataRestoreService $dataRestoreService,
        private readonly DataTableService $dataTableService,
    ) {
    }

    /**
     * Save form data to database with proper transaction handling
     * 
     * @param string $tableName The name of the data table
     * @param array<string, mixed> $data The form data to save
     * @param string $transactionBy Who initiated the transaction
     * @param array<string, mixed>|null $updateBasedOn Optional fields to update existing record
     * @param bool $ownEntriesOnly Whether to restrict updates to user's own entries
     * @param array<string, string|null>|null $fieldLabels Optional field_key => human label
     *   map. Used to auto-populate `data_cols.display_name` (never overwrites a
     *   manually curated label). Form fields and SurveyJS question titles flow in here.
     * @param RecordWriteContext|null $context How the write was reached (history source) and the
     *   record version the caller based an update on. A stale version is refused with 409 before
     *   anything is written. Null: a person's form save when `$transactionBy` is `by_user`, else the platform.
     * @return int|false The record ID on success or false on failure
     * @throws ServiceException
     *
     * After the database transaction commits successfully, this method triggers the
     * Symfony action runtime so jobs are scheduled from a stable post-commit state.
     */
    public function saveData(
        string $tableName,
        array $data,
        string $transactionBy = LookupService::TRANSACTION_BY_BY_USER,
        ?array $updateBasedOn = null,
        bool $ownEntriesOnly = true,
        ?array $fieldLabels = null,
        ?RecordWriteContext $context = null
    ): int|false {
        $context ??= RecordWriteContext::forTransactionBy($transactionBy);
        if ($context->interactive) {
            // A person's request is untrusted: it can neither set row metadata nor aim at a record by anything but its number.
            $this->assertInteractiveWrite($data, $updateBasedOn);
        } else {
            // Core CMS forms submit values keyed by the human input *name*. Remap
            // those to the immutable storage key (`section_<input section id>`) so a
            // later rename only updates the column's display_name instead of forking
            // a new column (issue #56). The human name travels along as the auto
            // display label. Non-form tables (SurveyJS `sh2_surveyjs_*`,
            // `user_validation_inputs`) are left untouched and keep their own keys.
            // An interactive caller resolved the names against the effective form already.
            [$data, $derivedLabels] = $this->mapFormFieldKeys($tableName, $data);
            if ($derivedLabels !== []) {
                // An explicit caller-supplied label wins over the auto-derived one.
                $fieldLabels = array_merge($derivedLabels, $fieldLabels ?? []);
            }
        }

        // Validate submitted field keys up-front (before opening a transaction)
        // so malformed/reserved keys surface as a clean 400 rather than a 500
        // wrapped by the catch block below.
        $this->dataColumnService->assertValidFieldData($data);

        $this->entityManager->beginTransaction();
        $currentUser = $this->userContextAwareService->getCurrentUser();

        try {
            // Ensure user ID is set. Anonymous submissions store NULL (the
            // column is nullable and carries no FK) instead of the admin's
            // id 1 — attributing guest data to the admin was a security bug.
            // Remember whether the caller provided the owner explicitly: on
            // UPDATE the auto-seeded acting user must never reassign the row
            // owner (a foreign editor updating a shared record would silently
            // steal ownership) — only an explicit id_users (system/job flows
            // acting on behalf of a user) may.
            $reassignOwnerOnUpdate = !$context->interactive && isset($data['id_users']);
            if (!$reassignOwnerOnUpdate) {
                $data['id_users'] = $currentUser ? $currentUser->getId() : null;
            }

            // Get or create data table
            $dataTable = $this->getOrCreateDataTable($tableName);

            if ($updateBasedOn !== null && !isset($data['trigger_type'])) {
                $data['trigger_type'] = LookupService::ACTION_TRIGGER_TYPES_UPDATED;
            }

            $actionPayload = $data;
            $actionTriggerType = $this->asString($data['trigger_type'] ?? LookupService::ACTION_TRIGGER_TYPES_FINISHED);
            $payloadUserId = $this->asIntOrNull($actionPayload['id_users'] ?? null);

            // Check for existing record to update
            if ($updateBasedOn !== null) {
                // Special handling for record_id - the row is looked up INSIDE the
                // table this save targets (F127): a record of another table, a
                // missing record and a deleted record are all "not found".
                if (isset($updateBasedOn['record_id'])) {
                    $recordId = $this->asInt($updateBasedOn['record_id']);
                    $dataRow = $this->findRecordInTable((int) $dataTable->getId(), $recordId);

                    if ($dataRow) {
                        // Check ownership if required
                        if ($ownEntriesOnly) {
                            if (!$currentUser || $dataRow->getIdUsers() !== $currentUser->getId()) {
                                throw new ServiceException('Access denied to this record', Response::HTTP_FORBIDDEN);
                            }
                        }

                        // Update the existing record
                        $updatedRecordId = $this->updateExistingRecord($dataRow, $data, $transactionBy, $fieldLabels, $reassignOwnerOnUpdate, $context, $ownEntriesOnly);
                        $this->entityManager->commit();

                        // Invalidate data table cache after updating record
                        $this->invalidateDataTableCache($dataTable, $currentUser ? $currentUser->getId() : null);

                        $this->runActionOrchestration(
                            $dataTable,
                            $updatedRecordId,
                            $actionPayload,
                            $actionTriggerType,
                            $currentUser ? $currentUser->getId() : $payloadUserId,
                            $transactionBy
                        );

                        return $updatedRecordId;
                    } else {
                        // Record not found in this table
                        $this->rollbackQuietly();
                        return false;
                    }
                } else {
                    // Other update filters are read through the data query engine
                    $filter = '';
                    foreach ($updateBasedOn as $key => $value) {
                        $predicate = $this->dataTableFilterService->buildStringEqualityPredicate(
                            $this->asString($key),
                            $this->asString($value),
                        );
                        if ($predicate === '') {
                            $filter = '';
                            break;
                        }
                        $filter .= $predicate;
                    }

                    if ($filter === '' && $context->interactive) {
                        // Never "the first record of the table": a selection the predicate builder refuses selects nothing.
                        throw new ServiceException('The entry cannot be selected that way.', Response::HTTP_BAD_REQUEST, ['reason' => 'unsupported_target_selection']);
                    }

                    $existingRecord = $this->getData((int) $dataTable->getId(), $filter, $ownEntriesOnly, $currentUser?->getId(), true);
                    $existingRow = $existingRecord
                        ? $this->findRecordInTable((int) $dataTable->getId(), $this->asInt($existingRecord['record_id']))
                        : null;

                    if ($existingRow) {
                        $recordId = $this->updateExistingRecord($existingRow, $data, $transactionBy, $fieldLabels, $reassignOwnerOnUpdate, $context, $ownEntriesOnly);
                        $this->entityManager->commit();

                        // Invalidate data table cache after updating record
                        $this->invalidateDataTableCache($dataTable, $currentUser ? $currentUser->getId() : null);

                        $this->runActionOrchestration(
                            $dataTable,
                            $recordId,
                            $actionPayload,
                            $actionTriggerType,
                            $currentUser ? $currentUser->getId() : $payloadUserId,
                            $transactionBy
                        );

                        return $recordId;
                    } elseif (count($updateBasedOn) > 0) {
                        // Trying to update non-existent record
                        $this->rollbackQuietly();
                        return false;
                    }
                }
            }

            // Create new record
            $recordId = $this->createNewRecord($dataTable, $data, $transactionBy, $fieldLabels, $context);

            $this->entityManager->commit();

            // Invalidate data table cache after creating new record
            $this->invalidateDataTableCache($dataTable, $currentUser ? $currentUser->getId() : null);

            $this->runActionOrchestration(
                $dataTable,
                $recordId,
                $actionPayload,
                $actionTriggerType,
                $currentUser ? $currentUser->getId() : $payloadUserId,
                $transactionBy
            );

            return $recordId;

        } catch (ServiceException $e) {
            // A deliberate refusal (forbidden, not found, validation) keeps its own
            // status; it must not be re-labelled as a 500 save failure.
            $this->rollbackQuietly();
            throw $e;
        } catch (\Throwable $e) {
            $this->rollbackQuietly();
            throw new ServiceException(
                'Failed to save form data: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'tableName' => $tableName]
            );
        }
    }

    /**
     * Find a record INSIDE one data table (F127). A record that does not exist,
     * that belongs to another table, or that is soft-deleted all come back as
     * null, so callers can answer them identically ("not found") and never touch
     * a row through a section that is bound to a different table.
     *
     * @param bool $includeDeleted true only for flows that restore or inspect deleted rows
     */
    public function findRecordInTable(int $dataTableId, int $recordId, bool $includeDeleted = false): ?DataRow
    {
        if ($dataTableId <= 0 || $recordId <= 0) {
            return null;
        }

        $dataRow = $this->entityManager->getRepository(DataRow::class)->find($recordId);
        if (!$dataRow instanceof DataRow || $dataRow->getDataTable()?->getId() !== $dataTableId) {
            return null;
        }

        if (!$includeDeleted && $this->isDeletedRow($dataRow)) {
            return null;
        }

        return $dataRow;
    }

    /**
     * The record's current version (1 when created, +1 for every persisted change).
     * Clients send it back as `expected_version` so a stale save is refused.
     */
    public function getRecordVersion(int $recordId): int
    {
        return $this->dataRevisionService->currentVersion($recordId);
    }

    /**
     * Add the record's current version to every row that names a record (`record_id`), under
     * {@see self::RECORD_VERSION_KEY}. The version is read live, in one query, after the cached
     * rows were read, so the client sends back exactly the version it was shown: a record changed in
     * between is refused with 409 and the person's draft is kept.
     *
     * @param array<array-key, mixed> $rows
     * @return array<array-key, mixed>
     */
    public function withRecordVersions(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['record_id'] ?? null)) {
                $ids[] = (int) $row['record_id'];
            }
        }
        if ($ids === []) {
            return $rows;
        }

        $versions = $this->dataRevisionService->currentVersions($ids);
        foreach ($rows as $key => $row) {
            if (is_array($row) && is_numeric($row['record_id'] ?? null) && isset($versions[(int) $row['record_id']])) {
                $row[self::RECORD_VERSION_KEY] = $versions[(int) $row['record_id']];
                $rows[$key] = $row;
            }
        }

        return $rows;
    }

    /**
     * Owner of a record of the given table; null when the record is not in that
     * table (see {@see findRecordInTable()}) or has no owner.
     */
    public function getRecordOwnerId(int $dataTableId, int $recordId): ?int
    {
        $dataRow = $this->findRecordInTable($dataTableId, $recordId);

        return $dataRow ? $dataRow->getIdUsers() : null;
    }

    /**
     * Delete form data record
     *
     * @param int $dataTableId The table the caller is authorized for; the record must belong to it (F127)
     * @param int $recordId The ID of the record to delete
     * @param bool $ownEntriesOnly Whether to restrict to user's own entries
     * @param RecordWriteContext|null $context History source and the record version the delete was based on (a stale version is refused with 409)
     * @return bool Success status
     * @throws ServiceException
     *
     * Queued action jobs linked to the deleted record are cleaned up after commit
     * through the same action orchestration pipeline used for save/update events.
     * The record is only marked deleted and its history is kept, so the uploaded
     * files stay too; explicit user-data erasure removes both.
     */
    public function deleteData(int $dataTableId, int $recordId, bool $ownEntriesOnly = true, ?RecordWriteContext $context = null): bool
    {
        $context ??= RecordWriteContext::forTransactionBy(LookupService::TRANSACTION_BY_BY_USER);
        $this->entityManager->beginTransaction();
        $currentUser = $this->userContextService->getCurrentUser();

        try {
            $dataRow = $this->findRecordInTable($dataTableId, $recordId);
            if (!$dataRow) {
                $this->throwNotFound('Record not found');
            }

            // Check ownership if required
            if ($ownEntriesOnly) {
                if (!$currentUser || $dataRow->getIdUsers() !== $currentUser->getId()) {
                    $this->throwForbidden('Access denied to this record');
                }
            }

            $dataTable = $dataRow->getDataTable();
            if (!$dataTable) {
                $this->throwNotFound('Data table not found for record');
            }

            // Take the record lock and refuse a delete based on an older version or of a record that left the caller's dataset.
            $currentVersion = $this->lockAndGuard($dataRow, $context, $ownEntriesOnly)->version;
            $stateBefore = $this->dataRevisionService->liveState($recordId);

            // Mark as deleted instead of physical deletion. The history and the
            // uploaded files stay: they are removed only by explicit erasure.
            $deletedTriggerType = $this->lookupService->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);
            $dataRow->setIdActionTriggerTypes($deletedTriggerType);
            $deletedValues = $this->extractRecordValues($dataRow);

            // Log transaction
            $transaction = $this->transactionService->logTransaction(
                'delete',
                LookupService::TRANSACTION_BY_BY_USER,
                'data_tables',
                $dataTable->getId()
            );

            $this->entityManager->flush();
            $newVersion = $this->dataRevisionService->recordChange(
                $recordId,
                (int) $dataTable->getId(),
                $currentVersion,
                $stateBefore,
                $stateBefore,
                $context,
                $transaction->getId(),
                LookupService::DATA_REVISION_OPERATIONS_DELETE,
            );
            $dataRow->setVersion($newVersion ?? $currentVersion);
            $this->entityManager->commit();

            // Invalidate data table cache after deleting record
            $this->invalidateDataTableCache($dataTable, $currentUser ? $currentUser->getId() : null);

            $this->runActionOrchestration(
                $dataTable,
                (int) $dataRow->getId(),
                $deletedValues,
                LookupService::ACTION_TRIGGER_TYPES_DELETED,
                $currentUser ? $currentUser->getId() : $dataRow->getIdUsers(),
                LookupService::TRANSACTION_BY_BY_USER
            );

            return true;

        } catch (ServiceException $e) {
            // A deliberate refusal (not found, forbidden) keeps its own status.
            $this->rollbackQuietly();
            throw $e;
        } catch (\Throwable $e) {
            $this->rollbackQuietly();
            throw new ServiceException(
                'Failed to delete record: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'recordId' => $recordId]
            );
        }
    }

    /**
     * What restoring an earlier version of a record would do right now, and what
     * stands in the way ({@see RestorePlan}). Reads only; nothing is written.
     *
     * @param list<string>|null $fieldKeys restore only these fields; null: every field that differs
     * @throws ServiceException 404 record/version unknown, 403 when `$ownEntriesOnly` and the record is not the caller's
     */
    public function previewRestore(int $dataTableId, int $recordId, int $fromVersion, ?array $fieldKeys, bool $ownEntriesOnly = true): RestorePlan
    {
        $dataRow = $this->findRecordInTable($dataTableId, $recordId);
        if (!$dataRow) {
            $this->throwNotFound('Record not found');
        }
        $this->assertMayWriteRecord($dataRow, $ownEntriesOnly);

        return $this->dataRestoreService->plan($recordId, $fromVersion, $fieldKeys);
    }

    /**
     * Restore an earlier version of a record as a NEW revision.
     *
     * The fields of `$fromVersion` are written back, fields the record has now but
     * that version lacked are removed, and everything else (the owner, the
     * remaining fields) stays. The history is appended to, never rewound: the
     * versions between the old state and now stay readable. Like every write it
     * takes the record lock and refuses (409) a save based on an older version, so
     * `$context->expectedVersion` is REQUIRED: the person saw version N and chose
     * to replace it.
     *
     * Nothing is applied partially: a field that cannot be restored today (its
     * column or language is gone, the value is invalid for its column type, a file
     * it names was erased) makes the whole restore fail with 422 and the list of
     * blockers; the caller may then restore an explicit subset (`$fieldKeys`).
     * Restoring what the record already holds writes nothing and creates no revision.
     *
     * @param list<string>|null $fieldKeys restore only these fields; null: every field that differs
     * @return int the record's version after the restore (unchanged when nothing differed)
     * @throws ServiceException 400 without expected version, 404 unknown record/version, 409 stale, 422 blocked
     */
    public function restoreRecord(
        int $dataTableId,
        int $recordId,
        int $fromVersion,
        ?array $fieldKeys,
        bool $ownEntriesOnly,
        RecordWriteContext $context
    ): int {
        if ($context->expectedVersion === null || $context->expectedVersion < 1) {
            throw new ServiceException(
                'expected_version is required to restore a record',
                Response::HTTP_BAD_REQUEST,
                ['reason' => 'expected_version_required', 'record_id' => $recordId],
            );
        }
        $context = $context->restoringFrom($fromVersion);

        $this->entityManager->beginTransaction();
        $currentUser = $this->userContextService->getCurrentUser();

        try {
            $dataRow = $this->findRecordInTable($dataTableId, $recordId);
            if (!$dataRow) {
                $this->throwNotFound('Record not found');
            }
            $this->assertMayWriteRecord($dataRow, $ownEntriesOnly);

            $dataTable = $dataRow->getDataTable();
            if (!$dataTable) {
                $this->throwNotFound('Data table not found for record');
            }

            // The record lock first, then the plan: the plan must describe the state
            // this restore is going to replace.
            $currentVersion = $this->lockAndGuard($dataRow, $context, $ownEntriesOnly)->version;
            $plan = $this->dataRestoreService->plan($recordId, $fromVersion, $fieldKeys);
            if (!$plan->isRestorable()) {
                throw new ServiceException(
                    'Version ' . $fromVersion . ' cannot be restored as it is: some of its fields no longer fit this form.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['reason' => 'restore_blocked', 'record_id' => $recordId, 'from_version' => $fromVersion, 'blockers' => $plan->blockers]
                );
            }
            // What the restore would write must also satisfy the form as it is now (a rule that changed since
            // the old version, a field that is required today). Refused as a whole, before anything is written.
            if ($context->planGuard !== null) {
                ($context->planGuard)($plan);
            }
            if ($plan->changes === []) {
                $this->entityManager->commit();

                return $currentVersion;
            }

            $stateBefore = $this->dataRevisionService->liveState($recordId);
            $this->dataRestoreService->apply($plan);

            // The owner is not touched: a restore changes what the record says, not whose it is.
            $dataRow->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $dataRow->setIdActionTriggerTypes($this->getTriggerTypeId(['trigger_type' => LookupService::ACTION_TRIGGER_TYPES_UPDATED]));

            $transaction = $this->transactionService->logTransaction(
                'update',
                LookupService::TRANSACTION_BY_BY_USER,
                'data_tables',
                $dataTable->getId()
            );
            $this->entityManager->flush();

            $stateAfter = $this->dataRevisionService->liveState($recordId);
            $newVersion = $this->dataRevisionService->recordChange(
                $recordId,
                (int) $dataTable->getId(),
                $currentVersion,
                $stateBefore,
                $stateAfter,
                $context,
                $transaction->getId(),
                LookupService::DATA_REVISION_OPERATIONS_RESTORE,
            );
            $dataRow->setVersion($newVersion ?? $currentVersion);
            $this->entityManager->commit();

            $this->invalidateDataTableCache($dataTable, $currentUser ? $currentUser->getId() : null);

            // Actions see the record as it is after the restore, like after any update.
            $values = ['record_id' => $recordId, 'id_users' => $dataRow->getIdUsers()];
            foreach ($stateAfter as $field) {
                if ($field->languageId === 1) {
                    $values[$field->key] = $field->value;
                    continue;
                }
                $existing = $values[$field->key] ?? [];
                $values[$field->key] = [...(is_array($existing) ? $existing : []), ['language_id' => $field->languageId, 'value' => $field->value]];
            }
            $this->runActionOrchestration(
                $dataTable,
                $recordId,
                $values,
                LookupService::ACTION_TRIGGER_TYPES_UPDATED,
                $currentUser ? $currentUser->getId() : $dataRow->getIdUsers(),
                LookupService::TRANSACTION_BY_BY_USER
            );

            return $newVersion ?? $currentVersion;
        } catch (ServiceException $e) {
            $this->rollbackQuietly();
            throw $e;
        } catch (\Throwable $e) {
            $this->rollbackQuietly();
            throw new ServiceException(
                'Failed to restore record: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'recordId' => $recordId]
            );
        }
    }

    /**
     * A person's request is untrusted: the owner of a record, its trigger type and the other row metadata are
     * derived by the server, and the record an update changes is named by its number, nothing else.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $updateBasedOn
     * @throws ServiceException 400 a protected field or a target that is not `record_id`
     */
    private function assertInteractiveWrite(array $data, ?array $updateBasedOn): void
    {
        foreach (array_keys($data) as $key) {
            $key = (string) $key;
            if ($this->dataColumnService->isReservedKey($key) || in_array($key, ['timestamp', 'version'], true)) {
                throw new ServiceException(
                    sprintf('The field "%s" is not a value a person can set.', $key),
                    Response::HTTP_BAD_REQUEST,
                    ['reason' => 'protected_field', 'fields' => [$key]],
                );
            }
        }

        if ($updateBasedOn === null) {
            return;
        }

        // The entry an update changes is named either by its number or by field values (the documented
        // alternative selection), never by both and never by an empty or malformed selection: an empty
        // selection must not fall back to "the first record of the table".
        $invalid = fn (string $message): ServiceException => new ServiceException(
            $message,
            Response::HTTP_BAD_REQUEST,
            ['reason' => 'unsupported_target_selection'],
        );
        if ($updateBasedOn === []) {
            throw $invalid('An update names the entry it changes.');
        }
        if (array_key_exists('record_id', $updateBasedOn)) {
            $recordId = $updateBasedOn['record_id'];
            $number = is_int($recordId) ? $recordId : (is_string($recordId) && ctype_digit($recordId) ? (int) $recordId : 0);
            if (count($updateBasedOn) !== 1 || $number < 1) {
                throw $invalid('An update names the entry by its record_id alone, or by field values.');
            }

            return;
        }
        foreach ($updateBasedOn as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[A-Za-z0-9_]{1,255}$/', $key) !== 1 || $this->dataColumnService->isReservedKey($key) || !is_scalar($value)) {
                throw $invalid(sprintf('The entry cannot be selected by "%s".', $key));
            }
        }
    }

    /**
     * Take the record lock and run the checks that must see the record as it is under it.
     *
     * Everything that was decided before the transaction (the caller's right to the record, the
     * dataset of the list, the version the caller saw) is decided again here for an interactive write,
     * because the record may have changed while the request waited for the lock:
     *  1. a record that was deleted meanwhile is not found;
     *  2. a caller restricted to its own records still owns it;
     *  3. the version the caller saw is the version the record has (409 otherwise; an interactive write
     *     that names none is refused with 400, a trusted system write may name none);
     *  4. the guard of the context accepts the record (the dataset boundary of a list).
     *
     * @throws ServiceException 404, 403, 400, 409 as above
     */
    private function lockAndGuard(DataRow $dataRow, RecordWriteContext $context, bool $ownEntriesOnly): LockedRecord
    {
        $recordId = (int) $dataRow->getId();
        $locked = $this->dataRevisionService->lockRecord($recordId);

        if ($context->interactive) {
            if ($locked->tableId !== (int) $dataRow->getDataTable()?->getId() || $this->isDeletedTrigger($locked->triggerTypeId)) {
                $this->throwNotFound('Record not found');
            }
            if ($ownEntriesOnly) {
                $currentUser = $this->userContextService->getCurrentUser();
                if (!$currentUser || $locked->ownerId !== $currentUser->getId()) {
                    $this->throwForbidden('Access denied to this record');
                }
            }
        }

        $this->dataRevisionService->assertExpectedVersion($recordId, $locked->version, $context->expectedVersion, $context->interactive);

        if ($context->targetGuard !== null) {
            ($context->targetGuard)($locked);
        }

        return $locked;
    }

    private function isDeletedTrigger(?int $triggerTypeId): bool
    {
        $deletedId = $this->lookupService->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);

        return $deletedId !== null && $triggerTypeId === $deletedId;
    }

    /**
     * The caller must be the record's owner when the access is own-entries-only.
     */
    private function assertMayWriteRecord(DataRow $dataRow, bool $ownEntriesOnly): void
    {
        if (!$ownEntriesOnly) {
            return;
        }
        $currentUser = $this->userContextService->getCurrentUser();
        if (!$currentUser || $dataRow->getIdUsers() !== $currentUser->getId()) {
            $this->throwForbidden('Access denied to this record');
        }
    }

    /**
     * @return array<string, mixed>
     *   Flattened record values used to build a delete-trigger action context.
     */
    private function extractRecordValues(DataRow $dataRow): array
    {
        $values = [
            'record_id' => $dataRow->getId(),
            'id_users' => $dataRow->getIdUsers(),
        ];

        $dataCells = $this->entityManager->getRepository(DataCell::class)->findBy([
            'dataRow' => $dataRow,
        ]);

        foreach ($dataCells as $dataCell) {
            $fieldName = $this->asString($dataCell->getDataCol()?->getFieldKey());
            $language = $dataCell->getLanguage()?->getId();

            if ($language && $language !== 1) {
                if (!isset($values[$fieldName]) || !is_array($values[$fieldName])) {
                    $values[$fieldName] = [];
                }
                $values[$fieldName][] = [
                    'language_id' => $language,
                    'value' => $dataCell->getValue(),
                ];
                continue;
            }

            $values[$fieldName] = $dataCell->getValue();
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $submittedValues
     *   The values that should be exposed to the action runtime.
     *
     * Runs after the data transaction commits so scheduled jobs are created from
     * persisted rows and cannot roll back the original user data save.
     */
    private function runActionOrchestration(
        DataTable $dataTable,
        int $recordId,
        array $submittedValues,
        string $triggerType,
        ?int $userId,
        string $transactionBy
    ): void {
        try {
            $dataRow = $this->entityManager->getRepository(DataRow::class)->find($recordId);
            if (!$dataRow) {
                return;
            }

            $context = $this->actionContextBuilderService->build(
                $dataTable,
                $dataRow,
                $submittedValues,
                $triggerType,
                $userId ? (int) $userId : null,
                $transactionBy
            );
            $this->actionOrchestratorService->handle($context);
        } catch (\Throwable $e) {
            $this->logger->error('Post-commit action orchestration failed', [
                'dataTableId' => $dataTable->getId(),
                'recordId' => $recordId,
                'triggerType' => $triggerType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get or create a data table by name
     * 
     * @param string $tableName The name of the table
     * @return DataTable
     */
    private function getOrCreateDataTable(string $tableName): DataTable
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $tableName]);

        if (!$dataTable) {
            $dataTable = new DataTable();
            $dataTable->setName($tableName);
            // DataTable constructor already sets UTC timestamp, but override if needed
            $dataTable->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            $this->entityManager->persist($dataTable);
            $this->entityManager->flush(); // Flush to get the ID
        }

        $this->dataTableService->backfillAutoDisplayNameFromOwningSection($dataTable);

        return $dataTable;
    }

    /**
     * Roll the open transaction back, tolerating the case where it is already
     * closed (a refusal thrown after an explicit rollback must not mask itself
     * with a "no active transaction" error).
     */
    private function rollbackQuietly(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }
    }

    /**
     * Whether a row carries the `deleted` action trigger type (soft delete).
     */
    private function isDeletedRow(DataRow $dataRow): bool
    {
        $deletedId = $this->lookupService->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);

        return $deletedId !== null && $dataRow->getIdActionTriggerTypes() === $deletedId;
    }

    /**
     * Update existing record
     * 
     * @param DataRow $dataRow The record to update (already resolved INSIDE the target table, see {@see findRecordInTable()})
     * @param array<string, mixed> $data New data
     * @param string $transactionBy Transaction initiator
     * @param array<string, string|null>|null $fieldLabels Optional field_key => label map
     * @param bool $reassignOwner Whether $data['id_users'] was explicitly provided
     *                            by the caller; when false the existing owner is
     *                            preserved (a foreign editor must not steal the row)
     * @param RecordWriteContext $context History source and the version the caller based the update on
     * @param bool $ownEntriesOnly the caller may change only its own records (checked again under the lock)
     * @return int Record ID
     */
    private function updateExistingRecord(DataRow $dataRow, array $data, string $transactionBy, ?array $fieldLabels, bool $reassignOwner, RecordWriteContext $context, bool $ownEntriesOnly): int
    {
        $dataTable = $dataRow->getDataTable();
        if (!$dataTable) {
            $this->throwNotFound('Data table not found for record');
        }

        // The record lock serialises the writers of this record. A save based on
        // an older version stops here, before anything is written, and so does a
        // record that left the caller's dataset while the request waited.
        $recordId = (int) $dataRow->getId();
        $currentVersion = $this->lockAndGuard($dataRow, $context, $ownEntriesOnly)->version;
        $stateBefore = $this->dataRevisionService->liveState($recordId);

        // Update timestamp and trigger type (use UTC for consistency)
        $dataRow->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if ($reassignOwner) {
            $dataRow->setIdUsers($this->asIntOrNull($data['id_users'] ?? null));
        }

        $triggerTypeId = $this->getTriggerTypeId($data);
        $dataRow->setIdActionTriggerTypes($triggerTypeId);

        // Strip reserved/row-metadata keys (id_users, trigger_type, ...) so they
        // never become dynamic data columns, then resolve columns by immutable key.
        $fieldData = $this->dataColumnService->filterFieldData($data);
        $columns = $this->dataColumnService->resolveColumns($dataTable, array_keys($fieldData), $fieldLabels ?? []);

        foreach ($fieldData as $fieldName => $fieldValue) {
            $column = $columns[$fieldName];

            // Handle language-specific data
            if (is_array($fieldValue) && isset($fieldValue[0]) && is_array($fieldValue[0]) && isset($fieldValue[0]['language_id'])) {
                // Multi-language field: array of {language_id, value} objects
                foreach ($fieldValue as $languageData) {
                    if (is_array($languageData) && isset($languageData['language_id']) && isset($languageData['value'])) {
                        $language = $this->entityManager->getRepository(Language::class)->find($languageData['language_id']);
                        if ($language) {
                            // Find existing cell or create new one for this language
                            $dataCell = $this->entityManager->getRepository(DataCell::class)
                                ->findOneBy(['dataRow' => $dataRow, 'dataCol' => $column, 'language' => $language]);

                            if (!$dataCell) {
                                $dataCell = new DataCell();
                                $dataCell->setDataRow($dataRow);
                                $dataCell->setDataCol($column);
                                $dataCell->setLanguage($language);
                                $this->entityManager->persist($dataCell);
                            }

                            $dataCell->setValue($this->asString($languageData['value']));
                        }
                    }
                }
            } else {
                // Single language field (default language 1)
                $defaultLanguage = $this->entityManager->getRepository(Language::class)->find(1);
                if ($defaultLanguage) {
                    // Find existing cell or create new one for default language
                    $dataCell = $this->entityManager->getRepository(DataCell::class)
                        ->findOneBy(['dataRow' => $dataRow, 'dataCol' => $column, 'language' => $defaultLanguage]);

                    if (!$dataCell) {
                        $dataCell = new DataCell();
                        $dataCell->setDataRow($dataRow);
                        $dataCell->setDataCol($column);
                        $dataCell->setLanguage($defaultLanguage);
                        $this->entityManager->persist($dataCell);
                    }

                    //if field value is empty array, set it to []
                    if (is_array($fieldValue) && empty($fieldValue)) {
                        $fieldValue = '[]';
                    }
                    $dataCell->setValue($this->asString($fieldValue));
                }
            }
        }

        // Log transaction
        $transaction = $this->transactionService->logTransaction(
            'update',
            $transactionBy,
            'data_tables',
            $dataTable->getId()
        );

        $this->entityManager->flush();

        // History: one revision, and the version moves forward by one, only when a value really changed.
        $newVersion = $this->dataRevisionService->recordChange(
            $recordId,
            (int) $dataTable->getId(),
            $currentVersion,
            $stateBefore,
            $this->dataRevisionService->liveState($recordId),
            $context,
            $transaction->getId(),
            $context->restoredFromVersion !== null
                ? LookupService::DATA_REVISION_OPERATIONS_RESTORE
                : LookupService::DATA_REVISION_OPERATIONS_UPDATE,
        );
        $dataRow->setVersion($newVersion ?? $currentVersion);

        return $recordId;
    }

    /**
     * Create new record
     * 
     * @param DataTable $dataTable The data table
     * @param array<string, mixed> $data Form data
     * @param string $transactionBy Transaction initiator
     * @param array<string, string|null>|null $fieldLabels Optional field_key => label map
     * @return int Record ID
     */
    private function createNewRecord(DataTable $dataTable, array $data, string $transactionBy, ?array $fieldLabels, RecordWriteContext $context): int
    {
        // Create data row
        $dataRow = new DataRow();
        $dataRow->setDataTable($dataTable);
        // DataRow constructor already sets UTC timestamp, but override if needed
        $dataRow->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $dataRow->setIdUsers($this->asIntOrNull($data['id_users'] ?? null));

        $triggerTypeId = $this->getTriggerTypeId($data);
        $dataRow->setIdActionTriggerTypes($triggerTypeId);

        $this->entityManager->persist($dataRow);
        $this->entityManager->flush(); // Flush to get the ID

        // Strip reserved/row-metadata keys (id_users, trigger_type, ...) so they
        // never become dynamic data columns, then resolve columns by immutable key.
        $fieldData = $this->dataColumnService->filterFieldData($data);
        $columns = $this->dataColumnService->resolveColumns($dataTable, array_keys($fieldData), $fieldLabels ?? []);

        // Create data cells
        foreach ($fieldData as $fieldName => $fieldValue) {
            $column = $columns[$fieldName];

            // Handle language-specific data
            if (is_array($fieldValue) && isset($fieldValue[0]) && is_array($fieldValue[0]) && isset($fieldValue[0]['language_id'])) {
                // Multi-language field: array of {language_id, value} objects
                foreach ($fieldValue as $languageData) {
                    if (is_array($languageData) && isset($languageData['language_id']) && isset($languageData['value'])) {
                        $language = $this->entityManager->getRepository(Language::class)->find($languageData['language_id']);
                        if ($language) {
                            $dataCell = new DataCell();
                            $dataCell->setDataRow($dataRow);
                            $dataCell->setDataCol($column);
                            $dataCell->setLanguage($language);
                            $dataCell->setValue($this->asString($languageData['value']));
                            $this->entityManager->persist($dataCell);
                        }
                    }
                }
            } else {
                // Single language field (default language 1)
                $defaultLanguage = $this->entityManager->getRepository(Language::class)->find(1);
                if ($defaultLanguage) {
                    $dataCell = new DataCell();
                    $dataCell->setDataRow($dataRow);
                    $dataCell->setDataCol($column);
                    $dataCell->setLanguage($defaultLanguage);
                    //if field value is empty array, set it to []
                    if (is_array($fieldValue) && empty($fieldValue)) {
                        $fieldValue = '[]';
                    }
                    $dataCell->setValue($this->asString($fieldValue));
                    $this->entityManager->persist($dataCell);
                }
            }
        }

        // Log transaction
        $transaction = $this->transactionService->logTransaction(
            'insert',
            $transactionBy,
            'data_tables',
            $dataTable->getId()
        );

        $this->entityManager->flush();

        // History: version 1 of the record, with every value it starts with.
        $recordId = (int) $dataRow->getId();
        $this->dataRevisionService->recordCreated(
            $recordId,
            (int) $dataTable->getId(),
            $this->dataRevisionService->liveState($recordId),
            $context,
            $transaction->getId(),
        );

        return $recordId;
    }

    /**
     * Get trigger type ID from form data
     * 
     * @param array<string, mixed> $data Form data
     * @return int Trigger type ID
     */
    private function getTriggerTypeId(array $data): int
    {
        $triggerType = $this->asString($data['trigger_type'] ?? LookupService::ACTION_TRIGGER_TYPES_FINISHED);

        $validTriggerTypes = [
            LookupService::ACTION_TRIGGER_TYPES_STARTED,
            LookupService::ACTION_TRIGGER_TYPES_UPDATED,
            LookupService::ACTION_TRIGGER_TYPES_DELETED,
            LookupService::ACTION_TRIGGER_TYPES_FINISHED
        ];

        if (!in_array($triggerType, $validTriggerTypes, true)) {
            $triggerType = LookupService::ACTION_TRIGGER_TYPES_FINISHED;
        }

        return $this->asInt($this->lookupService->getLookupIdByValue('actionTriggerTypes', $triggerType));
    }

    /**
     * Get data table by name
     * 
     * @param string $tableName Table name
     * @return DataTable|null
     */
    public function getDataTableByName(string $tableName): ?DataTable
    {
        return $this->dataTableRepository->findOneBy(['name' => $tableName]);
    }

    /**
     * Get data table by numeric id
     */
    public function getDataTableById(int $dataTableId): ?DataTable
    {
        return $this->dataTableRepository->find($dataTableId);
    }

    /**
     * Get data table by display name
     * 
     * @param string $displayName Display name
     * @return DataTable|null
     */
    public function getDataTableByDisplayName(string $displayName): ?DataTable
    {
        return $this->dataTableRepository->findOneBy(['displayName' => $displayName]);
    }

    /**
     * Remap a core form submission from human input names to immutable
     * `section_<id>` storage keys (issue #56). Reserved/metadata keys
     * (`id_users`, `trigger_type`, ...) are never remapped. A submitted name with
     * no matching input section is kept as-is so data is never dropped. Returns
     * the remapped data plus the auto display labels (field key => human name) to
     * merge into `fieldLabels`.
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function mapFormFieldKeys(string $tableName, array $data): array
    {
        $nameToFieldKey = $this->formFieldKeyResolver->getNameToFieldKey($tableName);
        if ($nameToFieldKey === []) {
            return [$data, []];
        }

        $remapped = [];
        $labels = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if ($this->dataColumnService->isReservedKey($key)) {
                $remapped[$key] = $value;
                continue;
            }
            if (isset($nameToFieldKey[$key])) {
                $fieldKey = $nameToFieldKey[$key];
                $remapped[$fieldKey] = $value;
                $labels[$fieldKey] = $key;
            } else {
                $remapped[$key] = $value;
            }
        }

        return [$remapped, $labels];
    }

    /**
     * Reverse of {@see mapFormFieldKeys} for reads: rename each record's
     * `section_<id>` data keys back to the current human input name so the
     * frontend/mobile (which bind by input name) prefill unchanged. Metadata
     * keys pass through. No-op for non-form tables.
     *
     * @param array<array-key, mixed> $records
     * @return array<array-key, mixed>
     */
    private function remapRecordKeysToInputNames(string $tableName, array $records): array
    {
        $keyToName = $this->formFieldKeyResolver->getFieldKeyToName($tableName);
        if ($keyToName === []) {
            return $records;
        }

        $out = [];
        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $out[$index] = $record;
                continue;
            }
            $mapped = [];
            foreach ($record as $key => $value) {
                $key = (string) $key;
                if (isset($keyToName[$key])) {
                    $mapped[$keyToName[$key]] = $value;
                    continue;
                }
                // Contract 3 suffixes built before remap (data-view row tokens):
                // section_12__url / __files / __links → portrait__url / …
                foreach (['__url', '__files', '__links'] as $suffix) {
                    if (str_ends_with($key, $suffix)) {
                        $base = substr($key, 0, -strlen($suffix));
                        if (isset($keyToName[$base])) {
                            $mapped[$keyToName[$base] . $suffix] = $value;
                            continue 2;
                        }
                    }
                }
                $mapped[$key] = $value;
            }
            $out[$index] = $mapped;
        }

        return $out;
    }

    /**
     * Remap entry-table entries (keyed by `field_key`) back to the current
     * human input name for display, so a renamed input shows the new label and
     * one logical column instead of section ids. No-op for non-form data tables
     * (e.g. SurveyJS tables keep `question.name` headers).
     *
     * @param array<array-key, mixed> $entries
     * @return array<array-key, mixed>
     */
    public function remapEntriesToInputNames(int $dataTableId, array $entries): array
    {
        $dataTable = $this->dataTableRepository->find($dataTableId);
        if (!$dataTable) {
            return $entries;
        }

        return $this->remapRecordKeysToInputNames((string) $dataTable->getName(), $entries);
    }

    /**
     * `field_key => display_name` for a data table's curated columns, so
     * renderers (e.g. entry-table) can show human labels as headers while
     * keying cells by the immutable `field_key` (issue #56 v2). `display_name`
     * falls back to the `field_key` when not curated. Standard projection
     * columns (record_id, entry_date, …) are not included — they render under
     * their own key. Cached per table and invalidated on column changes.
     *
     * @return array<string, string>
     */
    public function getColumnDisplayLabels(int $dataTableId): array
    {
        if ($dataTableId <= 0) {
            return [];
        }

        return $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId)
            ->getList("column_display_labels_{$dataTableId}", function () use ($dataTableId) {
                $rows = $this->entityManager->getConnection()->executeQuery(
                    'SELECT field_key, display_name FROM data_cols WHERE id_data_tables = :id ORDER BY field_key',
                    ['id' => $dataTableId],
                    ['id' => \Doctrine\DBAL\ParameterType::INTEGER]
                )->fetchAllAssociative();

                $labels = [];
                foreach ($rows as $row) {
                    $fieldKey = isset($row['field_key']) && is_scalar($row['field_key']) ? (string) $row['field_key'] : '';
                    if ($fieldKey === '') {
                        continue;
                    }
                    $displayName = isset($row['display_name']) && is_scalar($row['display_name']) ? (string) $row['display_name'] : '';
                    $labels[$fieldKey] = $displayName !== '' ? $displayName : $fieldKey;
                }

                return $labels;
            });
    }

    /**
     * Get the last record of a data table
     *
     * @param string $dataTableName Data table name
     * @return array<array-key, mixed>
     */
    public function getFormRecordData(string $dataTableName): array
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $dataTableName]);
        if (!$dataTable) {
            return [];
        }
        $dataTableId = (int) $dataTable->getId();
        $data = $this->getData($dataTableId, 'ORDER BY record_id DESC LIMIT 1', true, $this->userContextService->getCurrentUser()?->getId(), false, true);
        return $data;
    }

    /**
     * Get form record data with all languages
     *
     * @param string $dataTableName Data table name
     * @return array<array-key, mixed>
     */
    public function getFormRecordDataWithAllLanguages(string $dataTableName): array
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $dataTableName]);
        if (!$dataTable) {
            return [];
        }

        $dataTableId = (int) $dataTable->getId();
        $currentUser = $this->userContextService->getCurrentUser();
        $userId = $currentUser ? $currentUser->getId() : null;

        if (!$userId) {
            return []; // No user, no form record data
        }

        $cacheKey = "form_record_data_{$dataTableName}_{$userId}";

        $records = $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList($cacheKey, function () use ($dataTableId, $userId) {
                return $this->getDataWithAllLanguages($dataTableId, 'ORDER BY record_id DESC LIMIT 1', true, $userId, false, true);
            });

        // Records are stored keyed by the immutable section-id field_key; rename
        // them back to the current human input name for prefill (issue #56).
        // Applied after the cache read so a rename reflects immediately. The same goes for the
        // record version the person's next save must name (`expected_version`).
        return $this->withRecordVersions($this->remapRecordKeysToInputNames($dataTableName, $records));
    }

    /**
     * Record-scoped variant of {@see getFormRecordDataWithAllLanguages} for the
     * form-record edit mode (issue #30): load ONE specific record (all
     * languages) for prefill, identified by its record id (typically taken
     * from a route parameter via the style's `load_record_from` field).
     *
     * Permission mirrors {@see FormController::updateForm} through
     * DataAccessSecurityService::canUpdateOwnedRecord: the user's own record
     * always loads; with $ownEntriesOnly=true a foreign record never loads;
     * with $ownEntriesOnly=false a foreign record loads only when the user
     * holds UPDATE permission on the form's data table. A record the user may
     * not edit prefills nothing (empty array).
     *
     * @param bool $withVersion Add the record's current version to every row (`_version`, see
     *   {@see self::withRecordVersions()}); a caller that already knows the version (an entry list)
     *   passes false and saves the query
     * @return array<array-key, mixed>
     */
    public function getFormRecordDataForRecord(string $dataTableName, int $recordId, bool $ownEntriesOnly, bool $withVersion = true): array
    {
        $dataTable = $this->dataTableRepository->findOneBy(['name' => $dataTableName]);
        if (!$dataTable || $recordId <= 0) {
            return [];
        }

        $dataTableId = (int) $dataTable->getId();
        $currentUser = $this->userContextService->getCurrentUser();
        $userId = $currentUser ? (int) $currentUser->getId() : null;

        if ($userId === null) {
            return []; // No user, no record prefill
        }

        // Table-bound lookup (F127): a record of another table never prefills here.
        $recordOwnerId = $this->getRecordOwnerId($dataTableId, $recordId);
        $isOwnRecord = $recordOwnerId !== null && $recordOwnerId === $userId;
        if (!$isOwnRecord) {
            $hasUpdatePermission = $this->dataAccessSecurityService->hasPermission(
                $userId,
                'data_table',
                $dataTableId,
                DataAccessSecurityService::PERMISSION_UPDATE
            );
            // own_entries_only=true blocks foreign records outright here (the
            // query below is user-unscoped when own_entries_only=false only).
            if ($ownEntriesOnly || !$this->dataAccessSecurityService->canUpdateOwnedRecord(false, false, $hasUpdatePermission)) {
                return [];
            }
        }

        $cacheKey = "form_record_data_{$dataTableName}_{$userId}_r{$recordId}";

        $records = $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList($cacheKey, function () use ($dataTableId, $recordId) {
                return $this->getDataWithAllLanguages(
                    $dataTableId,
                    sprintf('AND record_id = %d', $recordId),
                    false,
                    null,
                    false,
                    true
                );
            });

        $records = $this->remapRecordKeysToInputNames($dataTableName, $records);

        return $withVersion ? $this->withRecordVersions($records) : $records;
    }

    /**
     * Fetch data records from a data table with the legacy filter-fragment behavior.
     * Mirrors the old get_data($dataTableId, $filter, $own_entries_only, $user_id, $db_first, $exclude_deleted) logic;
     * the fragment is translated to the structured filter of the data query engine and never pasted into SQL.
     *
     * - If the filter contains dynamic placeholders ("{{"), it will be ignored
     * - When ownEntriesOnly is true and userId is not provided, current user is used (or -1 if not available)
     * - When ownEntriesOnly is false and userId is not provided, -1 is used to fetch all users
     * - When dbFirst is true, the first row (or empty array) is returned
     *
     * @return array<array-key, mixed>
     */
    public function getData(
        int $dataTableId,
        string $filter = '',
        bool $ownEntriesOnly = true,
        ?int $userId = null,
        bool $dbFirst = false,
        bool $excludeDeleted = true,
        int $languageId = 1,
        string $selectedColumns = '',
    ): array {
        try {
            $rawFilter = $filter;
            // Guard: reject malformed or unsafe dynamic filter attempts (return no rows).
            if (str_contains($rawFilter, '{{')) {
                return $dbFirst ? [] : [];
            }

            if ($rawFilter !== '' && !$this->dataTableFilterService->isSafeFilterFragment($rawFilter)) {
                return $dbFirst ? [] : [];
            }

            $filter = $this->dataTableFilterService->guardForStoredProcedure($rawFilter);
            if ($rawFilter !== '' && $filter === '') {
                return $dbFirst ? [] : [];
            }

            $selectedColumns = $this->dataTableFilterService->sanitizeSelectedColumns($selectedColumns);

            // Resolve whose records are read. A visitor who is not signed in has no
            // records of their own, so an own-entries read returns nothing (the stored
            // procedures read every record for them).
            $resolvedUserId = $userId;
            if ($resolvedUserId === null) {
                if ($ownEntriesOnly) {
                    $currentUser = $this->userContextService->getCurrentUser();
                    if ($currentUser === null) {
                        return [];
                    }
                    $resolvedUserId = (int) $currentUser->getId();
                } else {
                    $resolvedUserId = -1; // all users
                }
            }

            $rows = $this->dataTableRepository->getDataTableWithFilter(
                $dataTableId,
                $resolvedUserId,
                $filter,
                $excludeDeleted,
                $languageId,
                $this->cmsPreferenceService->getDefaultTimezoneCode(),
                $selectedColumns,
            );

            if ($dbFirst) {
                return isset($rows[0]) ? $rows[0] : [];
            }

            return $rows;
        } catch (DataQueryException $e) {
            // A stored filter that has no structured form reads nothing, like an unsafe or unresolved one.
            $this->logger->warning('Data read skipped: ' . $e->getMessage(), ['dataTableId' => $dataTableId]);

            return [];
        } catch (\Throwable $e) {
            throw new ServiceException(
                'Failed to fetch data: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'dataTableId' => $dataTableId]
            );
        }
    }

    /**
     * Fetch data records from a data table with user group filtering.
     * Used for non-admin users who should only see data from users in their accessible groups.
     * The accessible users are determined server-side based on current user's permissions.
     *
     * @param int $dataTableId Data table ID
     * @param int $currentUserId Current user ID making the request
     * @param string $filter Filter string
     * @param bool $excludeDeleted Whether to exclude deleted records
     * @param int $languageId Language ID for translations
     * @return list<array<string, mixed>>
     */
    public function getDataWithUserGroupFilter(
        int $dataTableId,
        int $currentUserId,
        string $filter = '',
        bool $excludeDeleted = true,
        int $languageId = 1
    ): array {
        try {
            $rawFilter = $filter;
            // Guard: reject malformed or unsafe dynamic filter attempts (return no rows).
            if (str_contains($rawFilter, '{{')) {
                return [];
            }

            if ($rawFilter !== '' && !$this->dataTableFilterService->isSafeFilterFragment($rawFilter)) {
                return [];
            }

            $filter = $this->dataTableFilterService->guardForStoredProcedure($rawFilter);
            if ($rawFilter !== '' && $filter === '') {
                return [];
            }

            $rows = $this->dataTableRepository->getDataTableWithUserGroupFilter(
                $dataTableId,
                $currentUserId,
                $filter,
                $excludeDeleted,
                $languageId,
                $this->cmsPreferenceService->getDefaultTimezoneCode()
            );

            return $rows;
        } catch (DataQueryException $e) {
            // A stored filter that has no structured form reads nothing, like an unsafe or unresolved one.
            $this->logger->warning('Data read skipped: ' . $e->getMessage(), ['dataTableId' => $dataTableId]);

            return [];
        } catch (\Throwable $e) {
            throw new ServiceException(
                'Failed to fetch data with user group filter: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'dataTableId' => $dataTableId]
            );
        }
    }

    /**
     * The records {@see getData()} returns for an unfiltered read of a table, in
     * stable batches: exports and background work read a table of any size
     * without collecting it in memory. Records, order and shape are those of
     * the single read; the caller decides which users' records it may read.
     *
     * @param int|null $userId only this user's records; null (or below one) reads every user's
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function getDataInBatches(
        int $dataTableId,
        ?int $userId = null,
        bool $excludeDeleted = true,
        int $languageId = 1,
        int $batchSize = 500,
    ): \Generator {
        try {
            yield from $this->dataTableRepository->batchesWithFilter(
                $dataTableId,
                $userId ?? -1,
                '',
                $excludeDeleted,
                $languageId,
                $this->cmsPreferenceService->getDefaultTimezoneCode(),
                '',
                $batchSize,
            );
        } catch (DataQueryException $e) {
            $this->logger->warning('Data read skipped: ' . $e->getMessage(), ['dataTableId' => $dataTableId]);
        } catch (\Throwable $e) {
            throw new ServiceException(
                'Failed to fetch data: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'dataTableId' => $dataTableId]
            );
        }
    }

    /**
     * The rows {@see getDataWithAllLanguages()} returns for an unfiltered read of a
     * table: one row per record and language, in stable batches. Exports read a
     * table of any size with its translated cells, without collecting it in memory.
     *
     * @param int|null $userId only this user's records; null (or below one) reads every user's
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function getDataInBatchesWithAllLanguages(
        int $dataTableId,
        ?int $userId = null,
        bool $excludeDeleted = true,
        int $batchSize = 500,
    ): \Generator {
        try {
            yield from $this->dataTableRepository->batchesWithAllLanguages(
                $dataTableId,
                $userId ?? -1,
                '',
                $excludeDeleted,
                $this->cmsPreferenceService->getDefaultTimezoneCode(),
                $batchSize,
            );
        } catch (DataQueryException $e) {
            $this->logger->warning('Data read skipped: ' . $e->getMessage(), ['dataTableId' => $dataTableId]);
        } catch (\Throwable $e) {
            throw new ServiceException(
                'Failed to fetch data with all languages: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'dataTableId' => $dataTableId]
            );
        }
    }

    /**
     * The records {@see getDataWithUserGroupFilter()} returns, in stable batches.
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function getDataInBatchesWithUserGroupFilter(
        int $dataTableId,
        int $currentUserId,
        bool $excludeDeleted = true,
        int $languageId = 1,
        int $batchSize = 500,
    ): \Generator {
        try {
            yield from $this->dataTableRepository->batchesWithUserGroupFilter(
                $dataTableId,
                $currentUserId,
                '',
                $excludeDeleted,
                $languageId,
                $this->cmsPreferenceService->getDefaultTimezoneCode(),
                $batchSize,
            );
        } catch (DataQueryException $e) {
            $this->logger->warning('Data read skipped: ' . $e->getMessage(), ['dataTableId' => $dataTableId]);
        } catch (\Throwable $e) {
            throw new ServiceException(
                'Failed to fetch data with user group filter: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'dataTableId' => $dataTableId]
            );
        }
    }

    /**
     * Fetch data records from a data table with all languages returned.
     * This method returns all language versions for each record.
     *
     * @param int $dataTableId Data table ID
     * @param string $filter Filter string
     * @param bool $ownEntriesOnly Whether to restrict to user's own entries
     * @param int|null $userId User ID for filtering
     * @param bool $dbFirst Return only first record
     * @param bool $excludeDeleted Whether to exclude deleted records
     * @return array<array-key, mixed>
     */
    public function getDataWithAllLanguages(
        int $dataTableId,
        string $filter = '',
        bool $ownEntriesOnly = true,
        ?int $userId = null,
        bool $dbFirst = false,
        bool $excludeDeleted = true
    ): array {
        try {
            $rawFilter = $filter;
            // Guard: reject malformed or unsafe dynamic filter attempts (return no rows).
            if (str_contains($rawFilter, '{{')) {
                return $dbFirst ? [] : [];
            }

            if ($rawFilter !== '' && !$this->dataTableFilterService->isSafeFilterFragment($rawFilter)) {
                return $dbFirst ? [] : [];
            }

            $filter = $this->dataTableFilterService->guardForStoredProcedure($rawFilter);
            if ($rawFilter !== '' && $filter === '') {
                return $dbFirst ? [] : [];
            }

            // Resolve whose records are read. A visitor who is not signed in has no
            // records of their own, so an own-entries read returns nothing (the stored
            // procedures read every record for them).
            $resolvedUserId = $userId;
            if ($resolvedUserId === null) {
                if ($ownEntriesOnly) {
                    $currentUser = $this->userContextService->getCurrentUser();
                    if ($currentUser === null) {
                        return [];
                    }
                    $resolvedUserId = (int) $currentUser->getId();
                } else {
                    $resolvedUserId = -1; // all users
                }
            }

            $rows = $this->dataTableRepository->getDataTableWithAllLanguages(
                $dataTableId,
                $resolvedUserId,
                $filter,
                $excludeDeleted,
                $this->cmsPreferenceService->getDefaultTimezoneCode()
            );

            if ($dbFirst) {
                return isset($rows[0]) ? $rows[0] : [];
            }

            return $rows;
        } catch (DataQueryException $e) {
            // A stored filter that has no structured form reads nothing, like an unsafe or unresolved one.
            $this->logger->warning('Data read skipped: ' . $e->getMessage(), ['dataTableId' => $dataTableId]);

            return [];
        } catch (\Throwable $e) {
            throw new ServiceException(
                'Failed to fetch data with all languages: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'dataTableId' => $dataTableId]
            );
        }
    }

    /**
     * Invalidate cache selectively based on data table current_user configurations
     *
     * @param DataTable $dataTable The data table entity
     * @param int|null $userId The user ID (null for guest users)
     */
    private function invalidateDataTableCache(DataTable $dataTable, ?int $userId): void
    {
        $config = $this->getDataTableCurrentUserConfig((int) $dataTable->getId());

        // Always invalidate data table lists (this affects data table metadata)
        $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->invalidateAllListsInCategory();

        // Always invalidate the data-table entity scope. Every read in
        // DataTableRepository scopes its cache by the data_table id, so
        // bumping that scope's generation counter is the only reliable
        // way to invalidate plugin-owned data tables (no section
        // references them via data_config, which would otherwise drive
        // the has_global_config / has_current_user_config flags below).
        $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->invalidateEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, (int) $dataTable->getId());

        // If the data table has user-specific configs (current_user: true) and we have a user,
        // invalidate the user-specific scope for this user
        if ($config['has_current_user_config'] && $userId) {
            $this->cache
                ->withCategory(CacheService::CATEGORY_DATA_TABLES)
                ->invalidateEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
        }

        // Additionally invalidate form-record cache for this data table and user
        $this->invalidateFormRecordCache($dataTable, $userId);
    }

    /**
     * Invalidate form-record cache for a specific data table and user
     *
     * @param DataTable $dataTable The data table entity
     * @param int|null $userId The user ID
     */
    private function invalidateFormRecordCache(DataTable $dataTable, ?int $userId): void
    {
        if (!$userId) {
            return; // No user, no form record cache to invalidate
        }

        // Invalidate the specific form-record cache entry
        $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, (int) $dataTable->getId())
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->invalidateItem("form_record_data_{$dataTable->getName()}_{$userId}");
    }


    /**
     * Check if a data table has current_user configurations
     *
     * @param int $dataTableId The data table ID to check
     * @return array{has_current_user_config: bool, has_global_config: bool} Array with 'has_current_user_config' and 'has_global_config' boolean flags
     */
    public function getDataTableCurrentUserConfig(int $dataTableId): array
    {
        $cacheKey = "data_table_current_user_config_{$dataTableId}";

        return $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $dataTableId)
            ->getList($cacheKey, function () use ($dataTableId) {
                $hasCurrentUserConfig = false;
                $hasGlobalConfig = false;

                // Find all sections that reference this data table
                // We need to search through all sections to find data_config that references this table
                $allSections = $this->sectionRepository->findAll();

                foreach ($allSections as $section) {
                    $dataConfig = $section->getDataConfig();
                    if (!$dataConfig) {
                        continue;
                    }

                    // Parse data_config as JSON string to array
                    $dataConfigArray = json_decode($dataConfig, true);

                    if (is_array($dataConfigArray)) {
                        foreach ($dataConfigArray as $config) {
                            if (is_array($config) && isset($config['table'])) {
                                $tableName = $this->asString($config['table']);

                                // Get data table by name to compare with our target
                                try {
                                    $dataTable = $this->getDataTableByName($tableName);
                                    if ($dataTable && $dataTable->getId() === $dataTableId) {
                                        $currentUser = $config['current_user'] ?? true; // Default to true

                                        if ($currentUser) {
                                            $hasCurrentUserConfig = true;
                                        } else {
                                            $hasGlobalConfig = true;
                                        }

                                        // If we found both types, we can stop early
                                        if ($hasCurrentUserConfig && $hasGlobalConfig) {
                                            break 2;
                                        }
                                    }
                                } catch (\Exception $e) {
                                    // Continue if there's an error getting the data table
                                }
                            }
                        }
                    }
                }

                return [
                    'has_current_user_config' => $hasCurrentUserConfig,
                    'has_global_config' => $hasGlobalConfig
                ];
            });
    }


}
