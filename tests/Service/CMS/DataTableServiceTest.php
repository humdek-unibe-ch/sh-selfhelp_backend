<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataTable;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\CMS\DataTableService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\Factories\PageSectionFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for {@see DataTableService}: form-section table creation
 * (and the admin auto-grant it triggers), column inspection/deletion, table
 * deletion, and permission-scoped listing.
 */
final class DataTableServiceTest extends QaKernelTestCase
{
    private DataTableService $service;
    private DataService $dataService;
    private DataTableFactory $tables;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->service(DataTableService::class);
        $this->dataService = $this->service(DataService::class);
        $this->tables = new DataTableFactory($this->em, $this->dataService);
        $this->pages = new PageSectionFactory(
            $this->em,
            $this->service(ACLService::class),
            $this->service(LookupService::class),
            $this->service(CacheService::class),
        );

        $this->service(CacheService::class)->withCategory(CacheService::CATEGORY_DATA_TABLES)->invalidateCategory();
    }

    public function testCreateDataTableForFormSectionIsIdempotentAndAdminGranted(): void
    {
        $section = $this->pages->createSection('qa_dts_form_section', 'form-record');

        $table = $this->service->createDataTableForFormSection($section);
        self::assertInstanceOf(DataTable::class, $table);
        self::assertSame((string) $section->getId(), $table->getName());

        // Idempotent: a second call returns the same row, not a duplicate.
        $again = $this->service->createDataTableForFormSection($section);
        self::assertInstanceOf(DataTable::class, $again);
        self::assertSame($table->getId(), $again->getId());

        // The admin-access listener auto-granted the admin role full CRUD.
        self::assertTrue($this->service->canAccessDataTable(
            $this->userId(QaBaselineFixture::QA_ADMIN_EMAIL),
            (int) $table->getId(),
            DataAccessSecurityService::PERMISSION_READ,
        ));
    }

    public function testColumnInspectionReflectsSeededRow(): void
    {
        $this->tables->addRow('qa_dts_columns', ['qa_field' => 'v', 'qa_extra' => 'e'], $this->userId());
        // saveData created the data_cols via direct inserts; drop the identity map
        // so getColumnsNames re-reads the now-complete column collection.
        $this->em->clear();

        $names = $this->service->getColumnsNames('qa_dts_columns');
        self::assertIsArray($names);
        self::assertContains('qa_field', $this->flattenColumnNames($names));
        self::assertContains('qa_extra', $this->flattenColumnNames($names));
    }

    public function testDeleteColumnsReturnsFalseForUnknownTable(): void
    {
        self::assertFalse($this->service->deleteColumns('qa_dts_no_such_table', ['qa_field']));
    }

    public function testDeleteColumnsReturnsZeroForEmptyColumnList(): void
    {
        $this->tables->addRow('qa_dts_emptycols', ['qa_field' => 'v'], $this->userId());

        self::assertSame(0, $this->service->deleteColumns('qa_dts_emptycols', []));
    }

    /**
     * The actual column-removal side effect (qa_field disappears and does not
     * reappear) is asserted through the clean per-request HTTP path in
     * {@see \App\Tests\Controller\Api\V1\Admin\AdminDataControllerTest::testDeleteColumnsRemovesSelectedColumns()}.
     * At the service level the shared EntityManager identity map (the DataTable's
     * dataCols collection populated by the seeding saveData) confounds a direct
     * DB assertion, so we only assert the contract branches here.
     */
    public function testDeleteColumnsReportsAtLeastOneRemovedForKnownColumn(): void
    {
        $this->tables->addRow('qa_dts_delcol', ['qa_field' => 'v', 'qa_extra' => 'e'], $this->userId());
        $this->em->clear();

        $deleted = $this->service->deleteColumns('qa_dts_delcol', ['qa_extra']);

        self::assertNotFalse($deleted);
        self::assertGreaterThanOrEqual(1, $deleted, 'A matching column must be reported as removed.');
    }

    public function testDeleteDataTableRemovesIt(): void
    {
        $table = $this->tables->createTable('qa_dts_drop');
        self::assertInstanceOf(DataTable::class, $this->dataService->getDataTableByName('qa_dts_drop'));

        self::assertTrue($this->service->deleteDataTable('qa_dts_drop'));
        self::assertNull($this->dataService->getDataTableByName('qa_dts_drop'));
    }

    public function testDeleteUnknownDataTableReturnsFalse(): void
    {
        self::assertFalse($this->service->deleteDataTable('qa_dts_does_not_exist'));
    }

    public function testFilteredDataTablesIncludeAdminGrantedTable(): void
    {
        $table = $this->tables->createTable('qa_dts_filtered');

        $adminTables = $this->service->getFilteredDataTables($this->userId(QaBaselineFixture::QA_ADMIN_EMAIL));
        $names = array_map(
            static fn (array $row): string => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
            $adminTables
        );

        self::assertContains((string) $table->getName(), $names, 'Admin must see the auto-granted qa table.');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * getColumnsNames may return a list of strings or a list of {name:...} rows.
     *
     * @param array<array-key, mixed> $names
     * @return list<string>
     */
    private function flattenColumnNames(array $names): array
    {
        $flat = [];
        foreach ($names as $entry) {
            if (is_string($entry)) {
                $flat[] = $entry;
            } elseif (is_array($entry) && isset($entry['name']) && is_scalar($entry['name'])) {
                $flat[] = (string) $entry['name'];
            }
        }

        return $flat;
    }

    private function userId(string $email = QaBaselineFixture::QA_USER_EMAIL): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
