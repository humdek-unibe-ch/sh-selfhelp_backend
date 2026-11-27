<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the content reference (contract C19) of records for the places that show
 * a record next to its data: the data browser and the exports.
 *
 * One query per {@see self::CHUNK} records, never one per record. A record written before
 * the reference existed (or only by jobs and imports) has none; it is shown as empty,
 * never guessed.
 */
final class RecordContentRefReader
{
    /** Records per query, so a large table never builds an oversized `IN (...)` list. */
    private const CHUNK = 1000;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<int> $recordIds
     * @return array<int, string> record id => reference; a record without one has no entry
     */
    public function refsOf(array $recordIds): array
    {
        $recordIds = array_values(array_unique(array_filter($recordIds, static fn (int $id): bool => $id > 0)));

        $refs = [];
        foreach (array_chunk($recordIds, self::CHUNK) as $chunk) {
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT id, content_ref FROM data_rows WHERE id IN (:ids) AND content_ref IS NOT NULL',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                $ref = $row['content_ref'] ?? null;
                if (is_numeric($id) && is_string($ref)) {
                    $refs[(int) $id] = $ref;
                }
            }
        }

        return $refs;
    }

    /**
     * The rows of the data store (each names its record in `record_id`) with the reference of the record under `$key`.
     * A row that already has `$key` (a data column of that name) is left alone: stored data is never overwritten.
     *
     * @param list<array<string, mixed>> $rows
     * @param bool $withEmpty true: a record without a reference gets `null`; false: it gets no key at all
     * @return list<array<string, mixed>>
     */
    public function attach(array $rows, string $key, bool $withEmpty): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['record_id'] ?? null;
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        $refs = $this->refsOf($ids);

        foreach ($rows as $index => $row) {
            if (array_key_exists($key, $row)) {
                continue;
            }
            $id = $row['record_id'] ?? null;
            $ref = is_numeric($id) ? ($refs[(int) $id] ?? null) : null;
            if ($ref !== null || $withEmpty) {
                $rows[$index][$key] = $ref;
            }
        }

        return $rows;
    }
}
