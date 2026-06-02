<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Service\CMS;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataRow;
use App\Entity\User;
use App\Service\Cache\Core\CacheService;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * P0 integration coverage for {@see DataService} — the form data write/read core
 * used by both the public form controller and the admin data screens.
 *
 * Exercises the real save → store → stored-procedure-read round-trip inside the
 * DAMA transaction: insert, update-based-on, soft delete (and its effect on
 * `excludeDeleted` reads), owner-scoped reads, and cache invalidation after a
 * write. The data-tables cache generation is bumped in setUp because DAMA reuses
 * auto-increment table ids across runs while Redis persists.
 */
final class DataServiceTest extends QaKernelTestCase
{
    private DataService $dataService;
    private DataTableFactory $tables;
    private CacheService $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataService = $this->service(DataService::class);
        $this->cache = $this->service(CacheService::class);
        $this->tables = new DataTableFactory($this->em, $this->dataService);

        // Deterministic reads: drop any stale data-table cache keyed by a reused id.
        $this->cache->withCategory(CacheService::CATEGORY_DATA_TABLES)->invalidateCategory();
    }

    public function testSaveDataInsertsARowReadableByGetData(): void
    {
        [$table, $recordId] = $this->tables->createTableWithRow('qa_ds_insert', $this->userId(), 'qa-inserted');

        $rows = $this->dataService->getData((int) $table->getId(), '', false, null, false);

        self::assertCount(1, $rows);
        self::assertSame('qa-inserted', $this->fieldValue($this->asArray($rows[0]), 'qa_field'));

        // The persisted DataRow is real.
        self::assertInstanceOf(DataRow::class, $this->tables->findRow($recordId));
    }

    public function testUpdateBasedOnUpdatesExistingRowWithoutCreatingANewOne(): void
    {
        $userId = $this->userId();
        $table = $this->tables->createTable('qa_ds_update');
        $this->tables->addRow('qa_ds_update', ['qa_key' => 'k1', 'qa_field' => 'original'], $userId);

        // Re-save matching the same qa_key -> updates in place.
        $this->dataService->saveData(
            'qa_ds_update',
            ['id_users' => $userId, 'trigger_type' => LookupService::ACTION_TRIGGER_TYPES_FINISHED, 'qa_key' => 'k1', 'qa_field' => 'updated'],
            LookupService::TRANSACTION_BY_BY_SYSTEM,
            ['qa_key' => 'k1'],
            false,
        );

        $rows = $this->dataService->getData((int) $table->getId(), '', false, null, false);
        self::assertCount(1, $rows, 'update_based_on must update in place, not append.');
        self::assertSame('updated', $this->fieldValue($this->asArray($rows[0]), 'qa_field'));
    }

    public function testDeleteDataSoftDeletesAndHidesFromExcludeDeletedReads(): void
    {
        [$table, $recordId] = $this->tables->createTableWithRow('qa_ds_delete', $this->userId(), 'qa-doomed');

        $ok = $this->dataService->deleteData($recordId, false);
        self::assertTrue($ok);

        $visible = $this->dataService->getData((int) $table->getId(), '', false, null, false, true);
        self::assertCount(0, $visible, 'Soft-deleted row must be hidden from excludeDeleted reads.');

        $withDeleted = $this->dataService->getData((int) $table->getId(), '', false, null, false, false);
        self::assertCount(1, $withDeleted, 'Soft-deleted row must still be present when not excluding deleted.');
    }

    public function testOwnEntriesOnlyReadReturnsOnlyCallersRows(): void
    {
        $owner = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $other = $this->userId(QaBaselineFixture::QA_EDITOR_EMAIL);

        $table = $this->tables->createTable('qa_ds_owner');
        $this->tables->addRow('qa_ds_owner', ['qa_field' => 'owner-row'], $owner);
        $this->tables->addRow('qa_ds_owner', ['qa_field' => 'other-row'], $other);

        $all = $this->dataService->getData((int) $table->getId(), '', false, null, false);
        self::assertCount(2, $all, 'Cross-user read (ownEntriesOnly=false) must see both rows.');

        $ownerOnly = $this->dataService->getData((int) $table->getId(), '', true, $owner, false);
        self::assertCount(1, $ownerOnly, 'ownEntriesOnly read must return only the caller rows.');
        self::assertSame('owner-row', $this->fieldValue($this->asArray($ownerOnly[0]), 'qa_field'));
    }

    public function testReadsReflectWritesAfterSave(): void
    {
        $userId = $this->userId();
        $table = $this->tables->createTable('qa_ds_cache');
        $this->tables->addRow('qa_ds_cache', ['qa_field' => 'first'], $userId);

        $warm = $this->dataService->getData((int) $table->getId(), '', false, null, false);
        self::assertCount(1, $warm);

        // A second write must be visible on the next read (cache invalidated on save).
        $this->tables->addRow('qa_ds_cache', ['qa_field' => 'second'], $userId);
        $afterWrite = $this->dataService->getData((int) $table->getId(), '', false, null, false);

        self::assertCount(2, $afterWrite, 'A read after saveData must reflect the new row.');
    }

    // -- helpers ------------------------------------------------------------

    private function userId(string $email = QaBaselineFixture::QA_USER_EMAIL): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fieldValue(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
