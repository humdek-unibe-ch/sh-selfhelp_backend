<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataCol;
use App\Entity\DataTable;
use App\Entity\Section;
use App\Entity\User;
use App\Service\ACL\ACLService;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataColumnService;
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
    private DataColumnService $columns;
    private DataTableFactory $tables;
    private PageSectionFactory $pages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->service(DataTableService::class);
        $this->dataService = $this->service(DataService::class);
        $this->columns = $this->service(DataColumnService::class);
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

    /**
     * Regression for issue #56 label provenance (now the `id_display_name_source`
     * lookups FK): curating a label marks the column `manual` so submissions can
     * never overwrite it, and CLEARING the label must revert to `auto` (NULL FK)
     * so a later submission re-derives the human label instead of the column
     * keeping a NULL display_name and falling back to the opaque storage key.
     */
    public function testCuratingColumnLabelMarksManualThenClearingRevertsToAuto(): void
    {
        // Seed one column via a submission; a fresh column is `auto` (NULL FK).
        $this->tables->addRow('qa_dts_label', ['qa_label_field' => 'v'], $this->userId());
        $this->em->clear();

        self::assertFalse(
            $this->columnFor('qa_dts_label', 'qa_label_field')->isDisplayNameManual(),
            'A freshly created column starts as auto provenance.',
        );

        // Curate the label -> manual provenance (FK -> the `manual` lookup row).
        self::assertTrue(
            $this->service->updateColumnDisplayName('qa_dts_label', 'qa_label_field', 'Custom Label'),
        );
        $this->em->clear();

        $column = $this->columnFor('qa_dts_label', 'qa_label_field');
        self::assertSame('Custom Label', $column->getDisplayName());
        self::assertTrue($column->isDisplayNameManual(), 'A curated label must be marked manual.');
        self::assertSame(LookupService::DATA_COL_DISPLAY_NAME_SOURCE_MANUAL, $column->getDisplayNameSourceCode());

        // Clear the label -> back to auto (NULL FK).
        self::assertTrue(
            $this->service->updateColumnDisplayName('qa_dts_label', 'qa_label_field', null),
        );
        $this->em->clear();

        $column = $this->columnFor('qa_dts_label', 'qa_label_field');
        self::assertNull($column->getDisplayName());
        self::assertFalse($column->isDisplayNameManual(), 'Clearing the label must revert provenance to auto.');
        self::assertSame(LookupService::DATA_COL_DISPLAY_NAME_SOURCE_AUTO, $column->getDisplayNameSourceCode());
    }

    /**
     * Issue #56 table lock: curating a data table's label from the Data browser
     * marks it `manual` and the form section's `name` field can no longer
     * overwrite it on save (the auto sync becomes a no-op). The lock state is
     * exposed through getDataTableStats() and getFormSectionTableInfo().
     */
    public function testCuratingTableLabelLocksItAndBlocksFormSectionSync(): void
    {
        $section = $this->pages->createSection('qa_dts_locktbl', 'form-record');
        $table = $this->service->createDataTableForFormSection($section);
        self::assertInstanceOf(DataTable::class, $table);
        $tableName = (string) $section->getId();

        // An auto table is not locked and the form-section sync applies.
        self::assertFalse($this->service->getDataTableStats($tableName)['locked']);
        self::assertTrue($this->service->updateDataTableDisplayName($section, 'Auto Name'));
        $this->em->clear();
        self::assertSame('Auto Name', $this->dataService->getDataTableByName($tableName)?->getDisplayName());

        // Curate manually -> locked.
        self::assertTrue($this->service->setDataTableDisplayNameCurated($tableName, 'Manual Name'));
        $this->em->clear();

        $stats = $this->service->getDataTableStats($tableName);
        self::assertSame('Manual Name', $stats['displayName']);
        self::assertTrue($stats['locked'], 'A curated table label must be marked locked.');

        // A later form-section save must NOT overwrite the manual label.
        $section = $this->em->getRepository(Section::class)->find((int) $tableName);
        self::assertInstanceOf(Section::class, $section);
        self::assertFalse(
            $this->service->updateDataTableDisplayName($section, 'Auto Push'),
            'A locked table rejects the auto form-section sync.',
        );
        $this->em->clear();
        self::assertSame('Manual Name', $this->dataService->getDataTableByName($tableName)?->getDisplayName());

        // Reset to auto -> unlocked again.
        self::assertTrue($this->service->setDataTableDisplayNameCurated($tableName, null));
        $this->em->clear();
        self::assertFalse(
            $this->service->getDataTableStats($tableName)['locked'],
            'Resetting a table label must clear the lock.',
        );
    }

    /**
     * Issue #56 CMS surface: a form section exposes its underlying data table
     * (storage name == section id, current label, lock state) so the inspector
     * can warn + deep link; a section with no data table returns null.
     */
    public function testGetFormSectionTableInfoExposesLockState(): void
    {
        $section = $this->pages->createSection('qa_dts_info', 'form-record');
        $sectionId = (int) $section->getId();

        $beforeTable = $this->service->getFormSectionTableInfo($sectionId);
        self::assertNull($beforeTable, 'No data table yet -> null.');

        $this->service->createDataTableForFormSection($section);
        $this->service->setDataTableDisplayNameCurated((string) $sectionId, 'Inspector Label');
        $this->em->clear();

        $info = $this->service->getFormSectionTableInfo($sectionId);
        self::assertNotNull($info);
        self::assertSame((string) $sectionId, $info['name']);
        self::assertSame('Inspector Label', $info['display_name']);
        self::assertTrue($info['locked']);
    }

    /**
     * Issue #56 input-rename-on-save: renaming a form input propagates its new
     * name to the auto display_name of the immutable `section_<id>` column, but
     * a manually-locked column is never touched.
     */
    public function testRenameAutoColumnByFieldKeyUpdatesAutoButSkipsManual(): void
    {
        // Seed two columns via a submission; both start auto.
        $this->tables->addRow('qa_dts_colrename', ['section_910' => 'v', 'section_911' => 'w'], $this->userId());
        $this->em->clear();

        // Auto column: the rename propagates.
        $affected = $this->columns->renameAutoColumnByFieldKey('section_910', 'Renamed Auto');
        self::assertNotSame([], $affected, 'An auto column rename must report its table.');
        $this->em->clear();
        self::assertSame('Renamed Auto', $this->columnFor('qa_dts_colrename', 'section_910')->getDisplayName());

        // Lock the second column, then attempt a rename: it must be ignored.
        self::assertTrue($this->service->updateColumnDisplayName('qa_dts_colrename', 'section_911', 'Locked Label'));
        $this->em->clear();
        $this->columns->renameAutoColumnByFieldKey('section_911', 'Should Not Apply');
        $this->em->clear();
        self::assertSame(
            'Locked Label',
            $this->columnFor('qa_dts_colrename', 'section_911')->getDisplayName(),
            'A manually locked column must never be overwritten by an input rename.',
        );
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

    private function columnFor(string $tableName, string $fieldKey): DataCol
    {
        $table = $this->dataService->getDataTableByName($tableName);
        self::assertInstanceOf(DataTable::class, $table);

        $column = $this->em->getRepository(DataCol::class)
            ->findOneBy(['dataTable' => $table, 'fieldKey' => $fieldKey]);
        self::assertInstanceOf(DataCol::class, $column);

        return $column;
    }

    private function userId(string $email = QaBaselineFixture::QA_USER_EMAIL): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }
}
