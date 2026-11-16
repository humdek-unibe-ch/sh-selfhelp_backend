<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataQuery;

use App\Service\Core\LookupService;

/**
 * How the text a cell stores is read as the value type its column declares (`text`, `number`, `date`,
 * `datetime`, `boolean`), in PHP and in SQL, by one rule.
 *
 * {@see self::reads()} answers whether a text is a value of the type; {@see self::sql()} is the SQL expression
 * the query engine uses to read a cell as that type (NULL when it cannot be read). The two are twins: a value
 * the engine cannot read would never match a typed filter, so a write must not put one there, and a value a
 * write accepts must be read by the engine. `DataColumnValueTypeTest` runs one table of values through both.
 *
 * An empty value is always accepted (a field left empty); the engine treats it as "missing" for typed
 * comparisons. Only spaces are trimmed around a value, as SQL `TRIM` does.
 *
 * Dates and times, in short:
 *  - a `date` is a real calendar day, `YYYY-MM-DD` (year 1000 to 9999, leap days included); a text with a
 *    time after it is a datetime text and is read as its day as written;
 *  - a `datetime` is `YYYY-MM-DD`, or `YYYY-MM-DD HH:MM[:SS[.fraction]]` with a space or a `T`, and an optional
 *    zone: `Z` or an offset `+HH:MM` / `-HH:MM` / `+HHMM` / `-HHMM` between -13:59 and +14:00;
 *  - the clock must exist (hour 00-23, minute and second 00-59); the fraction is not compared (the second is);
 *  - times are UTC ("every time is stored in UTC"): a text with a zone is the instant it names and is compared
 *    as that instant in UTC, a text without a zone is read as a UTC clock time. A text the person typed
 *    without a zone therefore compares to other texts without a zone exactly as typed.
 */
final class DataColValueType
{
    /**
     * The end of the text and nothing else. `$` would also match before a final line break, in PCRE and in ICU (the
     * regular expressions of MySQL 8) differently, so neither is used.
     */
    private const END = '\z';

    /** `YYYY-MM-DD`, year 1000-9999; the calendar (days of the month, leap years) is checked beside it. */
    private const DATE = '[1-9][0-9]{3}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])';

    /** `[ T]HH:MM[:SS[.fraction]]` with an optional zone; one pattern for PHP and for SQL (no backslash in it). */
    private const TIME = '[ T]([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9]([.][0-9]+)?)?'
        . '(Z|[+]((0[0-9]|1[0-3]):?[0-5][0-9]|14:?00)|-(0[0-9]|1[0-3]):?[0-5][0-9])?';

    public static function reads(string $type, string $text): bool
    {
        $text = trim($text, ' ');
        if ($text === '') {
            return true;
        }

        return match ($type) {
            LookupService::DATA_COL_VALUE_TYPES_NUMBER => preg_match('/^[+-]?[0-9]+([.][0-9]+)?' . self::END . '/', $text) === 1,
            LookupService::DATA_COL_VALUE_TYPES_DATE => self::dayOf($text) !== null,
            LookupService::DATA_COL_VALUE_TYPES_DATETIME => self::instantOf($text) !== null,
            LookupService::DATA_COL_VALUE_TYPES_BOOLEAN => in_array(strtolower($text), ['1', 'true', 'yes', 'on', '0', 'false', 'no', 'off'], true),
            default => true,
        };
    }

    /**
     * The day a text names, as written (`YYYY-MM-DD`): a date, or a datetime text (its time must exist too).
     * Null when the text is neither or the day is not on the calendar.
     */
    public static function dayOf(string $text): ?string
    {
        $parts = self::partsOf($text);

        return $parts === null ? null : $parts['day'];
    }

    /**
     * The instant a datetime text names, in UTC as `YYYY-MM-DD HH:MM:SS`: the clock time shifted by its offset,
     * or as it is when the text has no zone or says `Z`. A date alone is the start of that day.
     * Null when the text is not a datetime, the day is not on the calendar or the clock does not exist.
     */
    public static function instantOf(string $text): ?string
    {
        $parts = self::partsOf($text);
        if ($parts === null) {
            return null;
        }

        $clock = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $parts['day'] . ' ' . $parts['clock'], new \DateTimeZone('UTC'));
        if ($clock === false) {
            return null;
        }
        if ($parts['offset'] !== 0) {
            $clock = $clock->modify(sprintf('%+d minutes', -$parts['offset']));
        }
        if ((int) $clock->format('Y') > 9999) {
            return null; // shifted past the last day SQL can hold: SQL reads such a text as missing too
        }

