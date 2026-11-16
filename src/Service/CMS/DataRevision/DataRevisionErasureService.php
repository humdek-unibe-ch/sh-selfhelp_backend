<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

use App\Service\CMS\FormFileUploadService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Explicit erasure of record history: the one place that removes revisions,
 * their changes and their values, and names the stored files they referenced.
 *
 * History is kept until someone erases data on purpose. These are the three
 * deliberate erasures: a user's data ({@see eraseOwnedBy()}), a whole data table
 * ({@see eraseTable()}) and the columns an administrator deletes
 * ({@see eraseColumns()}). Every method runs INSIDE the caller's transaction and
 * deletes explicitly, in foreign-key order, instead of leaning on the cascades:
 * an erasure must keep working if a constraint is ever relaxed, and it has to
 * count what it removed. Nothing here touches the files themselves; the caller
 * hands {@see ErasedHistory::$fileKeys} to
 * {@see FormFileUploadService::deleteUnreferencedFiles()} after the commit.
 *
 * Soft-deleting a record or changing a value is NOT an erasure: those keep the
 * history and its files (see {@see DataRevisionService}).
 *
 * Content only ever lives in the record it belongs to (values are never shared
 * across records), so erasing a record's revisions cannot damage another
 * record's history. Removing a user who merely EDITED someone else's record keeps
 * that record's history and only detaches the actor link (the person goes, the
 * content stays), the same policy as the audit rows.
 */
final class DataRevisionErasureService
{
    /** Matches any stored value that names a form file (a key or a JSON list of keys). */
    private const FILE_MARKER = '%' . FormFileUploadService::UPLOAD_DIR . '%';

    /** Candidate value ids are deleted in slices so one statement stays small. */
    private const VALUE_SLICE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Erase the history of every record the user owns, and detach the user from
     * history they only wrote on other people's records.
     *
     * Call it BEFORE the user's rows are deleted: the file keys are read from the
     * rows' current cells and from their history.
     */
    public function eraseOwnedBy(int $userId): ErasedHistory
    {
        $db = $this->connection();
        $scope = ['scope' => $userId];

        $fileKeys = $this->fileKeys([
            'SELECT c.value FROM data_cells c JOIN data_rows r ON r.id = c.id_data_rows'
                . ' WHERE r.id_users = :scope AND c.value LIKE :marker',
            'SELECT v.content FROM data_revision_values v JOIN data_rows r ON r.id = v.id_data_rows'
                . ' WHERE r.id_users = :scope AND v.content LIKE :marker',
        ], $scope);

        $changes = $this->count($db->executeStatement(
            'DELETE ch FROM data_revision_changes ch'
                . ' JOIN data_revisions rv ON rv.id = ch.id_data_revisions'
                . ' JOIN data_rows r ON r.id = rv.id_data_rows WHERE r.id_users = :scope',
            $scope,
        ));
        $values = $this->count($db->executeStatement(
            'DELETE v FROM data_revision_values v JOIN data_rows r ON r.id = v.id_data_rows WHERE r.id_users = :scope',
            $scope,
        ));
        $revisions = $this->count($db->executeStatement(
            'DELETE rv FROM data_revisions rv JOIN data_rows r ON r.id = rv.id_data_rows WHERE r.id_users = :scope',
            $scope,
        ));

        // History the user wrote on records that stay: the content stays, the person goes.
        $detached = $this->count($db->executeStatement(
            'UPDATE data_revisions SET id_users = NULL WHERE id_users = :scope',
            $scope,
        ));
        $detached += $this->count($db->executeStatement(
            'UPDATE data_revisions SET id_impersonating_users = NULL WHERE id_impersonating_users = :scope',
            $scope,
        ));

        return new ErasedHistory($revisions, $changes, $values, $detached, $fileKeys);
    }

    /**
     * Erase the history of every record of a data table. Call it before the table
     * (and so its rows) is deleted.
     */
    public function eraseTable(int $tableId): ErasedHistory
    {
        $db = $this->connection();
        $scope = ['scope' => $tableId];

        $fileKeys = $this->fileKeys([
            'SELECT c.value FROM data_cells c JOIN data_rows r ON r.id = c.id_data_rows'
                . ' WHERE r.id_data_tables = :scope AND c.value LIKE :marker',
            'SELECT v.content FROM data_revision_values v JOIN data_rows r ON r.id = v.id_data_rows'
                . ' WHERE r.id_data_tables = :scope AND v.content LIKE :marker',
        ], $scope);

        $changes = $this->count($db->executeStatement(
            'DELETE ch FROM data_revision_changes ch'
                . ' JOIN data_revisions rv ON rv.id = ch.id_data_revisions WHERE rv.id_data_tables = :scope',
            $scope,
        ));
        $values = $this->count($db->executeStatement(
            'DELETE v FROM data_revision_values v JOIN data_rows r ON r.id = v.id_data_rows WHERE r.id_data_tables = :scope',
            $scope,
        ));
        $revisions = $this->count($db->executeStatement(
            'DELETE FROM data_revisions WHERE id_data_tables = :scope',
            $scope,
        ));

        return new ErasedHistory($revisions, $changes, $values, 0, $fileKeys);
    }

