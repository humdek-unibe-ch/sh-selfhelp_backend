<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Factories;

use App\Entity\DataRow;
use App\Entity\DataTable;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds `qa_`-prefixed data tables and rows through the REAL persistence path
 * ({@see DataService::saveData()}), so:
 *   - the {@see \App\EventListener\DataTableAdminAccessListener} auto-grants the
 *     admin role full CRUD on the new table (qa.admin can then read/delete it),
 *   - rows land in `data_rows`/`data_cols`/`data_cells` exactly as a real form
 *     submission would, so the `get_data_table_filtered` stored procedure reads
 *     them back during the test.
 *
 * Everything is created inside the DAMA transaction and rolled back at tearDown.
 * Tables created with no {@see \App\Entity\Action} attached run action
 * orchestration as a no-op on save (nothing is scheduled, no outbound).
 */
final class DataTableFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DataService $dataService,
    ) {
    }

    /**
     * Create (or reuse) an empty `qa_`-named data table. Persisting it triggers
     * the admin-access listener, so qa.admin immediately has full CRUD on it.
     */
    public function createTable(string $name = 'qa_data_table'): DataTable
    {
        $existing = $this->em->getRepository(DataTable::class)->findOneBy(['name' => $name]);
        if ($existing instanceof DataTable) {
            return $existing;
        }

        $table = new DataTable();
        $table->setName($name);
        $table->setDisplayName('QA data table');
        $table->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->persist($table);
        $this->em->flush();

        return $table;
    }

    /**
     * Append a row to the table through the real save path and return the
     * record id. Pass an explicit user id so ownership-based reads/deletes are
     * deterministic; defaults to a fixed QA sentinel id.
     *
     * @param array<string, scalar|null> $fields field name => value
     */
    public function addRow(string $tableName, array $fields, int $userId): int
    {
        $payload = $fields;
        $payload['id_users'] = $userId;
        $payload['trigger_type'] = LookupService::ACTION_TRIGGER_TYPES_FINISHED;

        $recordId = $this->dataService->saveData(
            $tableName,
            $payload,
            LookupService::TRANSACTION_BY_BY_SYSTEM,
            null,
            false,
        );

        if ($recordId === false) {
            throw new \RuntimeException(sprintf('DataTableFactory could not seed a row into "%s".', $tableName));
        }

        return $recordId;
    }

    /**
     * Convenience: an empty-but-existing table plus one QA row owned by $userId.
     * Returns [DataTable, recordId].
     *
     * @return array{0: DataTable, 1: int}
     */
    public function createTableWithRow(string $name, int $userId, string $fieldValue = 'qa-value'): array
    {
        $recordId = $this->addRow($name, ['qa_field' => $fieldValue], $userId);
        $table = $this->em->getRepository(DataTable::class)->findOneBy(['name' => $name]);
        if (!$table instanceof DataTable) {
            throw new \RuntimeException(sprintf('DataTableFactory: table "%s" missing after seeding a row.', $name));
        }

        return [$table, $recordId];
    }

    /**
     * Re-read a row entity by id (post-write assertions on soft-delete etc.).
     */
    public function findRow(int $recordId): ?DataRow
    {
        return $this->em->getRepository(DataRow::class)->find($recordId);
    }
}