        return $clock->format('Y-m-d H:i:s');
    }

    /**
     * The SQL that reads a cell text as a value type: the typed value, or NULL when the text is missing or cannot be
     * read as the type. Never an error: one malformed cell must not fail a listing.
     *
     * @param string $text an SQL expression of the cell text
     */
    public static function sql(string $text, string $type): string
    {
        $trimmed = 'TRIM(' . $text . ')';

        return match ($type) {
            LookupService::DATA_COL_VALUE_TYPES_NUMBER => 'CASE WHEN REGEXP_LIKE(' . $trimmed . ', ' . self::sqlLiteral('^[+-]?[0-9]+([.][0-9]+)?' . self::END) . ", 'c') THEN CAST(" . $trimmed . ' AS DECIMAL(30,10)) END',
            LookupService::DATA_COL_VALUE_TYPES_DATE => 'CASE WHEN ' . self::sqlReadable($trimmed) . ' THEN STR_TO_DATE(LEFT(' . $trimmed . ", 10), '%Y-%m-%d') END",
            LookupService::DATA_COL_VALUE_TYPES_DATETIME => self::sqlInstant($trimmed),
            LookupService::DATA_COL_VALUE_TYPES_BOOLEAN => 'CASE WHEN LOWER(' . $trimmed . ") IN ('1', 'true', 'yes', 'on') THEN 1 WHEN LOWER(" . $trimmed . ") IN ('0', 'false', 'no', 'off', '') THEN 0 END",
            default => $text,
        };
    }

    /**
     * A pattern as a quoted SQL text (a backslash is doubled, a quote is doubled).
     */
    private static function sqlLiteral(string $pattern): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $pattern) . "'";
    }

    /**
     * Pieces of a valid date or datetime text.
     *
     * @return array{day: string, clock: string, offset: int}|null `clock` is `HH:MM:SS`; `offset` is minutes east of UTC
     */
    private static function partsOf(string $text): ?array
    {
        $text = trim($text, ' ');
        $pattern = '/^(?<day>' . self::DATE . ')(?<time>' . self::TIME . ')?' . self::END . '/';
        if (preg_match($pattern, $text, $match) !== 1) {
            return null;
        }
        $day = substr($match['day'], 0, 10);
        if (!checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            return null;
        }

        $time = $match['time'] ?? '';
        $clock = '00:00:00';
        $offset = 0;
        if ($time !== '') {
            $clock = substr($time, 1, 5) . (substr($time, 6, 1) === ':' ? substr($time, 6, 3) : ':00');
            if (preg_match('/([+-])([0-9]{2}):?([0-9]{2})' . self::END . '/', $time, $zone) === 1) {
                $minutes = ((int) $zone[2]) * 60 + (int) $zone[3];
                $offset = $zone[1] === '-' ? -$minutes : $minutes;
            }
        }

        return ['day' => $day, 'clock' => $clock, 'offset' => $offset];
    }

    /**
     * The SQL condition "this trimmed text is a valid date or datetime text" (the regular expression part; the
     * calendar and the existence of the clock are decided by the conversion that follows, which gives NULL
     * for a day or a time that does not exist).
     */
    private static function sqlReadable(string $trimmed): string
    {
        return 'REGEXP_LIKE(' . $trimmed . ', ' . self::sqlLiteral('^' . self::DATE . '(' . self::TIME . ')?' . self::END) . ", 'c')";
    }

    private static function sqlInstant(string $trimmed): string
    {
        $normal = 'REPLACE(' . $trimmed . ", 'T', ' ')";
        $zone = 'REGEXP_SUBSTR(' . $normal . ', ' . self::sqlLiteral('[+-][0-9]{2}:?[0-9]{2}' . self::END) . ')';
        $clock = 'CAST(CONCAT(LEFT(' . $normal . ', 10), '
            . "' ', IF(LENGTH(" . $normal . ') > 10, CONCAT(SUBSTRING(' . $normal . ', 12, 5), IF(SUBSTRING(' . $normal . ", 17, 1) = ':', SUBSTRING(" . $normal . ", 17, 3), ':00')), '00:00:00')) AS DATETIME)";
        $minutes = 'IF(' . $zone . ' IS NULL, 0, IF(LEFT(' . $zone . ", 1) = '-', -1, 1) * (CAST(SUBSTRING(" . $zone . ', 2, 2) AS SIGNED) * 60 + CAST(RIGHT(' . $zone . ', 2) AS SIGNED)))';

        return 'CASE WHEN ' . self::sqlReadable($trimmed) . ' THEN DATE_ADD(' . $clock . ', INTERVAL (-1 * ' . $minutes . ') MINUTE) END';
    }
}
