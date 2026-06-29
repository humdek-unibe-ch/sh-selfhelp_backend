<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Controller\Api\V1\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataTable;
use App\Entity\User;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\QaWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * P0 coverage for the admin Data-Management read/write API
 * ({@see \App\Controller\Api\V1\Admin\AdminDataController}).
 *
 * These are the highest-risk admin write paths: deleting records, dropping
 * tables, and removing columns. The success paths run as qa.admin, who has
 * full CRUD on every table because {@see \App\EventListener\DataTableAdminAccessListener}
 * grants the admin role full access when a data table is persisted. The
 * permission matrix (admin vs non-admin vs anonymous) lives in
 * {@see AdminDataPermissionTest}.
 *
 * Every test asserts the standard envelope, the key response fields, and — for
 * writes — the DB side effect (soft delete, removed column/table) plus the
 * cache-invalidation effect (a follow-up read no longer returns the row).
 */
final class AdminDataControllerTest extends QaWebTestCase
{
    private const BASE = '/cms-api/v1/admin/data';

    private EntityManagerInterface $em;
    private DataTableFactory $dataTables;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $dataService = $container->get(DataService::class);
        self::assertInstanceOf(DataService::class, $dataService);
        $this->dataTables = new DataTableFactory($this->em, $dataService);
    }

    // -- Reads --------------------------------------------------------------

    public function testGetDataTablesReturnsAccessibleTablesForAdmin(): void
    {
        $table = $this->dataTables->createTable('qa_data_list_table');

        $envelope = $this->jsonRequest('GET', self::BASE . '/tables', null, $this->loginAsQaAdmin());
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('dataTables', $data);
        self::assertIsArray($data['dataTables']);

        $names = array_column($data['dataTables'], 'name');
        self::assertContains('qa_data_list_table', $names, 'Admin must see the qa data table it has a grant on.');

        // Contract shape the frontend Data-Management view consumes.
        $row = $this->firstWithName($data['dataTables'], 'qa_data_list_table');
        foreach (['id', 'name', 'displayName', 'created', 'crud'] as $key) {
            self::assertArrayHasKey($key, $row, "Data-table row must expose '{$key}'.");
        }
        self::assertSame((int) $table->getId(), $this->asInt($row['id'] ?? null));
    }

    public function testGetDataReturnsRowsForAccessibleTable(): void
    {
        [$table, $recordId] = $this->dataTables->createTableWithRow('qa_data_rows_table', $this->qaUserId(), 'first answer');

        $envelope = $this->jsonRequest(
            'GET',
            self::BASE . '?table_name=' . $table->getName(),
            null,
            $this->loginAsQaAdmin(),
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('rows', $data);
        self::assertIsArray($data['rows']);
        self::assertNotEmpty($data['rows'], 'Admin full-table read must return the seeded row.');

        self::assertContains($recordId, $this->recordIds($data['rows']), 'The seeded record must appear in the admin read.');
    }

    public function testGetDataMissingTableNameReturns400(): void
    {
        $envelope = $this->jsonRequest('GET', self::BASE, null, $this->loginAsQaAdmin());
        $this->assertEnvelope400($envelope);
    }

    public function testGetDataUnknownTableReturns404(): void
    {
        $envelope = $this->jsonRequest('GET', self::BASE . '?table_name=qa_data_missing_table', null, $this->loginAsQaAdmin());
        $this->assertEnvelope404($envelope);
    }

    public function testGetColumnsReturnsTableColumns(): void
    {
        $table = $this->dataTables->createTableWithRow('qa_data_columns_table', $this->qaUserId())[0];

        $envelope = $this->jsonRequest(
            'GET',
            self::BASE . '/tables/' . $table->getName() . '/columns',
            null,
            $this->loginAsQaAdmin(),
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('columns', $data);
        self::assertIsArray($data['columns']);
        // Issue #56: each column exposes the immutable storage key (`fieldKey`)
        // and the mutable label (`displayName`), no longer a single `name`.
        $first = $data['columns'][0] ?? null;
        self::assertIsArray($first);
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('fieldKey', $first);
        self::assertArrayHasKey('displayName', $first);
        $fieldKeys = array_column($data['columns'], 'fieldKey');
        self::assertContains('qa_field', $fieldKeys, 'The field written by the factory must be a column (keyed by field_key).');
    }

    public function testGetColumnNamesIncludesSystemAndCustomColumns(): void
    {
        $table = $this->dataTables->createTableWithRow('qa_data_colnames_table', $this->qaUserId())[0];

        $envelope = $this->jsonRequest(
            'GET',
            self::BASE . '/tables/' . $table->getName() . '/column-names',
            null,
            $this->loginAsQaAdmin(),
        );
        $data = $this->assertEnvelopeSuccess($envelope);

        self::assertArrayHasKey('columnNames', $data);
        self::assertIsArray($data['columnNames']);
        // Service always prepends the fixed system columns, then appends custom ones.
        self::assertContains('record_id', $data['columnNames']);
        self::assertContains('id_users', $data['columnNames']);
        self::assertContains('qa_field', $data['columnNames']);
    }

    // -- Exports (raw blob, NOT the envelope) -------------------------------

    public function testExportTableAsCsvReturnsAttachmentWithHeaderRow(): void
    {
        $table = $this->dataTables->createTableWithRow('qa_data_export_csv', $this->qaUserId(), 'csv answer')[0];

        $response = $this->rawRequest(
            'GET',
            self::BASE . '/tables/' . $table->getName() . '/export?format=csv',
            $this->loginAsQaAdmin(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $body = (string) $response->getContent();
        // Header row is the union of column names; the seeded value appears in a data row.
        self::assertStringContainsString('qa_field', $body, 'CSV header must include the table column.');
        self::assertStringContainsString('csv answer', $body, 'CSV body must include the seeded cell value.');
    }

    public function testExportTableAsJsonReturnsRawArrayNotEnvelope(): void
    {
        $table = $this->dataTables->createTableWithRow('qa_data_export_json', $this->qaUserId(), 'json answer')[0];

        $response = $this->rawRequest(
            'GET',
            self::BASE . '/tables/' . $table->getName() . '/export?format=json',
            $this->loginAsQaAdmin(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $decoded = json_decode((string) $response->getContent(), true);
        // The body is a raw list of rows, NOT the {status,data,...} envelope.
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('status', $decoded, 'Export JSON must not be wrapped in the API envelope.');
        self::assertNotEmpty($decoded, 'Export JSON must contain the seeded row.');
    }

    public function testBulkExportReturnsZipWithOneEntryPerTable(): void
    {
        $a = $this->dataTables->createTableWithRow('qa_data_bulk_a', $this->qaUserId(), 'a value')[0];
        $b = $this->dataTables->createTableWithRow('qa_data_bulk_b', $this->qaUserId(), 'b value')[0];

        $response = $this->rawRequest(
            'POST',
            self::BASE . '/tables/bulk-export',
            $this->loginAsQaAdmin(),
            ['table_names' => [$a->getName(), $b->getName()], 'format' => 'csv'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/zip', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        // Inspect the ZIP: it must hold one CSV entry per requested table.
        $entries = $this->zipEntryNames((string) $response->getContent());
        self::assertCount(2, $entries, 'Bulk export must contain one file per table.');
        foreach ($entries as $name) {
            self::assertStringEndsWith('.csv', $name);
        }
    }

    public function testBulkExportUnknownTableReturns404Envelope(): void
    {
        // Error responses DO still use the standard envelope.
        $envelope = $this->jsonRequest(
            'POST',
            self::BASE . '/tables/bulk-export',
            ['table_names' => ['qa_data_missing_table'], 'format' => 'csv'],
            $this->loginAsQaAdmin(),
        );
        $this->assertEnvelope404($envelope);
    }

    public function testBulkExportRejectsBodyMissingFormat(): void
    {
        // `format` is required by the bulk-export request schema; omitting it
        // must fail JSON-schema validation (400) before any table is read.
        $table = $this->dataTables->createTable('qa_data_bulk_no_format');

        $envelope = $this->jsonRequest(
            'POST',
            self::BASE . '/tables/bulk-export',
            ['table_names' => [$table->getName()]],
            $this->loginAsQaAdmin(),
        );

        $this->assertEnvelope400($envelope);
    }

    public function testBulkExportRejectsEmptyTableNames(): void
    {
        // `table_names` is required and must hold at least one entry
        // (`minItems: 1`); an empty list must fail validation (400).
        $envelope = $this->jsonRequest(
            'POST',
            self::BASE . '/tables/bulk-export',
            ['table_names' => [], 'format' => 'csv'],
            $this->loginAsQaAdmin(),
        );

        $this->assertEnvelope400($envelope);
    }

    public function testBulkExportRejectsInvalidFormat(): void
    {
        // `format` is constrained to the csv/json enum; anything else must fail
        // validation (400) rather than silently defaulting.
        $table = $this->dataTables->createTable('qa_data_bulk_bad_format');

        $envelope = $this->jsonRequest(
            'POST',
            self::BASE . '/tables/bulk-export',
            ['table_names' => [$table->getName()], 'format' => 'xml'],
            $this->loginAsQaAdmin(),
        );

        $this->assertEnvelope400($envelope);
    }

    // -- Writes -------------------------------------------------------------

    public function testDeleteRecordSoftDeletesRowAndInvalidatesReadCache(): void
    {
        [$table, $recordId] = $this->dataTables->createTableWithRow('qa_data_delete_record_table', $this->qaUserId());
        $token = $this->loginAsQaAdmin();

        // Warm the admin read cache first so we prove invalidation, not just a cold read.
        $before = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '?table_name=' . $table->getName(), null, $token)
        );
        self::assertContains($recordId, $this->recordIds($before['rows']));

        // Admin deletes another user's record (own_entries_only=false).
        $deleteEnvelope = $this->jsonRequest(
            'DELETE',
            self::BASE . '/records/' . $recordId . '?table_name=' . $table->getName() . '&own_entries_only=false',
            null,
            $token,
        );
        $deleteData = $this->assertEnvelopeSuccess($deleteEnvelope);
        self::assertTrue((bool) ($deleteData['deleted'] ?? false), 'Delete must report success.');

        // DB side effect: soft delete (trigger type flipped to "deleted"), row still present.
        $this->em->clear();
        $row = $this->dataTables->findRow($recordId);
        self::assertNotNull($row, 'Soft delete must keep the physical row.');
        $lookups = self::getContainer()->get(LookupService::class);
        self::assertInstanceOf(LookupService::class, $lookups);
        $deletedTriggerId = (int) $lookups
            ->getLookupIdByValue(LookupService::ACTION_TRIGGER_TYPES, LookupService::ACTION_TRIGGER_TYPES_DELETED);
        self::assertSame($deletedTriggerId, (int) $row->getIdActionTriggerTypes(), 'Row must carry the deleted trigger type.');

        // Cache-invalidation effect: the default read (exclude_deleted) no longer returns it.
        $after = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '?table_name=' . $table->getName(), null, $token)
        );
        self::assertNotContains(
            $recordId,
            $this->recordIds($after['rows']),
            'After soft delete the record must disappear from the excluded-deleted read (cache invalidated).'
        );
    }

    public function testDeleteColumnsRemovesSelectedColumns(): void
    {
        $table = $this->dataTables->createTableWithRow('qa_data_delete_cols_table', $this->qaUserId())[0];
        $token = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest(
            'DELETE',
            self::BASE . '/tables/' . $table->getName() . '/columns',
            ['columns' => ['qa_field']],
            $token,
        );
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertSame(1, $this->coerceInt($data['deleted_column_count'] ?? -1), 'Exactly one column must be deleted.');

        // DB side effect: the column is gone.
        $columns = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '/tables/' . $table->getName() . '/columns', null, $token)
        );
        self::assertNotContains('qa_field', array_column($this->asList($columns['columns']), 'fieldKey'), 'Deleted column must not reappear.');
    }

    /**
     * Issue #56: relabeling a column changes ONLY the human label
     * (`display_name`); the immutable `field_key` and the stored cells stay put,
     * so renaming never forks a column or splits historical data.
     */
    public function testUpdateColumnDisplayNameRenamesLabelButKeepsStorageKeyAndData(): void
    {
        $table = $this->dataTables->createTableWithRow('qa_data_relabel_table', $this->qaUserId(), 'qa-original')[0];
        $token = $this->loginAsQaAdmin();
        $columnsUrl = self::BASE . '/tables/' . $table->getName() . '/columns';

        // Capture the column id before relabeling.
        $before = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $columnsUrl, null, $token));
        $beforeCols = $this->asList($before['columns']);
        $qaCol = null;
        foreach ($beforeCols as $col) {
            if (is_array($col) && ($col['fieldKey'] ?? null) === 'qa_field') {
                $qaCol = $col;
                break;
            }
        }
        self::assertIsArray($qaCol, 'The seeded qa_field column must exist.');
        $originalId = $qaCol['id'] ?? null;

        // Relabel qa_field -> "Daily mood".
        $envelope = $this->jsonRequest(
            'PATCH',
            $columnsUrl . '/display-name',
            ['fieldKey' => 'qa_field', 'displayName' => 'Daily mood'],
            $token,
        );
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertTrue((bool) ($data['updated'] ?? false), 'The relabel must report updated=true.');

        // The field_key + id are unchanged; only displayName moved.
        $after = $this->assertEnvelopeSuccess($this->jsonRequest('GET', $columnsUrl, null, $token));
        $afterCols = $this->asList($after['columns']);
        $relabeled = null;
        foreach ($afterCols as $col) {
            if (is_array($col) && ($col['fieldKey'] ?? null) === 'qa_field') {
                $relabeled = $col;
                break;
            }
        }
        self::assertIsArray($relabeled, 'field_key qa_field must still exist after relabel.');
        self::assertSame($originalId, $relabeled['id'] ?? null, 'Relabeling must not create a new column id.');
        self::assertSame('Daily mood', $relabeled['displayName'] ?? null, 'displayName must reflect the new label.');
        self::assertSameSize($beforeCols, $afterCols, 'Relabeling must not add or remove columns.');

        // The stored cell is still readable by the stable key (data not split).
        $rows = $this->assertEnvelopeSuccess(
            $this->jsonRequest('GET', self::BASE . '?table_name=' . $table->getName(), null, $token)
        );
        $rowList = $this->asList($rows['rows']);
        self::assertCount(1, $rowList, 'The original row must still be present after relabel.');
        $firstRow = $rowList[0] ?? null;
        self::assertIsArray($firstRow);
        self::assertSame('qa-original', $firstRow['qa_field'] ?? null, 'The value must still be keyed by field_key qa_field.');
    }

    public function testDeleteDataTableRemovesTable(): void
    {
        $table = $this->dataTables->createTable('qa_data_drop_table');
        $token = $this->loginAsQaAdmin();

        $envelope = $this->jsonRequest('DELETE', self::BASE . '/tables/' . $table->getName(), null, $token);
        $data = $this->assertEnvelopeSuccess($envelope);
        self::assertTrue((bool) ($data['deleted'] ?? false));

        // DB side effect: the table no longer exists.
        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(DataTable::class)->findOneBy(['name' => 'qa_data_drop_table']),
            'Dropped data table must be gone from the DB.'
        );
    }

    public function testDeleteRecordMissingTableNameReturns400(): void
    {
        $envelope = $this->jsonRequest('DELETE', self::BASE . '/records/1', null, $this->loginAsQaAdmin());
        $this->assertEnvelope400($envelope);
    }

    public function testDeleteUnknownDataTableReturns404(): void
    {
        $envelope = $this->jsonRequest('DELETE', self::BASE . '/tables/qa_data_missing_table', null, $this->loginAsQaAdmin());
        $this->assertEnvelope404($envelope);
    }

    // -- helpers ------------------------------------------------------------

    private function qaUserId(): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }

    /**
     * Issue a request and return the raw Response (headers + body) without
     * decoding an envelope — the export endpoints stream raw CSV/JSON/ZIP blobs.
     *
     * @param array<string, mixed>|null $body
     */
    private function rawRequest(string $method, string $uri, string $token, ?array $body = null): Response
    {
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Authorization' => 'Bearer ' . $token,
            ],
            $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null,
        );

        return $this->client->getResponse();
    }

    /**
     * Read the entry names out of an in-memory ZIP blob.
     *
     * @return list<string>
     */
    private function zipEntryNames(string $zipBlob): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qa_zip_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $zipBlob);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp) === true, 'Bulk export must be a readable ZIP.');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $names[] = (string) $stat['name'];
            }
        }
        $zip->close();
        unlink($tmp);

        return $names;
    }

    /**
     * @param array<mixed> $rows
     * @return array<string, mixed>
     */
    private function firstWithName(array $rows, string $name): array
    {
        foreach ($rows as $rawRow) {
            $row = $this->asArray($rawRow);
            if (($row['name'] ?? null) === $name) {
                return $row;
            }
        }

        self::fail("No data-table row named '{$name}' in response.");
    }

    /**
     * Extract the `record_id` of every data row from a decoded admin read.
     *
     * @return list<int>
     */
    private function recordIds(mixed $rows): array
    {
        $ids = [];
        foreach ($this->asList($rows) as $rawRow) {
            $row = $this->asArray($rawRow);
            $ids[] = $this->coerceInt($row['record_id'] ?? 0);
        }

        return $ids;
    }
}
