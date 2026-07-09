<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS\Common;

use App\Service\Core\InterpolationService;

/**
 * Validates and normalizes SQL filter fragments passed to
 * `get_data_table_filtered` (concatenated after `WHERE 1=1`).
 */
class DataTableFilterService
{
    public const MAX_FILTER_LENGTH = 1000;

    public const MAX_SELECTED_COLUMNS_LENGTH = 4000;

    /**
     * Keywords / patterns that must never appear in an author filter.
     */
    private const UNSAFE_PATTERN = '/(?:;|\b(?:DROP|DELETE|UPDATE|INSERT|UNION|INTO\s+OUTFILE|LOAD_FILE|INFORMATION_SCHEMA|SLEEP|BENCHMARK|EXEC|EXECUTE)\b|--|\/\*)/i';

    public function __construct(
        private readonly InterpolationService $interpolationService,
    ) {
    }

    /**
     * Interpolate tokens, validate, and normalize a author filter fragment.
     *
     * @param array<array-key, mixed> $context Interpolation scopes (must include `route` when used).
     * @param array<string, string>|null $routeRequirements Symfony route requirements keyed by param name.
     */
    public function prepareFilter(string $rawFilter, array $context, ?array $routeRequirements = null): string
    {
        $filter = trim($rawFilter);
        if ($filter === '') {
            return '';
        }

        $filter = $this->interpolateRouteTokens($filter, $context, $routeRequirements ?? []);
        if ($filter === '' || str_contains($filter, '{{UNRESOLVED}}')) {
            return '';
        }

        if ($this->containsUnresolvedTokens($filter)) {
            $filter = $this->interpolationService->interpolate($filter, $context);
        }

        if ($this->containsUnresolvedTokens($filter)) {
            return '';
        }

        if ($this->isIncompleteComparison($filter)) {
            return '';
        }

        if (!$this->isSafeFilterFragment($filter)) {
            return '';
        }

        return $this->glueLeadingAnd($filter);
    }

    /**
     * @param mixed $value Raw route parameter value.
     */
    public function validateRecordId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && $value !== '') {
            $filtered = filter_var($value, FILTER_VALIDATE_INT);
            if ($filtered !== false && (int) $filtered > 0) {
                return (int) $filtered;
            }
        }

        return null;
    }

    public function appendRecordIdFilter(string $filter, int $recordId): string
    {
        if ($recordId <= 0) {
            return '';
        }

        $suffix = ' AND record_id = ' . $recordId;

        return trim($filter . $suffix);
    }

    /**
     * Build a safe equality predicate for update-by-field lookups.
     */
    public function buildStringEqualityPredicate(string $column, string $value): string
    {
        $column = $this->sanitizeIdentifier($column);
        if ($column === '') {
            return '';
        }

        return ' AND ' . $column . ' = ' . $this->quoteSqlString($value);
    }

    public function glueLeadingAnd(string $filter): string
    {
        $filter = trim($filter);
        if ($filter === '') {
            return '';
        }

        if (preg_match('/^(AND|OR|ORDER\s+BY|LIMIT|GROUP\s+BY|HAVING)\b/i', $filter) === 1) {
            return $filter;
        }

        return 'AND ' . $filter;
    }

    /**
     * Last-line guard before a filter reaches `get_data_table_filtered*`.
     * Callers should already have run {@see prepareFilter()}; this rejects
     * unresolved tokens and over-length fragments.
     */
    /**
     * Normalize a comma-separated column subset for `get_data_table_filtered`.
     * Rejects unknown characters and over-length payloads.
     */
    public function sanitizeSelectedColumns(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (strlen($raw) > self::MAX_SELECTED_COLUMNS_LENGTH) {
            return '';
        }

        $parts = array_map('trim', explode(',', $raw));
        $safe = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $part) !== 1) {
                return '';
            }

            $safe[] = $part;
        }

        if ($safe === []) {
            return '';
        }

        return implode(',', array_values(array_unique($safe)));
    }

    public function guardForStoredProcedure(string $filter): string
    {
        $filter = trim($filter);
        if ($filter === '' || str_contains($filter, '{{')) {
            return '';
        }

        if (strlen($filter) > self::MAX_FILTER_LENGTH) {
            return '';
        }

        return $filter;
    }

    public function isSafeFilterFragment(string $filter): bool
    {
        $filter = trim($filter);
        if ($filter === '') {
            return true;
        }

        if (strlen($filter) > self::MAX_FILTER_LENGTH) {
            return false;
        }

        if (preg_match("/'\\s*OR\\s+'/i", $filter) === 1 || preg_match("/'\\s*AND\\s+'/i", $filter) === 1) {
            return false;
        }

        return preg_match(self::UNSAFE_PATTERN, $filter) !== 1;
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<string, string> $routeRequirements
     */
    private function interpolateRouteTokens(string $filter, array $context, array $routeRequirements): string
    {
        $route = $context['route'] ?? null;
        if (!is_array($route)) {
            return $filter;
        }

        return (string) preg_replace_callback(
            '/\{\{route\.([a-zA-Z0-9_]+)\}\}/',
            function (array $matches) use ($route, $routeRequirements): string {
                $paramName = $matches[1];
                if (!array_key_exists($paramName, $route)) {
                    return '{{UNRESOLVED}}';
                }

                $raw = $route[$paramName];
                $requirement = $routeRequirements[$paramName] ?? null;

                return $this->formatRouteValueForSql($paramName, $raw, is_string($requirement) ? $requirement : null);
            },
            $filter,
        );
    }

    private function formatRouteValueForSql(string $paramName, mixed $raw, ?string $requirement): string
    {
        if (!is_scalar($raw)) {
            return '{{UNRESOLVED}}';
        }

        $stringValue = (string) $raw;

        if ($this->isIntegerRequirement($paramName, $requirement)) {
            $id = $this->validateRecordId($stringValue);

            return $id !== null ? (string) $id : '{{UNRESOLVED}}';
        }

        if ($requirement !== null && @preg_match('{' . $requirement . '}', $stringValue) !== 1) {
            return '{{UNRESOLVED}}';
        }

        if (preg_match('/^[a-zA-Z0-9._@-]+$/', $stringValue) !== 1) {
            return '{{UNRESOLVED}}';
        }

        return $this->quoteSqlString($stringValue);
    }

    private function isIntegerRequirement(string $paramName, ?string $requirement): bool
    {
        if ($paramName === 'record_id' || str_ends_with($paramName, '_id')) {
            return true;
        }

        if ($requirement === null) {
            return false;
        }

        return str_contains($requirement, '\d')
            || str_contains($requirement, '[0-9]');
    }

    private function quoteSqlString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function sanitizeIdentifier(string $name): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            return '';
        }

        return $name;
    }

    private function containsUnresolvedTokens(string $value): bool
    {
        return str_contains($value, '{{');
    }

    private function isIncompleteComparison(string $filter): bool
    {
        return preg_match('/=\s*$/', trim($filter)) === 1
            || preg_match('/=\s*(AND|OR)\s*$/i', trim($filter)) === 1;
    }
}
