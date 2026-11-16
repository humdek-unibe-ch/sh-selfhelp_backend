<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

/**
 * Reads the SQL filter fragment that authors have written into `data_config`
 * (`record_id = 5`, `AND status = 'open' AND age >= 18 ORDER BY entry_date
 * DESC LIMIT 10`) and turns it into the structured filter, sort and page window
 * of a {@see DataQuery}. After this the fragment is never pasted into SQL
 * again: the engine binds every value and allowlists every column.
 *
 * Supported: a leading `AND`; comparisons (`=`, `!=`, `<>`, `<`, `<=`, `>`,
 * `>=`) with a quoted text or a number; `[NOT] IN (...)`; `BETWEEN a AND b`;
 * `IS [NOT] NULL` (read as empty / not empty); `LIKE 'text%'`, `LIKE '%text'`,
 * `LIKE '%text%'` (a pattern without other wildcards); `AND`, `OR` and
 * parentheses; `ORDER BY column [ASC|DESC], ...`; `LIMIT n [OFFSET m]` and
 * `LIMIT m, n`. Columns are the table's column keys and the row fields
 * (`record_id`, `entry_date`, `id_users`, `user_name`, `user_code`,
 * `triggerType`), bare or in backticks, optionally prefixed with `r.`.
 *
 * Everything else (functions, sub-queries, arithmetic, `GROUP BY`, a `LIKE`
 * with inner wildcards) is refused with a message that names the part that is
 * not supported, so the author sees it when saving.
 *
 * A number written without quotes compares as a number; a quoted value
 * compares as the column's declared type (text by default). The fields
 * `record_id`, `id_users` and `id_action_trigger_types` always compare as
 * numbers.
 */
final class LegacyFilterTranslator
{
    private const NUMERIC_ROW_FIELDS = ['record_id', 'id_users', 'id_action_trigger_types'];

    private const COMPARISONS = [
        '=' => DataFilter::OP_EQ,
        '!=' => DataFilter::OP_NEQ,
        '<>' => DataFilter::OP_NEQ,
        '<' => DataFilter::OP_LT,
        '<=' => DataFilter::OP_LTE,
        '>' => DataFilter::OP_GT,
        '>=' => DataFilter::OP_GTE,
    ];

    /** @var list<array{type: string, value: string}> */
    private array $tokens = [];

    private int $position = 0;

    private int $nodes = 0;

    private string $source = '';

    /**
     * @throws DataQueryException when the fragment uses something that has no structured form
     */
    public function translate(string $fragment): LegacyFilter
    {
        $this->source = trim($fragment);
        $this->tokens = $this->tokenize($this->source);
        $this->position = 0;
        $this->nodes = 0;

        if ($this->tokens === []) {
            return new LegacyFilter(new DataFilterGroup());
        }

        if ($this->peekKeyword('AND')) {
            $this->position++;
        } elseif ($this->peekKeyword('OR')) {
            throw $this->unsupported('A filter cannot start with OR.');
        }

        $filter = new DataFilterGroup();
        if (!$this->atEnd() && !$this->peekKeyword('ORDER') && !$this->peekKeyword('LIMIT')) {
            $expression = $this->parseOr(1);
            $filter = $expression instanceof DataFilterGroup ? $expression : new DataFilterGroup(DataFilterGroup::MODE_AND, [$expression]);
        }

        $sorts = [];
        if ($this->peekKeyword('ORDER')) {
            $sorts = $this->parseOrderBy();
        }

        $limit = null;
        $offset = 0;
        if ($this->peekKeyword('LIMIT')) {
            [$limit, $offset] = $this->parseLimit();
        }

        if (!$this->atEnd()) {
            throw $this->unsupported(sprintf('Unexpected "%s".', $this->tokens[$this->position]['value']));
        }

        return new LegacyFilter($filter, $sorts, $limit, $offset);
    }

    // -- grammar ------------------------------------------------------------------

    private function parseOr(int $depth): DataFilter|DataFilterGroup
    {
        $parts = [$this->parseAnd($depth)];
        while ($this->peekKeyword('OR')) {
            $this->position++;
            $parts[] = $this->parseAnd($depth);
        }

        return count($parts) === 1 ? $parts[0] : new DataFilterGroup(DataFilterGroup::MODE_OR, $parts);
    }

