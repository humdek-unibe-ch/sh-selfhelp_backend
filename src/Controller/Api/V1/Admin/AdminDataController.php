<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Controller\Api\V1\Admin;

use App\Entity\Language;
use App\Controller\Api\V1\Frontend\FormFileController;
use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\RequestValidationException;
use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\AdminDataHistoryService;
use App\Service\CMS\Admin\DataQueryPreviewService;
use App\Service\CMS\Admin\DataTableExportService;
use App\Service\CMS\DataRevision\RecordContentRefReader;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\CMS\FormFileUploadService;
use App\Service\CMS\Frontend\OptionLabelHydrator;
use App\Service\Core\LookupService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\TransactionService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\Security\DataAccessSecurityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AdminDataController extends AbstractController
{
    use RequestValidatorTrait;

    private const XLSX_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly DataService $dataService,
        private readonly DataTableService $dataTableService,
        private readonly DataQueryPreviewService $dataQueryPreviewService,
        private readonly AdminDataHistoryService $recordHistory,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        private readonly UserContextService $userContextService,
        private readonly TransactionService $transactionService,
        private readonly OptionLabelHydrator $optionLabelHydrator,
        private readonly FormFileUploadService $formFileUploadService,
        private readonly DataTableExportService $exports,
        private readonly RecordContentRefReader $contentRefs,
    ) {
    }

    /**
     * Preview how an entry-style filter will be read by the data query engine
     * (structured filter, sort and page window). Nothing is executed.
     */
    public function queryPreview(Request $request): JsonResponse
    {
        try {
            $payload = $this->validateRequest($request, 'requests/admin/data_query_preview', $this->jsonSchemaValidationService);

            $preview = $this->prepareQueryPreviewResponse($this->dataQueryPreviewService->preview($this->toAssocArray($payload)));
            $this->jsonSchemaValidationService->validate($preview, 'responses/admin/data_query_preview');

            return $this->responseFormatter->formatSuccess($preview, null, Response::HTTP_OK, false, true);
        } catch (RequestValidationException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get all data tables
     * Filtered by table access permissions with caching
     */
    public function getDataTables(): JsonResponse
    {
        try {
            $userId = $this->userContextService->getCurrentUser()?->getId();

            if ($userId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Use service-level filtering with caching
            $dataTables = $this->dataTableService->getFilteredDataTables($userId);

            // Format the timestamp for each dataTable to match expected format
            $result = array_map(function (array $table): array {
                $created = $table['created'] ?? null;
                return [
                    'id' => $table['id'] ?? null,
                    'name' => $table['name'] ?? null,
                    'displayName' => $table['displayName'] ?? null,
                    // Provenance lock so the Data browser can flag admin-renamed
                    // tables (issue #56).
                    'locked' => (bool) ($table['locked'] ?? false),
                    'created' => $created instanceof \DateTimeInterface ? $created->format(DATE_ATOM) : $created,
                    'crud' => $table['crud'] ?? null
                ];
            }, $dataTables);

            return $this->responseFormatter->formatSuccess(['dataTables' => $result]);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get data rows from a data table
     * Filtered by table access permissions
     * Supported query params: table_name (string, required), user_id (int|null), exclude_deleted (bool, default true), language_id (int, default 1)
     * Other legacy parameters are fixed: filter = '', own_entries_only = false, db_first = false
     */
    public function getData(Request $request): JsonResponse
    {
        try {
            $tableName = (string) $request->query->get('table_name', '');
            if ($tableName === '') {
                return $this->responseFormatter->formatError('Missing or invalid table_name', Response::HTTP_BAD_REQUEST);
            }

            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            // Check if user has permission to access this data table
            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_READ)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $userId = $request->query->has('user_id') ? (int) $request->query->get('user_id') : null;
            $excludeDeleted = filter_var($request->query->get('exclude_deleted', 'true'), FILTER_VALIDATE_BOOLEAN);
            $languageId = $request->query->has('language_id') ? (int) $request->query->get('language_id') : Language::INDEPENDENT_ID;

            $hasFullTableAccess = $this->dataTableService->canAccessDataTable(
                $currentUserId,
                (int) $dataTable->getId(),
                DataAccessSecurityService::PERMISSION_DELETE
            );

            if ($hasFullTableAccess) {
                $rows = $this->dataService->getData((int) $dataTable->getId(), '', false, $userId, false, $excludeDeleted, $languageId);
            } else {
                // Non-admin users: use group-based filtering where permissions are calculated server-side
                $rows = $this->dataService->getDataWithUserGroupFilter(
                    (int) $dataTable->getId(),
                    $currentUserId,
                    '', // filter
                    $excludeDeleted,
                    $languageId
                );

                // Log the filtering for audit purposes
                $this->transactionService->logTransaction(
                    LookupService::TRANSACTION_TYPES_SELECT,
                    LookupService::TRANSACTION_BY_BY_USER,
                    'data_tables',
                    $dataTable->getId(),
                    false,

                    'Group-based filtering for data table ' . $dataTable->getName() . ' by user ' . $currentUserId
                );
            }

            $hydratedRows = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $hydratedRows[] = $this->toAssocArray($row);
                }
            }
            // Each record shows the content it was written for (`content_ref`, contract C19); null for a record that predates it.
            $hydratedRows = $this->contentRefs->attach($hydratedRows, 'content_ref', true);
            $rows = $this->optionLabelHydrator->hydrate(
                $hydratedRows,
                (string) $dataTable->getName(),
                $languageId
            );

            return $this->responseFormatter->formatSuccess([
                'rows' => $rows,
                'optionLabelMaps' => $this->optionLabelHydrator->resolveFieldLabelMaps(
                    (string) $dataTable->getName(),
                    $languageId
                ),
            ]);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                'Failed to fetch data',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete a single record from any data table (soft-delete via trigger type)
     * Checks if user has DELETE permission for the data table
     */
    public function deleteRecord(Request $request, int $recordId): JsonResponse
    {
        try {
            $tableName = $request->query->get('table_name');
            if (!$tableName) {
                return $this->responseFormatter->formatError('table_name parameter is required', Response::HTTP_BAD_REQUEST);
            }

            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            // Check if user has DELETE permission for this data table
            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_DELETE)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $ownEntriesOnly = filter_var($request->query->get('own_entries_only', 'true'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $ownEntriesOnly = $ownEntriesOnly === null ? true : $ownEntriesOnly;

            // F127: the record must belong to the table the caller was authorized for.
            $success = $this->dataService->deleteData(
                (int) $dataTable->getId(),
                $recordId,
                $ownEntriesOnly,
                new RecordWriteContext(LookupService::DATA_REVISION_SOURCES_ADMIN)
            );
            return $this->responseFormatter->formatSuccess(['deleted' => $success]);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                'Failed to delete record',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete an entire data table and all associated rows/columns/cells
     * Checks if user has DELETE permission for the data table
     */
    public function deleteDataTable(string $tableName): JsonResponse
    {
        try {
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            // Check if user has DELETE permission for this data table
            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_DELETE)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $deleted = $this->dataTableService->deleteDataTable($tableName);
            if (!$deleted) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }
            return $this->responseFormatter->formatSuccess(['deleted' => true]);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                'Failed to delete data table',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete selected columns from a data table
     * Expects JSON body: { "columns": ["colA", "colB", ...] }
     * Filtered by data table access permissions
     */
    public function deleteColumns(Request $request, string $tableName): JsonResponse
    {
        try {
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            // Check if user has permission to update this data table (since we're modifying structure)
            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_UPDATE)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $data = $this->validateRequest($request, 'requests/admin/delete_data_columns', $this->jsonSchemaValidationService);
            $columns = $this->toStringList($data['columns'] ?? null);

            $result = $this->dataTableService->deleteColumns($tableName, $columns);
            if ($result === false) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }
            return $this->responseFormatter->formatSuccess(['deleted_column_count' => $result]);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                'Failed to delete columns',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get columns for a given data table
     * Filtered by data table access permissions
     */
    public function getColumns(string $tableName): JsonResponse
    {
        try {
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            // Check if user has permission to read this data table
            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_READ)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $columns = $this->dataTableService->getColumns($tableName);
            if ($columns === false) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }
            return $this->responseFormatter->formatSuccess(['columns' => $columns], 'responses/admin/data_table_columns');
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                'Failed to fetch columns',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Curate a column's human-facing display name (addressed by immutable field key).
     * The storage key never changes; this only updates the mutable label and marks
     * it as manually curated so future submissions never overwrite it.
     * Expects JSON body: { "fieldKey": "mood_score", "displayName": "Daily mood" }
     * Requires UPDATE permission on the data table.
     */
    public function updateColumnDisplayName(Request $request, string $tableName): JsonResponse
    {
        try {
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            // Curating a label changes table structure metadata -> require UPDATE.
            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_UPDATE)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $data = $this->validateRequest($request, 'requests/admin/update_data_column_display_name', $this->jsonSchemaValidationService);

            $fieldKey = is_string($data['fieldKey'] ?? null) ? $data['fieldKey'] : '';
            $displayNameRaw = $data['displayName'] ?? null;
            $displayName = is_string($displayNameRaw) ? $displayNameRaw : null;

            $updated = $this->dataTableService->updateColumnDisplayName($tableName, $fieldKey, $displayName);
            if ($updated === false) {
                return $this->responseFormatter->formatError('Data table or column not found', Response::HTTP_NOT_FOUND);
            }

            return $this->responseFormatter->formatSuccess(['updated' => true]);
        } catch (RequestValidationException $e) {
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                'Failed to update column display name',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Curate a data table's human-facing display name from the Data browser.
     * Sets the label and marks it manually locked so the form section's
     * `displayName` field never overwrites it on save; an empty/null value resets
     * it to the auto label derived from the form section (issue #56).
     * Expects JSON body: { "displayName": "Daily mood" | null }
     * Requires UPDATE permission on the data table.
     */
    public function updateDataTableDisplayName(Request $request, string $tableName): JsonResponse
    {
        try {
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            // Renaming the table changes its metadata -> require UPDATE.
            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_UPDATE)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $data = $this->validateRequest($request, 'requests/admin/update_data_table_display_name', $this->jsonSchemaValidationService);

            $displayNameRaw = $data['displayName'] ?? null;
            $displayName = is_string($displayNameRaw) ? $displayNameRaw : null;

            $updated = $this->dataTableService->setDataTableDisplayNameCurated($tableName, $displayName);
            if ($updated === false) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            return $this->responseFormatter->formatSuccess(['updated' => true]);
        } catch (RequestValidationException $e) {
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                'Failed to update data table display name',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Export a single data table as CSV, JSON or Excel (raw, no envelope).
     * Query params: format=csv|json|xlsx, user_id (optional), language_id (optional, default 1), exclude_deleted (optional, default true)
     */
    public function exportTable(Request $request, string $tableName): Response
    {
        try {
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_READ)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $format = strtolower((string) $request->query->get('format', 'csv'));
            if (!in_array($format, ['csv', 'json', 'xlsx'], true)) {
                return $this->responseFormatter->formatError('format must be csv, json or xlsx', Response::HTTP_BAD_REQUEST);
            }

            $userId = $request->query->has('user_id') ? (int) $request->query->get('user_id') : null;
            $languageId = $request->query->has('language_id') ? (int) $request->query->get('language_id') : Language::INDEPENDENT_ID;
            $excludeDeleted = filter_var($request->query->get('exclude_deleted', 'true'), FILTER_VALIDATE_BOOLEAN);

            $hasFullAccess = $this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_DELETE);
            // The rows are read in batches and written as they arrive; the column
            // header is the current human input name, not the immutable
            // section_<id> key (issue #56).
            $batches = $this->exports->batches($dataTable, $currentUserId, $hasFullAccess, $userId, $excludeDeleted, $languageId);

            $label = $dataTable->getDisplayName() ?? $dataTable->getName() ?? $tableName;
            $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $label) . '.' . $format;

            if ($format === 'xlsx') {
                return new Response(
                    $this->exports->xlsx($batches),
                    Response::HTTP_OK,
                    [
                        'Content-Type' => self::XLSX_CONTENT_TYPE,
                        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    ]
                );
            }

            if ($format === 'json') {
                return new Response(
                    $this->exports->json($batches),
                    Response::HTTP_OK,
                    [
                        'Content-Type' => 'application/json',
                        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    ]
                );
            }

            // CSV
            return new Response(
                $this->exports->csv($batches),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]
            );
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError('Export failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download the uploaded files of the given records of a form data table as
     * one zip (`record_<id>/<field>/<file>`). Query: record_ids=1,2,3 — the rows
     * the admin currently sees. Route permission admin.data.read + table READ.
     */
    public function downloadTableFiles(Request $request, string $tableName): Response
    {
        try {
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }
            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_READ)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $recordIds = array_values(array_filter(
                array_map('intval', explode(',', (string) $request->query->get('record_ids', ''))),
                static fn (int $id): bool => $id > 0
            ));
            $files = $this->formFileUploadService->findTableFiles($tableName, $recordIds);
            if ($files === []) {
                return $this->responseFormatter->formatError('No files found for these records', Response::HTTP_NOT_FOUND);
            }

            $zipPath = $this->formFileUploadService->buildZip(array_map(
                fn (array $file): array => [
                    'entry' => sprintf('record_%d/%s/%s', $file['recordId'], $file['field'], $this->formFileUploadService->originalName($file['absolutePath'])),
                    'absolutePath' => $file['absolutePath'],
                ],
                $files
            ));
            $label = $dataTable->getDisplayName() ?? $dataTable->getName() ?? $tableName;

            return FormFileController::zipResponse($zipPath, (preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $label) ?? 'table') . '_files.zip');
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError('Download failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Export multiple data tables as a ZIP archive containing one file per table.
     * Body: { table_names, format, user_id?, language_id?, exclude_deleted? }
     * Returns 403 if the user cannot read any of the requested tables.
     */
    public function exportTables(Request $request): Response
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            $data = $this->validateRequest($request, 'requests/admin/data_export_bulk', $this->jsonSchemaValidationService);

            $tableNames = $this->toStringList($data['table_names'] ?? []);
            $format = strtolower($this->asStringField($data, 'format', 'csv'));
            $userId = isset($data['user_id']) && is_int($data['user_id']) ? $data['user_id'] : null;
            $languageId = isset($data['language_id']) && is_int($data['language_id']) ? $data['language_id'] : Language::INDEPENDENT_ID;
            $excludeDeleted = $this->asBoolField($data, 'exclude_deleted', true);

            // Authorise all tables up-front; 403 on first unauthorised table
            $tables = [];
            foreach ($tableNames as $name) {
                $dataTable = $this->dataService->getDataTableByName($name);
                if (!$dataTable) {
                    return $this->responseFormatter->formatError("Data table not found: {$name}", Response::HTTP_NOT_FOUND);
                }
                if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_READ)) {
                    return $this->responseFormatter->formatError("Access denied for table: {$name}", Response::HTTP_FORBIDDEN);
                }
                $tables[] = $dataTable;
            }

            // Build ZIP in a temp file
            $tmpFile = tempnam(sys_get_temp_dir(), 'sh_export_');
            if ($tmpFile === false) {
                return $this->responseFormatter->formatError('Could not create temporary file', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
                unlink($tmpFile);
                return $this->responseFormatter->formatError('Could not create ZIP archive', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            foreach ($tables as $dataTable) {
                $hasFullAccess = $this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_DELETE);
                // Batched read; the column header is the current human input name (issue #56).
                $batches = $this->exports->batches($dataTable, $currentUserId, $hasFullAccess, $userId, $excludeDeleted, $languageId);

                $label = $dataTable->getDisplayName() ?? $dataTable->getName() ?? '';
                $entryName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $label) . '.' . $format;

                $content = match ($format) {
                    'json' => $this->exports->json($batches),
                    'xlsx' => $this->exports->xlsx($batches),
                    default => $this->exports->csv($batches),
                };

                $zip->addFromString($entryName, $content);
            }

            $zip->close();

            $zipContent = (string) file_get_contents($tmpFile);
            unlink($tmpFile);

            return new Response(
                $zipContent,
                Response::HTTP_OK,
                [
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="data_export.zip"',
                ]
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener turn schema-validation failures into a
            // 400 envelope instead of swallowing them as a generic 500 below.
            throw $e;
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError('Export failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get column names for a given data table
     * Filtered by data table access permissions
     */
    public function getColumnNames(string $tableName): JsonResponse
    {
        try {
            $dataTable = $this->dataService->getDataTableByName($tableName);
            if (!$dataTable) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }

            $currentUserId = $this->userContextService->getCurrentUser()?->getId();
            if ($currentUserId === null) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            // Check if user has permission to read this data table
            if (!$this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_READ)) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $columnNames = $this->dataTableService->getColumnsNames($tableName);
            if ($columnNames === false) {
                return $this->responseFormatter->formatError('Data table not found', Response::HTTP_NOT_FOUND);
            }
            return $this->responseFormatter->formatSuccess(['columnNames' => $columnNames]);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                'Failed to fetch column names',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function prepareQueryPreviewResponse(array $preview): array
    {
        foreach (['route_params', 'route_requirements'] as $key) {
            $map = is_array($preview[$key] ?? null) ? $preview[$key] : [];
            $preview[$key] = $map === [] ? new \stdClass() : $map;
        }

        return $preview;
    }

    // -- record history (contract 6) ---------------------------------------------------------

    public function recordHistory(Request $request, string $tableName, int $recordId): JsonResponse
    {
        try {
            $userId = $this->requireAdminUserId();
            $page = max(1, (int) $request->query->get('page', 1));
            $pageSize = max(1, (int) $request->query->get('page_size', 20));
            $data = $this->recordHistory->versions($tableName, $recordId, $userId, $page, $pageSize);

            return $this->responseFormatter->formatSuccess($data);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    public function recordVersion(Request $request, string $tableName, int $recordId, int $version): JsonResponse
    {
        try {
            $userId = $this->requireAdminUserId();
            $data = $this->recordHistory->version($tableName, $recordId, $userId, $version);

            return $this->responseFormatter->formatSuccess($data);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    public function recordCompare(Request $request, string $tableName, int $recordId): JsonResponse
    {
        try {
            $userId = $this->requireAdminUserId();
            $from = (int) $request->query->get('from', 0);
            $to = (int) $request->query->get('to', 0);
            if ($from < 1 || $to < 1) {
                return $this->responseFormatter->formatError('from and to versions are required', Response::HTTP_BAD_REQUEST);
            }
            $data = $this->recordHistory->compare($tableName, $recordId, $userId, $from, $to);

            return $this->responseFormatter->formatSuccess($data);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    public function recordRestore(Request $request, string $tableName, int $recordId): JsonResponse
    {
        try {
            $userId = $this->requireAdminUserId();
            $body = [];
            if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
                try {
                    $body = $request->toArray();
                } catch (\Throwable) {
                    $body = [];
                }
            } else {
                $body = $request->request->all();
            }
            $versionRaw = $body['version'] ?? null;
            $version = is_numeric($versionRaw) ? (int) $versionRaw : 0;
            if ($version < 1) {
                return $this->responseFormatter->formatError('version is required', Response::HTTP_BAD_REQUEST);
            }
            $dryRun = ($body['dry_run'] ?? false) === true;
            $expectedRaw = $body['expected_version'] ?? null;
            $expected = is_numeric($expectedRaw) ? (int) $expectedRaw : null;
            $fieldKeys = null;
            if (isset($body['field_keys']) && is_array($body['field_keys'])) {
                $fieldKeys = [];
                foreach ($body['field_keys'] as $key) {
                    if (is_string($key) || is_int($key)) {
                        $fieldKeys[] = (string) $key;
                    }
                }
            }
            $data = $this->recordHistory->restore($tableName, $recordId, $userId, $version, $fieldKeys, $expected, $dryRun);

            return $this->responseFormatter->formatSuccess($data);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    public function recordVersionFile(
        Request $request,
        string $tableName,
        int $recordId,
        int $version,
        string $fieldKey,
    ): Response {
        try {
            $userId = $this->requireAdminUserId();
            $file = $this->recordHistory->file(
                $tableName,
                $recordId,
                $userId,
                $version,
                $fieldKey,
                max(Language::INDEPENDENT_ID, (int) $request->query->get('file_language', Language::INDEPENDENT_ID)),
                max(0, (int) $request->query->get('index', 0)),
            );
            $response = new BinaryFileResponse($file['absolutePath']);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['name']);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");

            return $response;
        } catch (ServiceException $e) {
            return new Response($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : Response::HTTP_NOT_FOUND);
        }
    }

    private function requireAdminUserId(): int
    {
        $user = $this->userContextService->getCurrentUser();
        if ($user === null) {
            throw new ServiceException('Authentication required', Response::HTTP_UNAUTHORIZED);
        }

        return (int) $user->getId();
    }
}


