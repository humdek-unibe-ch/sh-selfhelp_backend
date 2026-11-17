<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Service\CMS\DataQuery\DataQueryLimits;
use App\Service\CMS\DataQuery\DataSort;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the query string of a list request into a {@see FormListUrlState}.
 *
 * Strict by design: a parameter in the list's namespace (`<key>.`) that is
 * unknown, repeated, written as an array or malformed refuses the request
 * instead of being silently ignored, so a bookmarked or hand-edited URL never
 * means something the visitor did not ask for. Parameters outside the
 * namespace (the page, the language, other lists) are not this parser's
 * business.
 *
 * The raw query string is read here, not PHP's decoded array: PHP rewrites a
 * dot in a parameter name to an underscore, which would destroy the
 * namespace.
 */
final class FormListUrlStateParser
{
    private const POSITIVE_INT = '/^[1-9][0-9]{0,8}$/';
    private const SORT_ITEM = '/^([A-Za-z][A-Za-z0-9_]{0,63}):(asc|desc)$/';
    private const MAX_PAGE = 1000000;
    private const MAX_VALUE_LENGTH = 1000;

    /**
     * @throws FormListStateException
     */
    public function parse(string $queryString, FormListConfig $config): FormListUrlState
    {
        $prefix = $config->key . '.';
        $own = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $name = $this->decode($parts[0]);
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            if (array_key_exists($name, $own)) {
                throw new FormListStateException(sprintf('The parameter "%s" is given twice.', $name), 'duplicate_list_parameter', $name);
            }
            $own[$name] = $this->decode($parts[1] ?? '');
        }

        $page = 1;
        $search = null;
        $sorts = [];
        $filters = [];
        $recordId = null;
        $mode = null;

        foreach ($own as $name => $value) {
            $suffix = substr($name, strlen($prefix));
            if (strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new FormListStateException(sprintf('The parameter "%s" is too long.', $name), 'invalid_list_parameter', $name);
            }
            switch (true) {
                case $suffix === 'page':
                    $page = $this->positiveInt($value, $name);
                    if ($page > self::MAX_PAGE) {
                        throw new FormListStateException(sprintf('The page in "%s" is out of range.', $name), 'invalid_list_parameter', $name);
                    }
                    break;
                case $suffix === 'q':
                    $search = $this->search($value, $name);
                    break;
                case $suffix === 'sort':
                    $sorts = $this->sorts($value, $name, $config);
                    break;
                case $suffix === 'record':
                    $recordId = $this->positiveInt($value, $name);
                    break;
                case $suffix === 'mode':
                    if (!in_array($value, FormListUrlState::MODES, true)) {
                        throw new FormListStateException(sprintf('The mode in "%s" is view, edit, create or history.', $name), 'invalid_list_parameter', $name);
                    }
                    $mode = $value;
                    break;
                case str_starts_with($suffix, 'f.'):
                    $controlId = substr($suffix, 2);
                    if ($config->control($controlId) === null) {
                        throw new FormListStateException(sprintf('The list has no filter "%s".', $controlId), 'unknown_list_parameter', $name);
                    }
                    if ($value !== '') {
                        $filters[$controlId] = $value;
                    }
                    break;
                default:
                    throw new FormListStateException(sprintf('The list has no parameter "%s".', $name), 'unknown_list_parameter', $name);
            }
        }

        return $this->withMode($config, new FormListUrlState($page, $search, $sorts, $filters, $recordId, $mode));
    }

    /**
     * The record and the mode belong together: a record without a mode is
     * viewed; a mode that needs a record without one, and `create` with a
     * record, are refused. A mode the list does not offer is forbidden.
     */
    private function withMode(FormListConfig $config, FormListUrlState $state): FormListUrlState
    {
        $mode = $state->mode;
        if ($mode === null && $state->recordId !== null) {
            $mode = FormListUrlState::MODE_VIEW;
        }

        if ($mode === FormListUrlState::MODE_CREATE) {
            if ($state->recordId !== null) {
                throw new FormListStateException('A new entry has no record.', 'invalid_list_parameter', $config->key . '.record');
            }
            if (!$config->allowCreate) {
                throw new FormListStateException('This list does not allow new entries.', 'operation_disabled', $config->key . '.mode', Response::HTTP_FORBIDDEN);
            }
        } elseif ($mode !== null && $state->recordId === null) {
            throw new FormListStateException(sprintf('The mode "%s" needs a record.', $mode), 'invalid_list_parameter', $config->key . '.record');
        }
        if ($mode === FormListUrlState::MODE_EDIT && !$config->allowUpdate) {
            throw new FormListStateException('This list does not allow editing.', 'operation_disabled', $config->key . '.mode', Response::HTTP_FORBIDDEN);
        }
        if ($mode === FormListUrlState::MODE_HISTORY && !$config->showHistory) {
            throw new FormListStateException('This list does not offer history.', 'history_disabled', $config->key . '.mode', Response::HTTP_FORBIDDEN);
        }

        return new FormListUrlState($state->page, $state->search, $state->sorts, $state->filters, $state->recordId, $mode);
    }

    /**
     * @throws FormListStateException
     */
    private function positiveInt(string $value, string $name): int
    {
        if (preg_match(self::POSITIVE_INT, $value) !== 1) {
            throw new FormListStateException(sprintf('The parameter "%s" is a positive whole number.', $name), 'invalid_list_parameter', $name);
        }

        return (int) $value;
    }

    /**
     * @throws FormListStateException
     */
    private function search(string $value, string $name): ?string
    {
        $term = trim($value);
        if ($term === '') {
            return null;
        }
        $length = mb_strlen($term);
        if ($length < DataQueryLimits::SEARCH_MIN_LENGTH || $length > DataQueryLimits::SEARCH_MAX_LENGTH) {
            throw new FormListStateException(
                sprintf('A search has %d to %d characters.', DataQueryLimits::SEARCH_MIN_LENGTH, DataQueryLimits::SEARCH_MAX_LENGTH),
                'invalid_list_parameter',
                $name,
            );
        }

        return $term;
    }

    /**
     * @return list<DataSort>
     * @throws FormListStateException
     */
    private function sorts(string $value, string $name, FormListConfig $config): array
    {
        if ($value === '') {
            return [];
        }
        $items = explode(',', $value);
        if (count($items) > DataQueryLimits::MAX_SORTS) {
            throw new FormListStateException(sprintf('At most %d sort keys.', DataQueryLimits::MAX_SORTS), 'invalid_list_parameter', $name);
        }
        $sortable = $config->sortableFields();
        $sorts = [];
        $seen = [];
        foreach ($items as $item) {
            if (preg_match(self::SORT_ITEM, $item, $match) !== 1) {
                throw new FormListStateException('A sort key is "column:asc" or "column:desc".', 'invalid_list_parameter', $name);
            }
            if (!in_array($match[1], $sortable, true)) {
                throw new FormListStateException(sprintf('The list cannot be sorted by "%s".', $match[1]), 'invalid_list_parameter', $name);
            }
            if (isset($seen[$match[1]])) {
                throw new FormListStateException(sprintf('"%s" is sorted twice.', $match[1]), 'invalid_list_parameter', $name);
            }
            $seen[$match[1]] = true;
            $sorts[] = new DataSort($match[1], $match[2]);
        }

        return $sorts;
    }

    private function decode(string $text): string
    {
        return rawurldecode(str_replace('+', ' ', $text));
    }
}
