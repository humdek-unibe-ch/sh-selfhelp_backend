<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Factories;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Writes generic-store fixtures straight into `data_tables`, `data_cols`,
 * `data_rows` and `data_cells` with exact control over column types, owners,
 * timestamps, languages and trigger types. Query tests need data that the
 * normal save path would never produce (a text cell in a number column, two
 * languages for one cell, a back-dated row), and they must not depend on the
 * write path they are not testing.
 *
 * Everything is created inside the DAMA transaction and rolled back.
 */
final class DataGridFactory
{
    /** @var array<string, int> */
    private array $columnIds = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Create a table with typed columns.
     *
     * @param array<string, string> $columns field_key => value type (`text`, `number`, `date`, `datetime`, `boolean`)
     */
    public function table(string $name, array $columns): int
    {
        $this->db->executeStatement(
            'INSERT INTO data_tables (`name`, `timestamp`, display_name) VALUES (:name, UTC_TIMESTAMP(), :display)',
            ['name' => $name, 'display' => $name]
        );
        $tableId = (int) $this->db->lastInsertId();

        foreach ($columns as $key => $type) {
            $this->column($tableId, $key, $type);
        }

        return $tableId;
    }

    public function column(int $tableId, string $key, string $type = 'text'): int
    {
        $typeId = $type === 'text' ? null : $this->lookupId('dataColValueTypes', $type);
        $this->db->executeStatement(
            'INSERT INTO data_cols (id_data_tables, field_key, display_name, id_value_type) VALUES (:t, :k, :d, :v)',
            ['t' => $tableId, 'k' => $key, 'd' => $key, 'v' => $typeId],
            ['t' => ParameterType::INTEGER, 'k' => ParameterType::STRING, 'd' => ParameterType::STRING, 'v' => $typeId === null ? ParameterType::NULL : ParameterType::INTEGER]
        );

        return $this->columnIds[$tableId . ':' . $key] = (int) $this->db->lastInsertId();
    }

    /**
     * Add a record.
     *
     * @param array<string, string|array<int, string>> $cells field_key => value in the default language, or language id => value
     * @param string|null $timestamp UTC `Y-m-d H:i:s`; now when null
     * @param string $trigger `actionTriggerTypes` lookup code of the record (`finished`, `deleted`, ...); NULL when empty
     */
    public function row(int $tableId, ?int $userId, array $cells, ?string $timestamp = null, string $trigger = 'finished'): int
    {
        $triggerId = $trigger === '' ? null : $this->lookupId('actionTriggerTypes', $trigger);
        $this->db->executeStatement(
            'INSERT INTO data_rows (id_data_tables, `timestamp`, id_users, id_action_trigger_types) VALUES (:t, COALESCE(:ts, UTC_TIMESTAMP()), :u, :a)',
            ['t' => $tableId, 'ts' => $timestamp, 'u' => $userId, 'a' => $triggerId],
            [
                't' => ParameterType::INTEGER,
                'ts' => $timestamp === null ? ParameterType::NULL : ParameterType::STRING,
                'u' => $userId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'a' => $triggerId === null ? ParameterType::NULL : ParameterType::INTEGER,
            ]
        );
        $rowId = (int) $this->db->lastInsertId();

        foreach ($cells as $key => $value) {
            $columnId = $this->columnIds[$tableId . ':' . $key] ?? null;
            if ($columnId === null) {
                throw new \LogicException(sprintf('Unknown column "%s" for table %d.', $key, $tableId));
            }
            $perLanguage = is_array($value) ? $value : [1 => $value];
            foreach ($perLanguage as $languageId => $text) {
                $this->db->executeStatement(
                    'INSERT INTO data_cells (id_data_rows, id_data_cols, id_languages, `value`) VALUES (:r, :c, :l, :v)',
                    ['r' => $rowId, 'c' => $columnId, 'l' => $languageId, 'v' => $text],
                    ['r' => ParameterType::INTEGER, 'c' => ParameterType::INTEGER, 'l' => ParameterType::INTEGER, 'v' => ParameterType::STRING]
                );
            }
        }

        return $rowId;
    }

    private function lookupId(string $typeCode, string $lookupCode): int
    {
        $id = $this->db->fetchOne('SELECT id FROM lookups WHERE type_code = :t AND lookup_code = :c', ['t' => $typeCode, 'c' => $lookupCode]);
        if (!is_numeric($id)) {
            throw new \LogicException(sprintf('Lookup %s/%s is not seeded.', $typeCode, $lookupCode));
        }

        return (int) $id;
    }
}