    private function parseAnd(int $depth): DataFilter|DataFilterGroup
    {
        $parts = [$this->parsePrimary($depth)];
        while ($this->peekKeyword('AND')) {
            $this->position++;
            $parts[] = $this->parsePrimary($depth);
        }

        return count($parts) === 1 ? $parts[0] : new DataFilterGroup(DataFilterGroup::MODE_AND, $parts);
    }

    private function parsePrimary(int $depth): DataFilter|DataFilterGroup
    {
        if ($this->peek('punct', '(')) {
            if ($depth >= DataQueryLimits::MAX_FILTER_DEPTH) {
                throw $this->unsupported(sprintf('Filters may be nested at most %d levels deep.', DataQueryLimits::MAX_FILTER_DEPTH));
            }
            $this->position++;
            $inner = $this->parseOr($depth + 1);
            $this->expect('punct', ')');

            return $inner instanceof DataFilterGroup ? $inner : new DataFilterGroup(DataFilterGroup::MODE_AND, [$inner]);
        }

        return $this->parsePredicate();
    }

    private function parsePredicate(): DataFilter
    {
        if (++$this->nodes > DataQueryLimits::MAX_FILTER_NODES) {
            throw $this->unsupported(sprintf('At most %d conditions are allowed.', DataQueryLimits::MAX_FILTER_NODES));
        }

        $column = $this->parseColumn();

        // IS [NOT] NULL
        if ($this->peekKeyword('IS')) {
            $this->position++;
            $negated = false;
            if ($this->peekKeyword('NOT')) {
                $this->position++;
                $negated = true;
            }
            $this->expectKeyword('NULL');

            return new DataFilter($column, $negated ? DataFilter::OP_NOT_EMPTY : DataFilter::OP_EMPTY);
        }

        $negated = false;
        if ($this->peekKeyword('NOT')) {
            $this->position++;
            $negated = true;
            if (!$this->peekKeyword('IN') && !$this->peekKeyword('LIKE')) {
                throw $this->unsupported('NOT is supported before IN and LIKE only.');
            }
        }

        if ($this->peekKeyword('IN')) {
            $this->position++;
            $this->expect('punct', '(');
            $literals = [$this->parseLiteral()];
            while ($this->peek('punct', ',')) {
                $this->position++;
                $literals[] = $this->parseLiteral();
            }
            $this->expect('punct', ')');

            return $this->listFilter($column, $negated ? DataFilter::OP_NOT_IN : DataFilter::OP_IN, $literals);
        }

        if ($this->peekKeyword('LIKE')) {
            $this->position++;
            $literal = $this->parseLiteral();
            if ($negated) {
                throw $this->unsupported('NOT LIKE has no structured form; use NOT IN or a comparison.');
            }
            if ($literal['kind'] !== 'string') {
                throw $this->unsupported('LIKE needs a quoted pattern.');
            }

            return $this->likeFilter($column, $literal['value']);
        }

        if ($this->peekKeyword('BETWEEN')) {
            $this->position++;
            $low = $this->parseLiteral();
            $this->expectKeyword('AND');
            $high = $this->parseLiteral();

            return $this->listFilter($column, DataFilter::OP_BETWEEN, [$low, $high]);
        }

        if ($this->peek('operator')) {
            $symbol = $this->tokens[$this->position]['value'];
            $this->position++;
            $literal = $this->parseLiteral();

            return $this->comparisonFilter($column, self::COMPARISONS[$symbol], $literal);
        }

        throw $this->unsupported(sprintf('Expected a comparison after "%s".', $column));
    }

    private function parseColumn(): string
    {
        if ($this->atEnd() || !in_array($this->tokens[$this->position]['type'], ['identifier', 'quoted_identifier'], true)) {
            throw $this->unsupported($this->atEnd() ? 'The filter ends too early.' : sprintf('Expected a column, found "%s".', $this->tokens[$this->position]['value']));
        }
        $name = $this->tokens[$this->position]['value'];
        $isQuoted = $this->tokens[$this->position]['type'] === 'quoted_identifier';
        $this->position++;

        if (!$isQuoted && $this->peek('punct', '(')) {
            throw $this->unsupported(sprintf('The function "%s()" is not supported.', $name));
        }

        // The legacy outer query named its derived table `r`.
        if (str_starts_with($name, 'r.')) {
            $name = substr($name, 2);
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw $this->unsupported(sprintf('"%s" is not a valid column name.', $name));
        }

        return $name;
    }

