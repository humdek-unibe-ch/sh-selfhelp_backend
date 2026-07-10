<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\RequestValidationException;
use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\DataQueryPreviewService;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\CMS\Frontend\OptionLabelHydrator;
use App\Service\Core\LookupService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\TransactionService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\Security\DataAccessSecurityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminDataController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly DataService $dataService,
        private readonly DataTableService $dataTableService,
        private readonly DataQueryPreviewService $dataQueryPreviewService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        private readonly UserContextService $userContextService,
        private readonly TransactionService $transactionService,
        private readonly OptionLabelHydrator $optionLabelHydrator,
    ) {
    }

    /**
     * Preview how an entry-style filter will be prepared for get_data_table_filtered.
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
            $languageId = $request->query->has('language_id') ? (int) $request->query->get('language_id') : 1;

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

            $success = $this->dataService->deleteData($recordId, $ownEntriesOnly);
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
            return $this->responseFormatter->formatSuccess(['columns' => $columns]);
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
     * Export a single data table as CSV or JSON (raw, no envelope).
     * Query params: format=csv|json, user_id (optional), language_id (optional, default 1), exclude_deleted (optional, default true)
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
            if (!in_array($format, ['csv', 'json'], true)) {
                return $this->responseFormatter->formatError('format must be csv or json', Response::HTTP_BAD_REQUEST);
            }

            $userId = $request->query->has('user_id') ? (int) $request->query->get('user_id') : null;
            $languageId = $request->query->has('language_id') ? (int) $request->query->get('language_id') : 1;
            $excludeDeleted = filter_var($request->query->get('exclude_deleted', 'true'), FILTER_VALIDATE_BOOLEAN);

            $hasFullAccess = $this->dataTableService->canAccessDataTable($currentUserId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_DELETE);
            if ($hasFullAccess) {
                $rows = $this->dataService->getData((int) $dataTable->getId(), '', false, $userId, false, $excludeDeleted, $languageId);
            } else {
                $rows = $this->dataService->getDataWithUserGroupFilter((int) $dataTable->getId(), $currentUserId, '', $excludeDeleted, $languageId);
            }
            // Export with the current human input name as the column header
            // instead of the immutable section_<id> key (issue #56).
            $rows = $this->dataService->remapEntriesToInputNames((int) $dataTable->getId(), $rows);

            $label = $dataTable->getDisplayName() ?? $dataTable->getName() ?? $tableName;
            $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $label) . '.' . $format;

            if ($format === 'json') {
                return new Response(
                    (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    Response::HTTP_OK,
                    [
                        'Content-Type' => 'application/json',
                        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    ]
                );
            }

            // CSV
            $csvContent = $this->buildCsv($rows);
            return new Response(
                $csvContent,
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
            $languageId = isset($data['language_id']) && is_int($data['language_id']) ? $data['language_id'] : 1;
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
                if ($hasFullAccess) {
                    $rows = $this->dataService->getData((int) $dataTable->getId(), '', false, $userId, false, $excludeDeleted, $languageId);
                } else {
                    $rows = $this->dataService->getDataWithUserGroupFilter((int) $dataTable->getId(), $currentUserId, '', $excludeDeleted, $languageId);
                }
                // Export with the current human input name as the column header
                // instead of the immutable section_<id> key (issue #56).
                $rows = $this->dataService->remapEntriesToInputNames((int) $dataTable->getId(), $rows);

                $label = $dataTable->getDisplayName() ?? $dataTable->getName() ?? '';
                $entryName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $label) . '.' . $format;

                $content = $format === 'json'
                    ? (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    : $this->buildCsv($rows);

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
     * Build a CSV string from a list of row arrays.
     * Collects the union of all keys as the header row; missing cells are empty.
     *
     * @param array<array-key, mixed> $rows
     */
    private function buildCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        // Union of all column names across all rows
        $headers = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                foreach (array_keys($row) as $key) {
                    $headers[(string) $key] = true;
                }
            }
        }
        $headers = array_keys($headers);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $headers, escape: '');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = [];
            foreach ($headers as $col) {
                $val = $row[$col] ?? null;
                $line[] = is_scalar($val) ? (string) $val : '';
            }
            fputcsv($handle, $line, escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
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
}


