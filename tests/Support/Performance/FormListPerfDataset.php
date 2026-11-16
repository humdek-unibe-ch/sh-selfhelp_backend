<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Performance;

use Doctrine\DBAL\Connection;

/**
 * Deterministic generic-store datasets for the form-list performance proof.
 *
 *  - {@see QA_TABLE}: 100,000 records x 50 columns (5,000,000 cells). A list
 *    shows 8 of the 50 columns, so the other 42 must never be read.
 *  - {@see MILLION_TABLE}: 1,000,000 records x 8 columns (8,000,000 cells), the
 *    size the speed target names for a CMS table.
 *
 * Every value is a function of the record's position `n` (1..count), so two
 * seeds produce the same data and the proof can state exact counts: how many
 * records match a status, how many carry a rare word, how many one user owns.
 *
 * The data is written straight into the store with a few set-based statements,
 * outside the application's write path (it would take hours), and is meant for
 * an ISOLATED database only: {@see seed()} refuses to run anywhere but in a
 * database whose name ends in `_test_form_list_perf`. Tests of the proof read it
 * and never change it (their writes roll back with the test).
 */
final class FormListPerfDataset
{
    public const DATABASE_SUFFIX = '_test_form_list_perf';

    public const QA_TABLE = 'perf_form_list_qa';
    public const QA_ROWS = 100_000;
    public const QA_COLUMNS = 50;
    /** Key prefix of the 50 columns: `section_91000NN`. */
    public const QA_KEY_PREFIX = 'section_91000';

    public const MILLION_TABLE = 'perf_form_list_million';
    public const MILLION_ROWS = 1_000_000;
    public const MILLION_COLUMNS = 8;
    /** Key prefix of the 8 columns: `section_92000NN`. */
    public const MILLION_KEY_PREFIX = 'section_92000';

    /** The heavy participant (owns 10% of the records of each table). */
    public const HEAVY_OWNER_EMAIL = 'qa.user@selfhelp.test';
    /** The light participant (owns every 5,000th record: 20 and 200 records). */
    public const LIGHT_OWNER_EMAIL = 'qa.editor@selfhelp.test';

    private const CHUNK_ROWS = 50_000;

    /** @var array<int, string> position => value type, shared by both tables where it makes sense */
    private const QA_TYPES = [3 => 'number', 4 => 'date', 5 => 'boolean', 6 => 'datetime'];