    /**
     * @return array{kind: string, value: string}
     */
    private function parseLiteral(): array
    {
        if ($this->atEnd()) {
            throw $this->unsupported('The filter ends too early.');
        }
        $token = $this->tokens[$this->position];
        if ($token['type'] === 'string') {
            $this->position++;

            return ['kind' => 'string', 'value' => $token['value']];
        }
        if ($token['type'] === 'number') {
            $this->position++;

            return ['kind' => 'number', 'value' => $token['value']];
        }

        throw $this->unsupported(sprintf('Expected a value, found "%s". Only quoted text and numbers are supported.', $token['value']));
    }

    /**
     * @return list<DataSort>
     */
    private function parseOrderBy(): array
    {
        $this->position++;
        $this->expectKeyword('BY');
        $sorts = [];
        do {
            if ($sorts !== []) {
                $this->position++;
            }
            $column = $this->parseColumn();
            $direction = DataSort::ASC;
            if ($this->peekKeyword('ASC')) {
                $this->position++;
            } elseif ($this->peekKeyword('DESC')) {
                $this->position++;
                $direction = DataSort::DESC;
            }
            $sorts[] = new DataSort($column, $direction);
            if (count($sorts) > DataQueryLimits::MAX_SORTS) {
                throw $this->unsupported(sprintf('At most %d sort keys are allowed.', DataQueryLimits::MAX_SORTS));
            }
        } while ($this->peek('punct', ','));

        return $sorts;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseLimit(): array
    {
        $this->position++;
        $first = $this->parseLiteral();
        if ($first['kind'] !== 'number' || preg_match('/^\d+$/', $first['value']) !== 1) {
            throw $this->unsupported('LIMIT needs a whole number.');
        }

        if ($this->peek('punct', ',')) {
            $this->position++;
            $second = $this->parseLiteral();
            if ($second['kind'] !== 'number' || preg_match('/^\d+$/', $second['value']) !== 1) {
                throw $this->unsupported('LIMIT needs whole numbers.');
            }

            return [(int) $second['value'], (int) $first['value']];
        }

        $offset = 0;
        if ($this->peekKeyword('OFFSET')) {
            $this->position++;
            $second = $this->parseLiteral();
            if ($second['kind'] !== 'number' || preg_match('/^\d+$/', $second['value']) !== 1) {
                throw $this->unsupported('OFFSET needs a whole number.');
            }
            $offset = (int) $second['value'];
        }

        return [(int) $first['value'], $offset];
    }

    // -- building filters ---------------------------------------------------------

    /**
     * @param array{kind: string, value: string} $literal
     */
    private function comparisonFilter(string $column, string $operator, array $literal): DataFilter
    {
        return new DataFilter($column, $operator, $literal['value'], $this->valueTypeFor($column, $literal));
    }

    /**
     * @param list<array{kind: string, value: string}> $literals
     */
    private function listFilter(string $column, string $operator, array $literals): DataFilter
    {
        $type = $this->valueTypeFor($column, $literals[0]);
        foreach ($literals as $literal) {
            if ($this->valueTypeFor($column, $literal) !== $type) {
                throw $this->unsupported(sprintf('The values for "%s" must all be numbers or all be text.', $column));
            }
        }

        return new DataFilter($column, $operator, array_map(static fn (array $literal): string => $literal['value'], $literals), $type);
    }

    private function likeFilter(string $column, string $pattern): DataFilter
    {
        $starts = str_starts_with($pattern, '%');
        $ends = str_ends_with($pattern, '%') && strlen($pattern) > 1;
        $core = substr($pattern, $starts ? 1 : 0, strlen($pattern) - ($starts ? 1 : 0) - ($ends ? 1 : 0));
        if ($core === '' || preg_match('/(?<!\\\\)[%_]/', $core) === 1) {
            throw $this->unsupported('LIKE is supported with the wildcard % at the start and/or end only.');
        }
        $core = str_replace(['\\%', '\\_', '\\\\'], ['%', '_', '\\'], $core);

        $operator = match (true) {
            $starts && $ends => DataFilter::OP_CONTAINS,
            $starts => DataFilter::OP_ENDS_WITH,
            $ends => DataFilter::OP_STARTS_WITH,
            default => DataFilter::OP_EQ,
        };

        return new DataFilter($column, $operator, $core);
    }

    /**
     * The comparison type of one literal: numbers compare as numbers, the
     * numeric row fields always do, a quoted value follows the column.
     *
     * @param array{kind: string, value: string} $literal
     */
    private function valueTypeFor(string $column, array $literal): ?string
    {
        if (in_array($column, self::NUMERIC_ROW_FIELDS, true)) {
            if (!is_numeric($literal['value'])) {
                throw $this->unsupported(sprintf('"%s" is compared with a number.', $column));
            }

            return 'number';
        }

        if ($column === 'entry_date') {
            return 'datetime';
        }

        return $literal['kind'] === 'number' ? 'number' : null;
    }

    // -- tokens -------------------------------------------------------------------

    /**
     * @return list<array{type: string, value: string}>
     */
    private function tokenize(string $text): array
    {
        $tokens = [];
        $length = strlen($text);
        $i = 0;
        while ($i < $length) {
            $char = $text[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            if ($char === "'" || $char === '"') {
                [$value, $i] = $this->readString($text, $i, $char);
                $tokens[] = ['type' => 'string', 'value' => $value];
                continue;
            }

            if ($char === '`') {
                $end = $i + 1;
                $value = '';
                while ($end < $length) {
                    if ($text[$end] === '`') {
                        if (($text[$end + 1] ?? '') === '`') {
                            $value .= '`';
                            $end += 2;
                            continue;
                        }
                        break;
                    }
                    $value .= $text[$end];
                    $end++;
                }
                if ($end >= $length) {
                    throw $this->unsupported('A backtick is not closed.');
                }
                $tokens[] = ['type' => 'quoted_identifier', 'value' => $value];
                $i = $end + 1;
                continue;
            }

            if (in_array($char, ['(', ')', ','], true)) {
                $tokens[] = ['type' => 'punct', 'value' => $char];
                $i++;
                continue;
            }

            $two = substr($text, $i, 2);
            if (in_array($two, ['<=', '>=', '<>', '!='], true)) {
                $tokens[] = ['type' => 'operator', 'value' => $two];
                $i += 2;
                continue;
            }
            if (in_array($char, ['=', '<', '>'], true)) {
                $tokens[] = ['type' => 'operator', 'value' => $char];
                $i++;
                continue;
            }

            if (preg_match('/\G[+-]?\d+(?:\.\d+)?(?![A-Za-z_])/', $text, $match, 0, $i) === 1) {
                $tokens[] = ['type' => 'number', 'value' => $match[0]];
                $i += strlen($match[0]);
                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?/', $text, $match, 0, $i) === 1) {
                $tokens[] = ['type' => 'identifier', 'value' => $match[0]];
                $i += strlen($match[0]);
                continue;
            }

            throw $this->unsupported(sprintf('The character "%s" is not supported.', $char));
        }

        return $tokens;
    }

    /**
     * @return array{0: string, 1: int} the text and the position after the closing quote
     */
    private function readString(string $text, int $start, string $quote): array
    {
        $length = strlen($text);
        $value = '';
        $i = $start + 1;
        while ($i < $length) {
            $char = $text[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $next = $text[$i + 1];
                // A backslash before % or _ stays, so LIKE can tell a literal wildcard from a wildcard.
                $value .= in_array($next, ['%', '_'], true) ? '\\' . $next : $next;
                $i += 2;
                continue;
            }
            if ($char === $quote) {
                if (($text[$i + 1] ?? '') === $quote) {
                    $value .= $quote;
                    $i += 2;
                    continue;
                }

                return [$value, $i + 1];
            }
            $value .= $char;
            $i++;
        }

        throw $this->unsupported('A quote is not closed.');
    }

    private function atEnd(): bool
    {
        return $this->position >= count($this->tokens);
    }

    private function peek(string $type, ?string $value = null): bool
    {
        if ($this->atEnd()) {
            return false;
        }
        $token = $this->tokens[$this->position];

        return $token['type'] === $type && ($value === null || $token['value'] === $value);
    }

    private function peekKeyword(string $keyword): bool
    {
        return $this->peek('identifier') && strtoupper($this->tokens[$this->position]['value']) === $keyword;
    }

    private function expect(string $type, string $value): void
    {
        if (!$this->peek($type, $value)) {
            throw $this->unsupported(sprintf('Expected "%s".', $value));
        }
        $this->position++;
    }

    private function expectKeyword(string $keyword): void
    {
        if (!$this->peekKeyword($keyword)) {
            throw $this->unsupported(sprintf('Expected %s.', $keyword));
        }
        $this->position++;
    }

    private function unsupported(string $reason): DataQueryException
    {
        return new DataQueryException(sprintf(
            'The filter "%s" cannot be used: %s Rewrite it with comparisons, AND/OR, ORDER BY and LIMIT.',
            mb_strimwidth($this->source, 0, 120, '...'),
            $reason
        ));
    }
}
