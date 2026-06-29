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
use App\Service\CMS\FormFileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Core service for handling form data operations with transactions and validation
 * ENTITY RULE - Uses association objects instead of primitive foreign keys
 */
class DataService extends BaseService
{


    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionService $transactionService,
        private readonly DataTableRepository $dataTableRepository,
        private readonly LookupService $lookupService,
        private readonly UserContextService $userContextService,
        private readonly UserContextAwareService $userContextAwareService,
        private readonly CacheService $cache,
        private readonly SectionRepository $sectionRepository,
        private readonly FormFileUploadService $formFileUploadService,
        private readonly CmsPreferenceService $cmsPreferenceService,
        private readonly ActionContextBuilderService $actionContextBuilderService,
        private readonly ActionOrchestratorService $actionOrchestratorService,
        private readonly DataColumnService $dataColumnService,
        private readonly FormFieldKeyResolver $formFieldKeyResolver,
        private readonly LoggerInterface $logger
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
        ?array $fieldLabels = null
    ): int|false {
        // Core CMS forms submit values keyed by the human input *name*. Remap
        // those to the immutable storage key (`section_<input section id>`) so a
        // later rename only updates the column's display_name instead of forking
        // a new column (issue #56). The human name travels along as the auto
        // display label. Non-form tables (SurveyJS `sh2_surveyjs_*`,
        // `user_validation_inputs`) are left untouched and keep their own keys.
        [$data, $derivedLabels] = $this->mapFormFieldKeys($tableName, $data);
        if ($derivedLabels !== []) {
            // An explicit caller-supplied label wins over the auto-derived one.
            $fieldLabels = array_merge($derivedLabels, $fieldLabels ?? []);
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
            if (!isset($data['id_users'])) {
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
                // Special handling for record_id - look up directly by DataRow ID
                if (isset($updateBasedOn['record_id'])) {
                    $recordId = $this->asInt($updateBasedOn['record_id']);
                    $dataRow = $this->entityManager->getRepository(DataRow::class)->find($recordId);

                    if ($dataRow) {
                        // Check ownership if required
                        if ($ownEntriesOnly) {
                            if (!$currentUser || $dataRow->getIdUsers() !== $currentUser->getId()) {
                                $this->entityManager->rollback();
                                throw new ServiceException('Access denied to this record', Response::HTTP_FORBIDDEN);
                            }
                        }

                        // Update the existing record
                        $updatedRecordId = $this->updateExistingRecord($recordId, $data, $transactionBy, $fieldLabels);
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
                        // Record not found
                        $this->entityManager->rollback();
                        return false;
                    }
                } else {
                    // Handle other types of update filters using the stored procedure
                    $filter = '';
                    foreach ($updateBasedOn as $key => $value) {
                        $filter = $filter . ' AND ' . $key . ' = "' . $this->asString($value) . '"';
                    }

                    $existingRecord = $this->getData((int) $dataTable->getId(), $filter, $ownEntriesOnly, $currentUser?->getId(), true);

                    if ($existingRecord) {
                        $recordId = $this->updateExistingRecord($this->asInt($existingRecord['record_id']), $data, $transactionBy, $fieldLabels);
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
                        $this->entityManager->rollback();
                        return false;
                    }
                }
            }

            // Create new record
            $recordId = $this->createNewRecord($dataTable, $data, $transactionBy, $fieldLabels);

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

        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw new ServiceException(
                'Failed to save form data: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'tableName' => $tableName]
            );
        }
    }

    public function getRecordOwnerId(int $recordId): ?int
    {
        $dataRow = $this->entityManager->getRepository(DataRow::class)->find($recordId);
        return $dataRow ? $dataRow->getIdUsers() : null;
    }

    /**
     * Delete form data record
     *
     * @param int $recordId The ID of the record to delete
     * @param bool $ownEntriesOnly Whether to restrict to user's own entries
     * @return bool Success status
     * @throws ServiceException
     *
     * Queued action jobs linked to the deleted record are cleaned up after commit
     * through the same action orchestration pipeline used for save/update events.
     */
    public function deleteData(int $recordId, bool $ownEntriesOnly = true): bool
    {
        $this->entityManager->beginTransaction();
        $currentUser = $this->userContextService->getCurrentUser();

        try {
            $dataRow = $this->entityManager->getRepository(DataRow::class)->find($recordId);
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

            // Extract file data before marking as deleted
            $fileData = $this->extractFileDataFromRecord($dataRow);

            // Mark as deleted instead of physical deletion
            $deletedTriggerType = $this->lookupService->getLookupIdByValue('actionTriggerTypes', LookupService::ACTION_TRIGGER_TYPES_DELETED);
            $dataRow->setIdActionTriggerTypes($deletedTriggerType);
            $deletedValues = $this->extractRecordValues($dataRow);

            // Log transaction
            $this->transactionService->logTransaction(
                'delete',
                LookupService::TRANSACTION_BY_BY_USER,
                'data_tables',
                $dataTable->getId()
            );

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Clean up associated files after successful database deletion
            if (!empty($fileData)) {
                try {
                    $this->formFileUploadService->deleteFiles($fileData);
                } catch (\Exception $e) {
                    // Log file cleanup error but don't fail the deletion
                    $this->logger->warning(
                        "File cleanup failed for record {$recordId}: " . $e->getMessage(),
                        ['exception' => $e]
                    );
                }
            }

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

        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw new ServiceException(
                'Failed to delete record: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
                ['previous' => $e, 'recordId' => $recordId]
            );
        }
    }

    /**
     * Extract file data from a data record
     *
     * @param DataRow $dataRow The data row to extract files from
     * @return array<string, mixed> Array of file paths keyed by field name
     */
    private function extractFileDataFromRecord(DataRow $dataRow): array
    {
        $fileData = [];

        // Get all data cells for this row (across all languages)
        $dataCells = $this->entityManager->getRepository(DataCell::class)->findBy([
            'dataRow' => $dataRow
        ]);

        foreach ($dataCells as $dataCell) {
            $fieldName = $this->asString($dataCell->getDataCol()?->getFieldKey());
            $fieldValue = $dataCell->getValue();

            // Check if this field contains file information
            if ($this->formFileUploadService->isFileInputField($fieldName, (int) $dataRow->getDataTable()?->getId())) {
                try {
                    // Try to decode as JSON (for multiple files)
                    $decoded = json_decode((string) $fieldValue, true);
                    if (is_array($decoded)) {
                        // Multiple files
                        $fileData[$fieldName] = array_filter($decoded, function ($path) {
                            return is_string($path) && str_contains($path, 'uploads/form-files/');
                        });
                    } elseif (is_string($fieldValue) && str_contains($fieldValue, 'uploads/form-files/')) {
                        // Single file
                        $fileData[$fieldName] = $fieldValue;
                    }
                } catch (\Exception $e) {
                    // If JSON parsing fails, check if it's a direct file path
                    if (is_string($fieldValue) && str_contains($fieldValue, 'uploads/form-files/')) {
                        $fileData[$fieldName] = $fieldValue;
                    }
                }
            }
        }

        return $fileData;
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

        return $dataTable;
    }

    /**
     * Update existing record
     * 
     * @param int $recordId The ID of the record to update
     * @param array<string, mixed> $data New data
     * @param string $transactionBy Transaction initiator
     * @param array<string, string|null>|null $fieldLabels Optional field_key => label map
     * @return int Record ID
     */
    private function updateExistingRecord(int $recordId, array $data, string $transactionBy, ?array $fieldLabels = null): int
    {

        $dataRow = $this->entityManager->getRepository(DataRow::class)->find($recordId);
        if (!$dataRow) {
            $this->throwNotFound('Record not found');
        }

        $dataTable = $dataRow->getDataTable();
        if (!$dataTable) {
            $this->throwNotFound('Data table not found for record');
        }

        // Update timestamp and trigger type (use UTC for consistency)
        $dataRow->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $dataRow->setIdUsers($this->asIntOrNull($data['id_users'] ?? null));

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
        $this->transactionService->logTransaction(
            'update',
            $transactionBy,
            'data_tables',
            $dataTable->getId()
        );

        $this->entityManager->flush();

        return (int) $dataRow->getId();
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
    private function createNewRecord(DataTable $dataTable, array $data, string $transactionBy, ?array $fieldLabels = null): int
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
        $this->transactionService->logTransaction(
            'insert',
            $transactionBy,
            'data_tables',
            $dataTable->getId()
        );

        $this->entityManager->flush();

        return (int) $dataRow->getId();
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
                $mapped[$keyToName[$key] ?? $key] = $value;
            }
            $out[$index] = $mapped;
        }

        return $out;
    }

    /**
     * Remap showUserInput entries (keyed by `field_key`) back to the current
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
        // Applied after the cache read so a rename reflects immediately.
        return $this->remapRecordKeysToInputNames($dataTableName, $records);
    }

    /**
     * Fetch data records from a data table using the legacy stored procedure behavior.
     * Mirrors the old get_data($dataTableId, $filter, $own_entries_only, $user_id, $db_first, $exclude_deleted) logic.
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
        int $languageId = 1
    ): array {
        try {
            // Guard: ignore malformed dynamic filter attempts
            if (str_contains($filter, '{{')) {
                $filter = '';
            }

            // Resolve user id as per legacy rules
            $resolvedUserId = $userId;
            if ($resolvedUserId === null) {
                if ($ownEntriesOnly) {
                    $currentUser = $this->userContextService->getCurrentUser();
                    $resolvedUserId = $currentUser ? (int) $currentUser->getId() : -1;
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
                $this->cmsPreferenceService->getDefaultTimezoneCode()
            );

            if ($dbFirst) {
                return isset($rows[0]) ? $rows[0] : [];
            }

            return $rows;
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
            // Guard: ignore malformed dynamic filter attempts
            if (str_contains($filter, '{{')) {
                $filter = '';
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
            // Guard: ignore malformed dynamic filter attempts
            if (str_contains($filter, '{{')) {
                $filter = '';
            }

            // Resolve user id as per legacy rules
            $resolvedUserId = $userId;
            if ($resolvedUserId === null) {
                if ($ownEntriesOnly) {
                    $currentUser = $this->userContextService->getCurrentUser();
                    $resolvedUserId = $currentUser ? (int) $currentUser->getId() : -1;
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
