<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS\Admin;

use App\DataFixtures\Test\QaBaselineFixture;
use App\Entity\DataTable;
use App\Entity\User;
use App\Service\CMS\Admin\DataTableExportService;
use App\Service\CMS\DataService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\Factories\GroupFactory;
use App\Tests\Support\Factories\RoleDataAccessFactory;
use App\Tests\Support\Factories\UserFactory;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The admin exports read a table in batches and write it as they go. The files
 * must be the ones the single read produced, whatever the batch size is: each
 * test builds the file the old way (one read, one encode) and compares bytes.
 */
#[Group('form-list')]
final class DataTableExportTest extends QaKernelTestCase
{
    private DataTableExportService $exports;
    private DataService $data;
    private DataGridFactory $grid;
    private Connection $db;
    private int $ownerOne;
    private int $ownerTwo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exports = $this->service(DataTableExportService::class);
        $this->data = $this->service(DataService::class);
        $this->db = $this->em->getConnection();
        $this->grid = new DataGridFactory($this->db);
        $this->ownerOne = $this->userId(QaBaselineFixture::QA_USER_EMAIL);
        $this->ownerTwo = $this->userId(QaBaselineFixture::QA_EDITOR_EMAIL);
    }

    public function testJsonIsTheSameFileForEveryBatchSize(): void
    {
        $table = $this->livelyTable();

        $expected = $this->singleReadJson($table);
        self::assertNotSame('[]', $expected, 'The fixture must have rows.');
        foreach ([1, 2, 3, 500] as $size) {
            self::assertSame($expected, $this->exports->json($this->exports->batches($table, $this->ownerOne, true, null, true, 1, $size)), 'Batch size ' . $size);
        }
    }

    public function testAnExcelCellOverExcelsLimitIsCutAndTheFileStaysValid(): void
    {
        $long = str_repeat('a', 40000);
        $file = $this->exports->xlsx([[['answer' => $long, 'score' => 7]]]);

        $path = (string) tempnam(sys_get_temp_dir(), 'qa_xlsx_');
        file_put_contents($path, $file);
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path) === true, 'the export is a readable zip');
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
        } finally {
            unlink($path);
        }
        self::assertStringNotContainsString($long, $sheet);
        self::assertSame(1, preg_match('/<t xml:space="preserve">(a+) \[cut: too long for Excel\]<\/t>/', $sheet, $match), 'the cell is cut with a mark');
        self::assertLessThanOrEqual(32767, strlen($match[1] ?? '') + strlen(' [cut: too long for Excel]'));
        self::assertStringContainsString('<c><v>7</v></c>', $sheet, 'numbers stay numbers');
    }

    public function testCsvIsTheSameFileForEveryBatchSize(): void
    {
        $table = $this->livelyTable();

        $expected = $this->singleReadCsv($table);
        self::assertStringContainsString('"quote ""inside"", comma', $expected);
        foreach ([1, 2, 3, 500] as $size) {
            self::assertSame($expected, $this->exports->csv($this->exports->batches($table, $this->ownerOne, true, null, true, 1, $size)), 'Batch size ' . $size);
        }
    }

    public function testTheHeaderHoldsEveryColumnThatAppearsInAnyRowInTheOrderTheyAppear(): void
    {
        $table = $this->livelyTable();

        $csv = $this->exports->csv($this->exports->batches($table, $this->ownerOne, true, null, true, 1, 2));
        $header = str_getcsv((string) strtok($csv, "\n"), escape: '');
        // `note` is only in the later rows; the header still has it, after the columns the first row has.
        self::assertContains('note', $header);
        self::assertLessThan((int) array_search('note', $header, true), (int) array_search('name', $header, true));
    }

    public function testAFileOfOneUsersRowsAndOfDeletedRowsFollowTheSameRules(): void
    {
        $table = $this->livelyTable();

        $own = $this->exports->json($this->exports->batches($table, $this->ownerOne, true, $this->ownerTwo, true, 1, 2));
        self::assertSame($this->singleReadJson($table, $this->ownerTwo), $own);
        self::assertStringNotContainsString('Anna', $own);

        $withDeleted = $this->exports->json($this->exports->batches($table, $this->ownerOne, true, null, false, 1, 2));
        self::assertSame($this->singleReadJson($table, null, false), $withDeleted);
        self::assertStringContainsString('removed', $withDeleted);
    }

    public function testATableWithoutRowsGivesAnEmptyListAndAnEmptyCsv(): void
    {
        $table = $this->table('qa_exp_empty_' . bin2hex(random_bytes(4)), ['name' => 'text']);

        self::assertSame('[]', $this->exports->json($this->exports->batches($table, $this->ownerOne, true, null, true, 1)));
        self::assertSame($this->singleReadJson($table), '[]');
        self::assertSame('', $this->exports->csv($this->exports->batches($table, $this->ownerOne, true, null, true, 1)));
    }

    public function testACallerWithoutFullAccessExportsOnlyTheRowsOfTheirGroups(): void
    {
        $roles = new RoleDataAccessFactory($this->em, $this->service(LookupService::class), $this->service(DataAccessSecurityService::class));
        $group = (new GroupFactory($this->em))->createGroup('qa_exp_group');
        $users = new UserFactory($this->em, $this->service(UserPasswordHasherInterface::class), $this->service(LookupService::class));
        $member = $users->createUser('qa.exp.member@selfhelp.test', 'Member', [$group]);
        $viewer = $users->createUser('qa.exp.viewer@selfhelp.test', 'Viewer');
        $role = $roles->createRole('qa_exp_group_reader');
        $roles->grantGroupAccess($role, (int) $group->getId(), DataAccessSecurityService::PERMISSION_READ);
        $roles->assignRoleToUser($viewer, $role);

        $table = $this->table('qa_exp_group_' . bin2hex(random_bytes(4)), ['name' => 'text']);
        $this->grid->row((int) $table->getId(), (int) $member->getId(), ['name' => 'Inside']);
        $this->grid->row((int) $table->getId(), $this->ownerOne, ['name' => 'Outside']);

        $json = $this->exports->json($this->exports->batches($table, (int) $viewer->getId(), false, null, true, 1, 1));

        self::assertStringContainsString('Inside', $json);
        self::assertStringNotContainsString('Outside', $json);
        self::assertSame(
            json_encode($this->data->getDataWithUserGroupFilter((int) $table->getId(), (int) $viewer->getId(), '', true, 1), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            $json,
        );
    }

    public function testTheBatchesOfTheDataServiceAreTheRowsOfTheSingleRead(): void
    {
        $table = $this->livelyTable();
        $tableId = (int) $table->getId();

        $batched = [];
        foreach ($this->data->getDataInBatches($tableId, null, true, 1, 2) as $batch) {
            self::assertLessThanOrEqual(2, count($batch));
            $batched = [...$batched, ...$batch];
        }

        self::assertSame($this->data->getData($tableId, '', false, null, false, true, 1), $batched);
    }

    // -- helpers ---------------------------------------------------------------------

    /**
     * Seven rows, two owners, unicode, quotes, commas and a line break in values,
     * a column only the later rows have and one deleted row.
     */
    private function livelyTable(): DataTable
    {
        $table = $this->table('qa_exp_lively_' . bin2hex(random_bytes(4)), ['name' => 'text', 'age' => 'number', 'note' => 'text']);
        $id = (int) $table->getId();
        $this->grid->row($id, $this->ownerOne, ['name' => 'Anna', 'age' => '30']);
        $this->grid->row($id, $this->ownerOne, ['name' => 'Ünïcode € 日本', 'age' => '45']);
        $this->grid->row($id, $this->ownerTwo, ['name' => 'quote "inside", comma', 'age' => '1.5']);
        $this->grid->row($id, $this->ownerTwo, ['name' => "line\nbreak"]);
        $this->grid->row($id, $this->ownerOne, ['name' => 'later', 'note' => 'only the later rows']);
        $this->grid->row($id, $this->ownerTwo, ['name' => 'last', 'age' => '0', 'note' => '']);
        $this->grid->row($id, $this->ownerOne, ['name' => 'removed'], null, 'deleted');

        return $table;
    }

    /**
     * @param array<string, string> $columns
     */
    private function table(string $name, array $columns): DataTable
    {
        $id = $this->grid->table($name, $columns);
        $table = $this->em->getRepository(DataTable::class)->find($id);
        self::assertInstanceOf(DataTable::class, $table);

        return $table;
    }

    /** The export as the single read built it: one read, one encode. */
    private function singleReadJson(DataTable $table, ?int $userId = null, bool $excludeDeleted = true): string
    {
        return (string) json_encode($this->singleRead($table, $userId, $excludeDeleted), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function singleReadCsv(DataTable $table): string
    {
        $rows = $this->singleRead($table, null, true);
        if ($rows === []) {
            return '';
        }
        $headers = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $headers[(string) $key] = true;
            }
        }
        $headers = array_keys($headers);
        $handle = fopen('php://temp', 'r+');
        self::assertIsResource($handle);
        fputcsv($handle, $headers, escape: '');
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $column) {
                $value = $row[$column] ?? null;
                $line[] = is_scalar($value) ? (string) $value : '';
            }
            fputcsv($handle, $line, escape: '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function singleRead(DataTable $table, ?int $userId, bool $excludeDeleted): array
    {
        $tableId = (int) $table->getId();
        $rows = [];
        foreach ($this->data->remapEntriesToInputNames($tableId, $this->data->getData($tableId, '', false, $userId, false, $excludeDeleted, 1)) as $row) {
            $rows[] = self::asArray($row);
        }

        return $rows;
    }

    private function userId(string $email): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, $email . ' must be seeded. Run: composer test:reset-db');

        return (int) $user->getId();
    }
}
