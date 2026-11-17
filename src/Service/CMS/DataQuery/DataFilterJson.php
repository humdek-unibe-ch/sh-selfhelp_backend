<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * The stored (JSON) form of a filter: what a list's fixed filter, a quick
 * action's condition and the structured `data_config.filter` of a section
 * contain. One shape for every author-facing filter:
 *
 *   {"mode":"and","children":[
 *       {"column":"section_12","operator":"eq","value":"open"},
 *       {"mode":"or","children":[ ... ]}
 *   ]}
 *
 * Parsing checks the shape and the limits only. Whether a column exists and
 * whether a value fits the column's type is decided by the engine when the
 * query is planned ({@see DataQueryEngine::compile()}), which is what
 * author-time validation calls.
 */
final class DataFilterJson
{
    private const FILTER_KEYS = ['column', 'operator', 'value', 'type'];
    private const GROUP_KEYS = ['mode', 'children'];

    private function __construct()
    {
    }

    /**
     * @throws DataQueryException when the shape or a limit is violated
     */
    public static function parse(mixed $raw): DataFilterGroup
    {
        if ($raw === null || $raw === [] || $raw === '') {
            return new DataFilterGroup();
        }
        if (!is_array($raw) || !array_key_exists('children', $raw)) {
            throw new DataQueryException('A filter is a group: {"mode":"and|or","children":[...]}.');
        }

        $nodes = 0;
        $group = self::parseGroup($raw, 1, $nodes);
        if ($nodes > DataQueryLimits::MAX_FILTER_NODES) {
            throw new DataQueryException(sprintf('A filter may have at most %d conditions.', DataQueryLimits::MAX_FILTER_NODES));
        }

        return $group;
    }

    /**
     * @return array{mode: string, children: list<array<string, mixed>>}
     */
    public static function toArray(DataFilterGroup $group): array
    {
        $children = [];
        foreach ($group->children as $child) {
            if ($child instanceof DataFilterGroup) {
                $children[] = self::toArray($child);
                continue;
            }
            $item = ['column' => $child->column, 'operator' => $child->operator];
            if (!in_array($child->operator, DataFilter::VALUELESS_OPERATORS, true)) {
                $item['value'] = $child->value;
            }
            if ($child->valueType !== null) {
                $item['type'] = $child->valueType;
            }
            $children[] = $item;
        }

        return ['mode' => $group->mode, 'children' => $children];
    }

    /**
     * Levels of a filter: a group of plain conditions is 1.
     */
    public static function depth(DataFilterGroup $group): int
    {
        $deepest = 0;
        foreach ($group->children as $child) {
            if ($child instanceof DataFilterGroup) {
                $deepest = max($deepest, self::depth($child));
            }
        }

        return 1 + $deepest;
    }

    /**
     * Conditions and nested groups of a filter, counted as the engine counts them.
     */
    public static function nodes(DataFilterGroup $group): int
    {
        $count = 0;
        foreach ($group->children as $child) {
            ++$count;
            if ($child instanceof DataFilterGroup) {
                $count += self::nodes($child);
            }
        }

        return $count;
    }

    /**
     * Every column key a filter names, once each, in first-seen order.
     *
     * @return list<string>
     */
    public static function columnsOf(DataFilterGroup $group): array
    {
        $columns = [];
        foreach ($group->children as $child) {
            if ($child instanceof DataFilterGroup) {
                foreach (self::columnsOf($child) as $column) {
                    $columns[$column] = true;
                }
                continue;
            }
            $columns[$child->column] = true;
        }

        return array_map('strval', array_keys($columns));
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function parseGroup(array $raw, int $depth, int &$nodes): DataFilterGroup
    {
        if ($depth > DataQueryLimits::MAX_FILTER_DEPTH) {
            throw new DataQueryException(sprintf('A filter may be nested at most %d levels deep.', DataQueryLimits::MAX_FILTER_DEPTH));
        }
        self::assertKeys($raw, self::GROUP_KEYS, 'group');

        $mode = $raw['mode'] ?? DataFilterGroup::MODE_AND;
        if (!in_array($mode, [DataFilterGroup::MODE_AND, DataFilterGroup::MODE_OR], true)) {
            throw new DataQueryException('A filter group mode is "and" or "or".');
        }
        $children = $raw['children'] ?? [];
        if (!is_array($children) || !array_is_list($children)) {
            throw new DataQueryException('A filter group needs a list of children.');
        }

        $parsed = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                throw new DataQueryException('A filter condition is an object.');
            }
            ++$nodes;
            if ($nodes > DataQueryLimits::MAX_FILTER_NODES) {
                throw new DataQueryException(sprintf('A filter may have at most %d conditions.', DataQueryLimits::MAX_FILTER_NODES));
            }
            $parsed[] = array_key_exists('children', $child)
                ? self::parseGroup($child, $depth + 1, $nodes)
                : self::parseFilter($child);
        }

        return new DataFilterGroup((string) $mode, $parsed);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function parseFilter(array $raw): DataFilter
    {
        self::assertKeys($raw, self::FILTER_KEYS, 'condition');

        $column = $raw['column'] ?? null;
        $operator = $raw['operator'] ?? null;
        if (!is_string($column) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $column) !== 1) {
            throw new DataQueryException('A filter condition names a column.');
        }
        if (!is_string($operator) || !in_array($operator, DataFilter::OPERATORS, true)) {
            throw new DataQueryException(sprintf('Unknown filter operator "%s".', is_string($operator) ? $operator : gettype($operator)));
        }
        $type = $raw['type'] ?? null;
        if ($type !== null && !is_string($type)) {
            throw new DataQueryException('A filter condition type is a text.');
        }

        return new DataFilter($column, $operator, $raw['value'] ?? null, $type);
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param list<string> $allowed
     */
    private static function assertKeys(array $raw, array $allowed, string $what): void
    {
        foreach (array_keys($raw) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new DataQueryException(sprintf('A filter %s has no property "%s".', $what, (string) $key));
            }
        }
    }
}
