<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\DataTable;
use App\Service\CMS\DataRevision\RecordContentRefReader;
use App\Service\CMS\DataService;

/**
 * Admin export of one generic-store table as JSON, CSV or Excel (xlsx).
 *
 * The rows are read in stable batches ({@see DataService::getDataInBatches()})
 * and written to the output as they arrive, so a table of any size is never held
 * in memory as a list of row arrays. The files are the same as the ones the
 * single read produced: JSON is the pretty-printed list of rows, CSV has one
 * header row with every column that appears in any row and one line per row.
 * Excel has the same header and rows as the CSV, in one sheet.
 *
 * A record written for known content carries its content reference (contract C19)
 * in the column {@see self::CONTENT_REF_COLUMN}: a row without one has no such key,
 * so CSV and Excel show an empty cell for it (the column exists as soon as one row
 * has a reference) and a table whose records all predate the reference is
 * exported exactly as before.
 */
final class DataTableExportService
{
    /** The export column of the content a record was written for (`data_rows.content_ref`). */
    public const CONTENT_REF_COLUMN = '_content_ref';

    /** Bytes a spool or an output buffer keeps in memory before PHP moves it to a temporary file. */
    private const MEMORY_LIMIT_BYTES = 4 * 1024 * 1024;

    /** Under Excel's 32,767 per cell, leaving room for characters Excel counts twice. */
    private const XLSX_CELL_MAX = 32000;
    private const XLSX_CUT_MARK = ' [cut: too long for Excel]';

    public function __construct(
        private readonly DataService $dataService,
        private readonly RecordContentRefReader $contentRefs,
    ) {
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
            $rows = [];
            foreach ($this->dataService->remapEntriesToInputNames($tableId, $batch) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $named = [];
                foreach ($row as $key => $value) {
                    $named[(string) $key] = $value;
                }
                $rows[] = $named;
            }

            yield $this->contentRefs->attach($rows, self::CONTENT_REF_COLUMN, false);
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
        $spooled = $this->spool($batches);
        if ($spooled === null) {
            return '';
        }
        [$headers, $spool] = $spooled;

        $out = $this->buffer();
        fputcsv($out, $headers, escape: '');
        foreach ($this->spooledRows($spool) as $row) {
            $line = [];
            foreach ($headers as $column) {
                $value = $row[$column] ?? null;
                $line[] = is_scalar($value) ? (string) $value : '';
            }
            fputcsv($out, $line, escape: '');
        }

        return $this->contents($out);
    }

    /**
     * Excel workbook (xlsx) with one sheet: the CSV's header and rows. Text
     * stays text (an id like `007` keeps its zeros, a value starting with `=`
     * is never a formula); only integer and decimal values become numbers. A
     * table without rows gives a sheet without rows.
     *
     * Written with ZipArchive: an xlsx file is a zip of a few XML parts, and
     * the export needs no styles, so no spreadsheet library is involved.
     *
     * @param iterable<array<array-key, mixed>> $batches
     */
    public function xlsx(iterable $batches): string
    {
        $sheet = $this->buffer();
        fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

        $spooled = $this->spool($batches);
        if ($spooled !== null) {
            [$headers, $spool] = $spooled;
            fwrite($sheet, $this->xlsxRow($headers));
            foreach ($this->spooledRows($spool) as $row) {
                $line = [];
                foreach ($headers as $column) {
                    $value = $row[$column] ?? null;
                    $line[] = is_scalar($value) ? $value : '';
                }
                fwrite($sheet, $this->xlsxRow($line));
            }
        }
        fwrite($sheet, '</sheetData></worksheet>');

        $file = tempnam(sys_get_temp_dir(), 'sh_xlsx_');
        if ($file === false) {
            throw new \RuntimeException('Could not create a temporary file for the export.');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($file, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the Excel file.');
            }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->contents($sheet));
            $zip->close();

            return (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * One worksheet row: numbers as numbers, everything else as inline text.
     *
     * @param list<bool|float|int|string> $values
     */
    private function xlsxRow(array $values): string
    {
        $cells = '';
        foreach ($values as $value) {
            if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $cells .= '<c><v>' . $value . '</v></c>';
                continue;
            }
            $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            // XML 1.0 has no place for most control characters; drop them.
            $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
            // Excel refuses a cell over 32,767 characters (it reports the file as damaged).
            if (mb_strlen($text, 'UTF-8') > self::XLSX_CELL_MAX) {
                $text = mb_substr($text, 0, self::XLSX_CELL_MAX - mb_strlen(self::XLSX_CUT_MARK, 'UTF-8'), 'UTF-8') . self::XLSX_CUT_MARK;
            }
            $cells .= '<c t="inlineStr"><is><t xml:space="preserve">'
                . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</t></is></c>';
        }

        return '<row>' . $cells . '</row>';
    }

    /**
     * Writes every row to a spool (memory first, then a temporary file) and
     * collects the header: every column that appears in any row, in the order
     * the columns first appear. Null when there are no rows.
     *
     * @param iterable<array<array-key, mixed>> $batches
     * @return array{0: list<string>, 1: resource}|null
     */
    private function spool(iterable $batches): ?array
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

            return null;
        }

        return [array_map('strval', array_keys($headers)), $spool];
    }

    /**
     * Reads the spooled rows back and closes the spool.
     *
     * @param resource $spool
     * @return \Generator<int, array<array-key, mixed>>
     */
    private function spooledRows($spool): \Generator
    {
        rewind($spool);
        while (($frame = fgets($spool)) !== false) {
            $row = json_decode($frame, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($row)) {
                yield $row;
            }
        }
        fclose($spool);
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