    /**
     * Erase what the history holds for the given columns of a table (addressed by
     * their immutable field key): their changes (checkpoint entries included) and
     * the values nothing else refers to. The revision headers stay as non-content
     * audit metadata. Call it before the columns (and so their cells) are deleted.
     *
     * @param list<string> $fieldKeys
     */
    public function eraseColumns(int $tableId, array $fieldKeys): ErasedHistory
    {
        if ($fieldKeys === []) {
            return new ErasedHistory();
        }

        $db = $this->connection();
        $params = ['scope' => $tableId, 'fieldKeys' => $fieldKeys];
        $types = ['fieldKeys' => ArrayParameterType::STRING];

        $fileKeys = $this->fileKeys([
            'SELECT c.value FROM data_cells c JOIN data_cols dc ON dc.id = c.id_data_cols'
                . ' WHERE dc.id_data_tables = :scope AND dc.field_key IN (:fieldKeys) AND c.value LIKE :marker',
            'SELECT v.content FROM data_revision_changes ch'
                . ' JOIN data_revisions rv ON rv.id = ch.id_data_revisions'
                . ' JOIN data_revision_values v ON v.id = ch.id_before_values'
                . ' WHERE rv.id_data_tables = :scope AND ch.field_key IN (:fieldKeys) AND v.content LIKE :marker',
            'SELECT v.content FROM data_revision_changes ch'
                . ' JOIN data_revisions rv ON rv.id = ch.id_data_revisions'
                . ' JOIN data_revision_values v ON v.id = ch.id_after_values'
                . ' WHERE rv.id_data_tables = :scope AND ch.field_key IN (:fieldKeys) AND v.content LIKE :marker',
        ], $params, $types);

        // The values these changes name are the only candidates for removal.
        $candidateIds = [];
        foreach (['id_before_values', 'id_after_values'] as $column) {
            $ids = $db->fetchFirstColumn(
                "SELECT DISTINCT ch.$column FROM data_revision_changes ch"
                    . ' JOIN data_revisions rv ON rv.id = ch.id_data_revisions'
                    . " WHERE rv.id_data_tables = :scope AND ch.field_key IN (:fieldKeys) AND ch.$column IS NOT NULL",
                $params,
                $types,
            );
            foreach ($ids as $id) {
                if (is_numeric($id)) {
                    $candidateIds[(int) $id] = true;
                }
            }
        }

        $changes = $this->count($db->executeStatement(
            'DELETE ch FROM data_revision_changes ch'
                . ' JOIN data_revisions rv ON rv.id = ch.id_data_revisions'
                . ' WHERE rv.id_data_tables = :scope AND ch.field_key IN (:fieldKeys)',
            $params,
            $types,
        ));

        // A value that another change still names (it cannot be one of these
        // columns', but the check is what makes the delete safe) stays.
        $values = 0;
        foreach (array_chunk(array_keys($candidateIds), self::VALUE_SLICE) as $slice) {
            $values += $this->count($db->executeStatement(
                'DELETE v FROM data_revision_values v WHERE v.id IN (:ids)'
                    . ' AND NOT EXISTS (SELECT 1 FROM data_revision_changes c WHERE c.id_before_values = v.id)'
                    . ' AND NOT EXISTS (SELECT 1 FROM data_revision_changes c WHERE c.id_after_values = v.id)',
                ['ids' => $slice],
                ['ids' => ArrayParameterType::INTEGER],
            ));
        }

        return new ErasedHistory(0, $changes, $values, 0, $fileKeys);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * The distinct file keys named by the values the queries return.
     *
     * @param list<string> $queries each selects one value column and takes `:marker` plus the given parameters
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType> $types
     * @return list<string>
     */
    private function fileKeys(array $queries, array $params, array $types = []): array
    {
        $params['marker'] = self::FILE_MARKER;
        $keys = [];
        foreach ($queries as $sql) {
            foreach ($this->connection()->iterateColumn($sql, $params, $types) as $value) {
                if (!is_string($value)) {
                    continue;
                }
                foreach (FormFileUploadService::keysIn($value) as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return array_map('strval', array_keys($keys));
    }

    private function count(int|string $affected): int
    {
        return (int) $affected;
    }
}
