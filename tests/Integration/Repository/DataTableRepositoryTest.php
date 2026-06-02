<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\User;
use App\Repository\DataTableRepository;
use App\Service\CMS\DataService;
use App\Tests\Support\Factories\DataTableFactory;
use App\Tests\Support\QaKernelTestCase;

/**
 * Integration coverage for {@see DataTableRepository} — the `get_data_table_*`
 * stored-procedure readers behind the admin data + frontend list views (plan
 * Phase 9: repository integration tests). Rows are seeded through the real save
 * path so the stored procedure reads them exactly as production would.
 */
final class DataTableRepositoryTest extends QaKernelTestCase
{
    private DataTableRepository $repository;
    private DataTableFactory $tables;
    private DataService $dataService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->service(DataTableRepository::class);
        $this->dataService = $this->service(DataService::class);
        $this->tables = new DataTableFactory($this->em, $this->dataService);
    }

    public function testGetDataTableIdByNameResolves(): void
    {
        $table = $this->tables->createTable('qa_dt_repo_id');

        self::assertSame((int) $table->getId(), $this->repository->getDataTableIdByName('qa_dt_repo_id'));
    }

    public function testGetDataTableWithFilterReturnsTheSeededRow(): void
    {
        $userId = $this->qaUserId();
        [$table, ] = $this->tables->createTableWithRow('qa_dt_repo_rows', $userId, 'qa-repo-value');

        $rows = $this->repository->getDataTableWithFilter((int) $table->getId(), $userId, '', true);

        self::assertNotEmpty($rows, 'The seeded row must be readable through the stored procedure.');
        self::assertStringContainsString('qa-repo-value', $this->flatten($rows));
    }

    public function testExcludeDeletedHidesSoftDeletedRows(): void
    {
        $userId = $this->qaUserId();
        [$table, $recordId] = $this->tables->createTableWithRow('qa_dt_repo_softdelete', $userId, 'qa-soft-deleted');

        // Soft-delete through the real service (ownEntriesOnly=false: no logged-in
        // user in a kernel test). This also invalidates the data-table cache.
        self::assertTrue($this->dataService->deleteData($recordId, false));

        $visible = $this->repository->getDataTableWithFilter((int) $table->getId(), $userId, '', true);
        self::assertStringNotContainsString('qa-soft-deleted', $this->flatten($visible), 'excludeDeleted=true must hide soft-deleted rows.');

        $all = $this->repository->getDataTableWithFilter((int) $table->getId(), $userId, '', false);
        self::assertStringContainsString('qa-soft-deleted', $this->flatten($all), 'excludeDeleted=false must still include soft-deleted rows.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function flatten(array $rows): string
    {
        return (string) json_encode($rows);
    }

    private function qaUserId(): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => QaBaselineFixture::QA_USER_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'qa.user must be seeded. Run: composer test:reset-db');

        return (int) $user->getId();
    }
}