    /** @var array<int, string> */
    private const MILLION_TYPES = [4 => 'number', 5 => 'date', 6 => 'boolean'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Id of a seeded table, or null when it does not exist yet.
     */
    public function tableId(string $name): ?int
    {
        $id = $this->db->fetchOne('SELECT id FROM data_tables WHERE `name` = :n', ['n' => $name]);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Key of the column at a position (1-based) of a seeded table.
     */
    public static function key(string $table, int $position): string
    {
        $prefix = $table === self::QA_TABLE ? self::QA_KEY_PREFIX : self::MILLION_KEY_PREFIX;

        return sprintf('%s%02d', $prefix, $position);
    }

    /**
     * Create whatever is missing. Safe to run again: a complete table is left alone.
     *
     * @param callable(string): void $log
     */
    public function seed(callable $log): void
    {
        $database = self::stringOf($this->db->fetchOne('SELECT DATABASE()'));
        if (!str_ends_with($database, self::DATABASE_SUFFIX)) {
            throw new \RuntimeException(sprintf('Refusing to seed "%s": the performance datasets only go into a database whose name ends in "%s".', $database, self::DATABASE_SUFFIX));
        }

        $this->db->executeStatement('SET SESSION cte_max_recursion_depth = 2000000');
        $this->db->executeStatement('SET SESSION foreign_key_checks = 0');
        $this->db->executeStatement('SET SESSION unique_checks = 0');

        $this->seedTable(self::QA_TABLE, self::QA_ROWS, self::QA_COLUMNS, self::QA_KEY_PREFIX, self::QA_TYPES, $log);
        $this->seedTable(self::MILLION_TABLE, self::MILLION_ROWS, self::MILLION_COLUMNS, self::MILLION_KEY_PREFIX, self::MILLION_TYPES, $log);

        $this->db->executeStatement('SET SESSION foreign_key_checks = 1');
        $this->db->executeStatement('SET SESSION unique_checks = 1');

        $log('ANALYZE TABLE data_rows, data_cols, data_cells');
        $this->db->executeQuery('ANALYZE TABLE data_tables, data_rows, data_cols, data_cells')->fetchAllAssociative();
    }

    /**
     * @param array<int, string> $types
     * @param callable(string): void $log
     */
    private function seedTable(string $name, int $rows, int $columns, string $keyPrefix, array $types, callable $log): void
    {
        $existing = $this->tableId($name);
        if ($existing !== null) {
            $present = self::intOf($this->db->fetchOne('SELECT COUNT(*) FROM data_rows WHERE id_data_tables = :t', ['t' => $existing]));
            $cells = self::intOf($this->db->fetchOne(
                'SELECT COUNT(*) FROM data_cells c JOIN data_rows r ON r.id = c.id_data_rows WHERE r.id_data_tables = :t',
                ['t' => $existing]
            ));
            if ($present === $rows && $cells === $rows * $columns) {
                $log(sprintf('%s: complete (%d rows, %d cells)', $name, $present, $cells));

                return;
            }
            // A seed that was interrupted: start this table again.
            $log(sprintf('%s: incomplete (%d rows, %d cells), removing it', $name, $present, $cells));
            $this->db->executeStatement('DELETE FROM data_tables WHERE id = :t', ['t' => $existing]);
        }

        $started = hrtime(true);
        $this->db->executeStatement(
            'INSERT INTO data_tables (`name`, `timestamp`, display_name) VALUES (:n, UTC_TIMESTAMP(), :n)',
            ['n' => $name]
        );
        $tableId = (int) $this->db->lastInsertId();

        for ($position = 1; $position <= $columns; ++$position) {
            $type = $types[$position] ?? null;
            $typeId = $type === null ? null : $this->lookupId('dataColValueTypes', $type);
            $this->db->executeStatement(
                'INSERT INTO data_cols (id_data_tables, field_key, display_name, id_value_type) VALUES (:t, :k, :d, :v)',
                ['t' => $tableId, 'k' => sprintf('%s%02d', $keyPrefix, $position), 'd' => sprintf('Column %02d', $position), 'v' => $typeId]
            );
        }

        $heavy = $this->userId(self::HEAVY_OWNER_EMAIL);
        $light = $this->userId(self::LIGHT_OWNER_EMAIL);
        $finished = $this->lookupId('actionTriggerTypes', 'finished');
        $deleted = $this->lookupId('actionTriggerTypes', 'deleted');

        // Records. n = position 1..rows; the owner, the age and the trigger type follow n.
        // Every 10th record belongs to the heavy participant, every 5,000th (n % 5000 = 1) to the
        // light one, the rest to 5,000 other user ids (the column has no foreign key); every 100th
        // record is soft deleted so that readers have to leave it out.
        $this->db->executeStatement(sprintf(
            'INSERT INTO data_rows (id_data_tables, `timestamp`, id_users, id_action_trigger_types) '
            . 'WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < %d) '
            . "SELECT %d, TIMESTAMP('2024-01-01 00:00:00') + INTERVAL n MINUTE, "
            . 'CASE WHEN n %% 10 = 0 THEN %d WHEN n %% 5000 = 1 THEN %d ELSE 100000 + (n %% 5000) END, '
            . 'CASE WHEN n %% 100 = 0 THEN %d ELSE %d END '
            . 'FROM seq ORDER BY n',
            $rows,
            $tableId,
            $heavy,
            $light,
            $deleted,
            $finished,
        ));
        $firstId = self::intOf($this->db->fetchOne('SELECT MIN(id) FROM data_rows WHERE id_data_tables = :t', ['t' => $tableId]));
        $lastId = self::intOf($this->db->fetchOne('SELECT MAX(id) FROM data_rows WHERE id_data_tables = :t', ['t' => $tableId]));
        $log(sprintf('%s: %d rows written (ids %d..%d)', $name, $rows, $firstId, $lastId));

        $base = $firstId - 1;
        $valueExpression = $name === self::QA_TABLE ? $this->qaValue($base) : $this->millionValue($base);

        for ($from = $firstId; $from <= $lastId; $from += self::CHUNK_ROWS) {
            $to = min($lastId, $from + self::CHUNK_ROWS - 1);
            $this->db->executeStatement(sprintf(
                'INSERT INTO data_cells (id_data_rows, id_data_cols, id_languages, `value`) '
                . 'SELECT r.id, c.id, 1, %s FROM data_rows r JOIN data_cols c ON c.id_data_tables = r.id_data_tables '
                . 'WHERE r.id_data_tables = %d AND r.id BETWEEN %d AND %d',
                $valueExpression,
                $tableId,
                $from,
                $to,
            ));
            $log(sprintf('%s: cells for rows %d..%d (%.0f s)', $name, $from - $base, $to - $base, (hrtime(true) - $started) / 1e9));
        }

        $log(sprintf('%s: done in %.0f s', $name, (hrtime(true) - $started) / 1e9));
    }

    /**
     * SQL expression of the cell value of the QA table; `n` is the record's position, `p` the column's.
     */
    private function qaValue(int $base): string
    {
        $n = sprintf('(r.id - %d)', $base);
        $p = 'CAST(RIGHT(c.field_key, 2) AS UNSIGNED)';

        return 'CASE ' . $p
            . " WHEN 1 THEN CONCAT('Entry ', $n, ' ', ELT(1 + ($n % 8), 'alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel'))"
            . " WHEN 2 THEN ELT(1 + ($n % 4), 'open', 'done', 'archived', 'blocked')"
            . " WHEN 3 THEN CAST($n % 100 AS CHAR)"
            . " WHEN 4 THEN DATE_FORMAT(DATE_ADD('2025-01-01', INTERVAL ($n % 730) DAY), '%Y-%m-%d')"
            . " WHEN 5 THEN CAST($n % 2 AS CHAR)"
            . " WHEN 6 THEN DATE_FORMAT(TIMESTAMP('2025-01-01 00:00:00') + INTERVAL $n MINUTE, '%Y-%m-%d %H:%i:%s')"
            . " ELSE CONCAT('filler ', $p, ' row ', $n, ' ', REPEAT('x', 20 + (($n + $p) % 30))) END";
    }

    /**
     * SQL expression of the cell value of the million-row table.
     *
     *  1 title (text)        `News item <n> about <topic>`; topic `sleep` is every 10th record
     *  2 category (text)     `cat-00`..`cat-19`
     *  3 status (text)       open | done | archived | blocked
     *  4 amount (number)     0.00 .. 999.99
     *  5 published_on (date) 2024-01-01 .. 2025-12-30
     *  6 featured (boolean)  1 for every 7th record
     *  7 description (text)  about 60 characters
     *  8 note (text)         `note <n % 997>`, plus ` quartz` for every 20,011th record (a rare word)
     */
    private function millionValue(int $base): string
    {
        $n = sprintf('(r.id - %d)', $base);
        $p = 'CAST(RIGHT(c.field_key, 2) AS UNSIGNED)';

        return 'CASE ' . $p
            . " WHEN 1 THEN CONCAT('News item ', $n, ' about ', ELT(1 + ($n % 10), 'sleep', 'stress', 'focus', 'mood', 'diet', 'sport', 'work', 'family', 'study', 'travel'))"
            . " WHEN 2 THEN CONCAT('cat-', LPAD($n % 20, 2, '0'))"
            . " WHEN 3 THEN ELT(1 + ($n % 4), 'open', 'done', 'archived', 'blocked')"
            . " WHEN 4 THEN CAST(ROUND(($n % 100000) / 100, 2) AS CHAR)"
            . " WHEN 5 THEN DATE_FORMAT(DATE_ADD('2024-01-01', INTERVAL ($n % 729) DAY), '%Y-%m-%d')"
            . " WHEN 6 THEN CAST(IF($n % 7 = 0, 1, 0) AS CHAR)"
            . " WHEN 7 THEN CONCAT('Description for item ', $n, ' ', REPEAT('lorem ipsum ', 3 + ($n % 4)))"
            . " ELSE CONCAT('note ', $n % 997, IF($n % 20011 = 0, ' quartz', '')) END";
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function lookupId(string $typeCode, string $lookupCode): int
    {
        $id = $this->db->fetchOne('SELECT id FROM lookups WHERE type_code = :t AND lookup_code = :c', ['t' => $typeCode, 'c' => $lookupCode]);
        if (!is_numeric($id)) {
            throw new \LogicException(sprintf('Lookup %s/%s is not seeded.', $typeCode, $lookupCode));
        }

        return (int) $id;
    }

    private function userId(string $email): int
    {
        $id = $this->db->fetchOne('SELECT id FROM users WHERE email = :e', ['e' => $email]);
        if (!is_numeric($id)) {
            throw new \LogicException(sprintf('User %s does not exist; reset the isolated database first.', $email));
        }

        return (int) $id;
    }
}
