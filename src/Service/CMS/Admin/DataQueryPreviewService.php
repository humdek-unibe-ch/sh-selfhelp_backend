<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\DataTable;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Common\DataTableFilterService;
use App\Service\CMS\Common\SectionAccessibleRouteService;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\Security\DataAccessSecurityService;
use App\Exception\ServiceException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only admin preview of how an entry-style filter will be prepared for
 * `get_data_table_filtered` -- no arbitrary SQL execution.
 */
final class DataQueryPreviewService
{
    private const STORED_PROCEDURE = 'get_data_table_filtered';

    public function __construct(
        private readonly AdminSectionService $adminSectionService,
        private readonly DataTableFilterService $dataTableFilterService,
        private readonly DataTableService $dataTableService,
        private readonly DataService $dataService,
        private readonly UserContextService $userContextService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly SectionAccessibleRouteService $sectionAccessibleRouteService,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array
    {
        $userId = $this->userContextService->getCurrentUser()?->getId();
        if ($userId === null) {
            throw new ServiceException('User not authenticated', Response::HTTP_UNAUTHORIZED);
        }

        $sectionId = isset($input['section_id']) ? (int) $input['section_id'] : 0;
        $draft = is_array($input['draft'] ?? null) ? $input['draft'] : [];

        $dataTableId = $this->resolveInt($draft['data_table'] ?? $input['data_table'] ?? null);
        $rawFilter = $this->asString($draft['filter'] ?? $input['filter'] ?? '');
        $selectedColumns = $this->asString($draft['selected_columns'] ?? $input['selected_columns'] ?? '');
        $ownEntriesOnly = $this->resolveBool($draft['own_entries_only'] ?? $input['own_entries_only'] ?? null, true);
        $languageId = $this->resolveInt($draft['language_id'] ?? $input['language_id'] ?? null) ?: 1;
        $timezoneCode = $this->asString($draft['timezone_code'] ?? $input['timezone_code'] ?? '') ?: 'UTC';

        if ($sectionId > 0 && $this->sectionAccessibleRouteService->hasAccessiblePagesForSection($sectionId, $userId)) {
            $sectionPayload = $this->adminSectionService->getSection(null, $sectionId);
            $dataTableId = $dataTableId > 0
                ? $dataTableId
                : $this->resolveInt($this->readPropertyField($sectionPayload, 'data_table'));
            if ($rawFilter === '') {
                $rawFilter = $this->readPropertyField($sectionPayload, 'filter');
            }
            if ($selectedColumns === '') {
                $selectedColumns = $this->readPropertyField($sectionPayload, 'selected_columns');
            }
            if (!array_key_exists('own_entries_only', $draft) && !array_key_exists('own_entries_only', $input)) {
                $ownEntriesOnly = $this->readPropertyBool($sectionPayload, 'own_entries_only', $ownEntriesOnly);
            }
        }

        $routeParams = $this->normalizeStringMap($input['route_params'] ?? $draft['route_params'] ?? []);
        $routeRequirements = $this->normalizeStringMap($input['route_requirements'] ?? $draft['route_requirements'] ?? []);

        if ($sectionId > 0 && $routeParams === []) {
            $routeParams = $this->defaultRouteParamsForSection($sectionId, $userId);
        }
        if ($sectionId > 0 && $routeRequirements === []) {
            $routeRequirements = $this->routeRequirementsForSection($sectionId, $userId);
        }

        $errors = [];
        $warnings = [];

        if ($dataTableId <= 0) {
            $errors[] = 'Select a data table before previewing the filter.';
        }

        $dataTable = $dataTableId > 0 ? $this->dataService->getDataTableById($dataTableId) : null;
        if ($dataTableId > 0 && !$dataTable instanceof DataTable) {
            $errors[] = sprintf('Data table id %d was not found.', $dataTableId);
        }

        if ($dataTable instanceof DataTable
            && !$this->dataTableService->canAccessDataTable($userId, (int) $dataTable->getId(), DataAccessSecurityService::PERMISSION_READ)
        ) {
            throw new ServiceException('Access denied', Response::HTTP_FORBIDDEN);
        }

        $normalizedColumns = $this->dataTableFilterService->sanitizeSelectedColumns($selectedColumns);
        if ($selectedColumns !== '' && $normalizedColumns === '') {
            $errors[] = 'Selected columns contain invalid identifiers or exceed the allowed length.';
        }

        $context = [
            'route' => $routeParams,
            'route_requirements' => $routeRequirements,
        ];

        $preparedFilter = '';
        if ($rawFilter !== '') {
            $preparedFilter = $this->dataTableFilterService->prepareFilter(
                $rawFilter,
                $context,
                $routeRequirements !== [] ? $routeRequirements : null,
            );
            if ($preparedFilter === '') {
                if ($this->containsUnresolvedInterpolation($rawFilter)) {
                    $errors[] = 'The filter still contains unresolved {{ }} tokens. Provide sample route parameter values or fix the filter.';
                } else {
                    $errors[] = 'The filter was rejected by the SQL safety rules or could not be normalized. Check route parameters, comparisons, and dangerous SQL patterns.';
                }
            }
        }

        foreach ($this->findUnresolvedRouteTokens($rawFilter) as $token) {
            $param = substr($token, strlen('route.'));
            if (!array_key_exists($param, $routeParams) || $routeParams[$param] === '') {
                $warnings[] = sprintf('Route token {{%s}} has no sample value in this preview.', $token);
            }
        }

        $tableName = $dataTable instanceof DataTable ? (string) $dataTable->getName() : '';
        $columns = [];
        if ($tableName !== '') {
            $columnRows = $this->dataTableService->getColumns($tableName);
            if (is_array($columnRows)) {
                foreach ($columnRows as $column) {
                    if (!is_array($column)) {
                        continue;
                    }
                    $columns[] = [
                        'fieldKey' => $column['fieldKey'] ?? null,
                        'displayName' => $column['displayName'] ?? null,
                        'standard' => (bool) ($column['standard'] ?? false),
                    ];
                }
            }
        }

        $excludeDeleted = true;
        $procedureParams = [
            'tableId' => $dataTableId > 0 ? $dataTableId : null,
            'userId' => $ownEntriesOnly ? $userId : null,
            'filter' => $preparedFilter,
            'excludeDeleted' => $excludeDeleted,
            'languageId' => $languageId,
            'timezoneCode' => $timezoneCode,
            'selectedColumns' => $normalizedColumns,
        ];

        return [
            'data_table' => $dataTable instanceof DataTable ? [
                'id' => (int) $dataTable->getId(),
                'name' => (string) $dataTable->getName(),
                'displayName' => $dataTable->getDisplayName(),
            ] : null,
            'columns' => $columns,
            'route_params' => $routeParams,
            'route_requirements' => $routeRequirements,
            'raw_filter' => $rawFilter,
            'prepared_filter' => $preparedFilter,
            'selected_columns' => $normalizedColumns,
            'own_entries_only' => $ownEntriesOnly,
            'language_id' => $languageId,
            'timezone_code' => $timezoneCode,
            'errors' => $errors,
            'warnings' => $warnings,
            'stored_procedure' => [
                'name' => self::STORED_PROCEDURE,
                'call' => sprintf(
                    'CALL %s(:tableId, :userId, :filter, :excludeDeleted, :languageId, :timezoneCode, :selectedColumns)',
                    self::STORED_PROCEDURE,
                ),
                'parameters' => $procedureParams,
            ],
            'sql_shape' => $this->buildSqlShape($tableName, $preparedFilter, $normalizedColumns, $ownEntriesOnly, $userId),
        ];
    }

    /**
     * @param array<string, mixed> $sectionPayload
     */
    private function readPropertyField(array $sectionPayload, string $fieldName): string
    {
        $fields = is_array($sectionPayload['fields'] ?? null) ? $sectionPayload['fields'] : [];
        foreach ($fields as $field) {
            if (!is_array($field) || ($field['name'] ?? '') !== $fieldName) {
                continue;
            }
            $translations = is_array($field['translations'] ?? null) ? $field['translations'] : [];
            foreach ($translations as $translation) {
                if (!is_array($translation)) {
                    continue;
                }
                if ((int) ($translation['language_id'] ?? 0) === 1) {
                    return $this->asString($translation['content'] ?? '');
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $sectionPayload
     */
    private function readPropertyBool(array $sectionPayload, string $fieldName, bool $default): bool
    {
        $raw = $this->readPropertyField($sectionPayload, $fieldName);
        if ($raw === '') {
            return $default;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string, string>
     */
    private function defaultRouteParamsForSection(int $sectionId, int $userId): array
    {
        $params = [];
        foreach ($this->sectionAccessibleRouteService->getRoutePlaceholdersForSection($sectionId, $userId) as $placeholder) {
            $params[$placeholder] = $this->sampleRouteValue($placeholder);
        }

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private function routeRequirementsForSection(int $sectionId, int $userId): array
    {
        return $this->sectionAccessibleRouteService->getRouteRequirementsForSection($sectionId, $userId);
    }

    private function sampleRouteValue(string $placeholder): string
    {
        if ($placeholder === 'record_id' || str_ends_with($placeholder, '_id')) {
            return '1';
        }

        return 'sample';
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $out[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function findUnresolvedRouteTokens(string $filter): array
    {
        if ($filter === '' || !preg_match_all('/\{\{\s*route\.([A-Za-z0-9_]+)\s*\}\}/', $filter, $matches)) {
            return [];
        }

        $tokens = [];
        foreach ($matches[1] as $param) {
            $tokens['route.' . $param] = true;
        }

        return array_keys($tokens);
    }

    private function containsUnresolvedInterpolation(string $value): bool
    {
        return preg_match('/\{\{[^}]+\}\}/', $value) === 1;
    }

    private function buildSqlShape(
        string $tableName,
        string $preparedFilter,
        string $selectedColumns,
        bool $ownEntriesOnly,
        int $userId,
    ): string {
        $tableLabel = $tableName !== '' ? $tableName : '<data_table>';
        $where = 'WHERE 1=1';
        if ($preparedFilter !== '') {
            $fragment = $preparedFilter;
            if (!preg_match('/^\s*(AND|OR)\b/i', $fragment)) {
                $fragment = 'AND ' . $fragment;
            }
            $where .= ' ' . $fragment;
        }
        if ($ownEntriesOnly) {
            $where .= sprintf(' AND id_users = %d', $userId);
        }
        $shape = sprintf('SELECT pivoted rows FROM data table "%s" %s', $tableLabel, $where);
        if ($selectedColumns !== '') {
            $shape .= sprintf(' [column subset: %s]', $selectedColumns);
        }

        return $shape;
    }

    private function asString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private function resolveInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function resolveBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_string($value)) {
            return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }
}
