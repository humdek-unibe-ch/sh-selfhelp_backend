<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\CMS;

use App\Entity\DataCol;
use App\Entity\DataTable;
use App\Exception\ServiceException;
use App\Service\Core\BaseService;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves data-source submission keys to immutable {@see DataCol} columns.
 *
 * Column identity is the immutable, opaque `field_key`; the mutable
 * `display_name` is a human label only. The caller is responsible for deriving
 * a STABLE key per source (core CMS forms => `section_<input section id>`;
 * SurveyJS => `question.name`) and passing the human label separately — this
 * service treats the key as an opaque literal and never re-derives it. It:
 *   - validates keys (opaque literal, dotted SurveyJS keys allowed) and rejects
 *     reserved / `__`-prefixed metadata keys so user fields never collide with
 *     system/projection/interpolation keys;
 *   - resolves a batch of keys in one indexed `field_key IN (...)` query;
 *   - creates missing columns with a concurrency-safe upsert
 *     (`INSERT IGNORE` keyed on the unique `(id_data_tables, field_key)`) so a
 *     concurrent submission can never close the Doctrine unit of work mid-save;
 *   - auto-populates `display_name` from incoming labels, but never overwrites a
 *     manually curated label (`display_name_source = manual`).
 */
class DataColumnService extends BaseService
{
    /**
     * Opaque field-key grammar: must start with a letter, then letters, digits,
     * underscores or dots (dots are part of the literal SurveyJS key, never an
     * object path), up to 255 characters total. Core form keys are
     * `section_<id>` and SurveyJS keys are `question.name`, both of which match.
     */
    private const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_.]{0,254}$/';

    /**
     * Keys that must never become dynamic data columns because they collide with
     * row metadata, stored-procedure projection columns, or interpolation scope
     * words. Comparison is an exact string match.
     *
     * @var list<string>
     */
    public const RESERVED_KEYS = [
        'id',
        'id_data_tables',
        'id_data_rows',
        'id_data_cols',
        'id_languages',
        'id_users',
        'id_action_trigger_types',
        'record_id',
        'entry_date',
        'user_name',
        'user_code',
        'triggerType',
        'trigger_type',
        'language_id',
        'deleted',
        'record',
        'scope',
        'user',
        'metadata',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * True when the key is reserved: any `__`-prefixed meta key (SurveyJS uses
     * e.g. `__editor`) or one of {@see self::RESERVED_KEYS}.
     */
    public function isReservedKey(string $key): bool
    {
        if (str_starts_with($key, '__')) {
            return true;
        }

        return in_array($key, self::RESERVED_KEYS, true);
    }

    /**
     * Drop reserved/metadata keys so they never become dynamic columns.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function filterFieldData(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if ($this->isReservedKey((string) $key)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Validate that every non-reserved key in a submission is a legal field key.
     * Call this BEFORE opening the save transaction so invalid input surfaces as
     * a clean 400 instead of a wrapped 500.
     *
     * @param array<string, mixed> $data
     * @throws ServiceException
     */
    public function assertValidFieldData(array $data): void
    {
        foreach (array_keys($this->filterFieldData($data)) as $key) {
            $this->assertValidFieldKey((string) $key);
        }
    }

    /**
     * Resolve the given field keys to managed {@see DataCol} entities, creating
     * any that do not exist yet. Returns a `field_key => DataCol` map.
     *
     * @param list<string> $fieldKeys
     * @param array<string, string|null> $labels field_key => incoming display label
     * @return array<string, DataCol>
     * @throws ServiceException
     */
    public function resolveColumns(DataTable $dataTable, array $fieldKeys, array $labels = []): array
    {
        $keys = [];
        foreach ($fieldKeys as $key) {
            $key = (string) $key;
            $this->assertValidFieldKey($key);
            $keys[$key] = true;
        }
        $keys = array_keys($keys);

        if ($keys === []) {
            return [];
        }

        $columns = $this->fetchColumns($dataTable, $keys);

        $missing = array_values(array_diff($keys, array_keys($columns)));
        if ($missing !== []) {
            $this->insertMissingColumns((int) $dataTable->getId(), $missing, $labels);
            // Re-read so the rows just inserted via DBAL become managed entities.
            $columns = $this->fetchColumns($dataTable, $keys);
        }

        $this->applyLabels($columns, $labels);

        return $columns;
    }

    /**
     * Fetch existing columns for the given keys as a `field_key => DataCol` map.
     *
     * @param list<string> $keys
     * @return array<string, DataCol>
     */
    private function fetchColumns(DataTable $dataTable, array $keys): array
    {
        /** @var list<DataCol> $cols */
        $cols = $this->entityManager->getRepository(DataCol::class)
            ->createQueryBuilder('c')
            ->where('c.dataTable = :table')
            ->andWhere('c.fieldKey IN (:keys)')
            ->setParameter('table', $dataTable)
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($cols as $col) {
            $map[(string) $col->getFieldKey()] = $col;
        }

        return $map;
    }

    /**
     * Concurrency-safe creation of missing columns. `INSERT IGNORE` against the
     * unique `(id_data_tables, field_key)` key makes a racing creator a no-op
     * instead of a fatal unique-violation that would close the EntityManager.
     *
     * @param list<string> $missing
     * @param array<string, string|null> $labels
     */
    private function insertMissingColumns(int $tableId, array $missing, array $labels): void
    {
        $conn = $this->entityManager->getConnection();

        foreach ($missing as $key) {
            $label = $labels[$key] ?? null;
            $label = is_string($label) && $label !== '' ? $label : null;

            // id_display_name_source is left NULL, which the entity interprets as
            // the default `auto` provenance (issue #56). It only becomes the
            // `manual` lookup FK once an admin curates the label.
            $conn->executeStatement(
                'INSERT IGNORE INTO data_cols (id_data_tables, field_key, display_name, id_display_name_source) '
                . 'VALUES (:tableId, :fieldKey, :displayName, NULL)',
                [
                    'tableId' => $tableId,
                    'fieldKey' => $key,
                    'displayName' => $label,
                ],
                [
                    'tableId' => ParameterType::INTEGER,
                    'fieldKey' => ParameterType::STRING,
                    'displayName' => $label === null ? ParameterType::NULL : ParameterType::STRING,
                ]
            );
        }
    }

    /**
     * Refresh `display_name` from incoming labels for auto-curated columns only.
     * Manually curated labels (`display_name_source = manual`) are never touched.
     *
     * @param array<string, DataCol> $columns
     * @param array<string, string|null> $labels
     */
    private function applyLabels(array $columns, array $labels): void
    {
        foreach ($columns as $key => $column) {
            if (!array_key_exists($key, $labels)) {
                continue;
            }

            $label = $labels[$key];
            if (!is_string($label) || $label === '') {
                continue;
            }

            if ($column->isDisplayNameManual()) {
                continue;
            }

            if ($column->getDisplayName() !== $label) {
                // Stays `auto` provenance (NULL FK); we never overwrite a manually
                // curated label above, so no need to touch the source here.
                $column->setDisplayName($label);
            }
        }
    }

    /**
     * Propagate a renamed core form input to its data column label: refresh the
     * auto-curated display_name of the column addressed by the immutable
     * field_key (`section_<input id>`). A no-op when the column does not exist
     * yet (no submission has created it) or when an admin has manually locked the
     * label (`display_name_source = manual`) (issue #56).
     *
     * Returns the affected data table ids so the caller can bust the
     * variable-picker / column caches for those tables.
     *
     * @return list<int>
     */
    public function renameAutoColumnByFieldKey(string $fieldKey, ?string $displayName): array
    {
        $label = is_string($displayName) && $displayName !== '' ? $displayName : null;
        if ($label === null) {
            return [];
        }

        /** @var list<DataCol> $columns */
        $columns = $this->entityManager->getRepository(DataCol::class)
            ->findBy(['fieldKey' => $fieldKey]);

        $affected = [];
        foreach ($columns as $column) {
            if ($column->isDisplayNameManual()) {
                continue;
            }
            if ($column->getDisplayName() !== $label) {
                $column->setDisplayName($label);
            }
            $table = $column->getDataTable();
            if ($table !== null && $table->getId() !== null) {
                $affected[(int) $table->getId()] = true;
            }
        }

        if ($affected !== []) {
            $this->entityManager->flush();
        }

        return array_keys($affected);
    }

    /**
     * @throws ServiceException when the key is malformed or reserved.
     */
    private function assertValidFieldKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ServiceException(
                sprintf('Invalid data column key "%s". Keys must match %s.', $key, self::KEY_PATTERN),
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($this->isReservedKey($key)) {
            throw new ServiceException(
                sprintf('Reserved data column key "%s" cannot be used as a field.', $key),
                Response::HTTP_BAD_REQUEST
            );
        }
    }
}
