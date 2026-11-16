<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\DataTable;
use App\Service\CMS\DataService;

/**
 * Admin export of one generic-store table as JSON or CSV.
 *
 * The rows are read in stable batches ({@see DataService::getDataInBatches()})
 * and written to the output as they arrive, so a table of any size is never held
 * in memory as a list of row arrays. The files are the same as the ones the
 * single read produced: JSON is the pretty-printed list of rows, CSV has one
 * header row with every column that appears in any row and one line per row.
 */
final class DataTableExportService
{
    /** Bytes a spool or an output buffer keeps in memory before PHP moves it to a temporary file. */
    private const MEMORY_LIMIT_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly DataService $dataService)
    {
    }

    /**
     * The rows the caller may export, in batches. The columns carry the current
     * human input name instead of the immutable `section_<id>` key (issue #56).
     *
     * @param bool $fullAccess a role with delete rights on the table exports every user's rows; anyone else only the rows of the users of their groups
     * @param int|null $userId restrict a full-access export to one user's rows
     * @return \Generator<int, array<array-key, mixed>>
     */
    public function batches(DataTable $table, int $currentUserId, bool $fullAccess, ?int $userId, bool $excludeDeleted, int $languageId, int $batchSize = 500): \Generator
    {
        $tableId = (int) $table->getId();
        $source = $fullAccess
            ? $this->dataService->getDataInBatches($tableId, $userId, $excludeDeleted, $languageId, $batchSize)
            : $this->dataService->getDataInBatchesWithUserGroupFilter($tableId, $currentUserId, $excludeDeleted, $languageId, $batchSize);

        foreach ($source as $batch) {
            yield $this->dataService->remapEntriesToInputNames($tableId, $batch);
        }
    }

    /**
     * The pretty-printed JSON list of the rows.
     *
     * @param iterable<array<array-key, mixed>> $batches
     */
    public function json(iterable $batches): string
    {
        $out = $this->buffer();
        $first = true;
        foreach ($batches as $batch) {
            foreach ($batch as $row) {
                $text = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                // A row sits one level deeper inside the list than it is written alone.
                fwrite($out, ($first ? "[\n" : ",\n") . '    ' . str_replace("\n", "\n    ", $text));
                $first = false;
            }
        }
        fwrite($out, $first ? '[]' : "\n]");

        return $this->contents($out);
    }

    /**
     * CSV of the rows: the header is every column that appears in any row, in the
     * order the columns first appear; a row without a cell has an empty one. A
     * table without rows gives an empty file.
     *
     * The header is only known after the last row, so the rows are spooled
     * (memory first, then a temporary file) and written once the header is.
     *
     * @param iterable<array<array-key, mixed>> $batches
     */
    public function csv(iterable $batches): string
    {
        $spool = $this->buffer();
        $headers = [];
        $count = 0;
        foreach ($batches as $batch) {
            foreach ($batch as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (array_keys($row) as $key) {
                    $headers[(string) $key] = true;
                }
                // One row per line: JSON never writes a raw line break.
                fwrite($spool, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
                ++$count;
            }
        }
        if ($count === 0) {
            fclose($spool);

            return '';
        }

        $headers = array_keys($headers);
        $out = $this->buffer();
        fputcsv($out, $headers, escape: '');

        rewind($spool);
        while (($frame = fgets($spool)) !== false) {
            $row = json_decode($frame, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($row)) {
                continue;
            }
            $line = [];
            foreach ($headers as $column) {
                $value = $row[$column] ?? null;
                $line[] = is_scalar($value) ? (string) $value : '';
            }
            fputcsv($out, $line, escape: '');
        }
        fclose($spool);

        return $this->contents($out);
    }

    /**
     * @return resource
     */
    private function buffer()
    {
        $handle = fopen('php://temp/maxmemory:' . self::MEMORY_LIMIT_BYTES, 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary buffer for the export.');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function contents($handle): string
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }
}
