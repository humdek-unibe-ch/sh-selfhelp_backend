<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Integration\CMS;

use App\Tests\Support\Factories\DataGridFactory;
use App\Tests\Support\QaKernelTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Two writers of one record, in separate processes with separate database
 * connections, really committed. The fixture is committed on purpose (the other
 * process cannot see an open test transaction) and removed again in `finally`.
 *
 * Proves: a second save based on the same version waits for the first one,
 * finds the version moved and is refused with nothing written; and of two
 * simultaneous saves of one version exactly one wins.
 */
#[Group('form-list')]
final class DataRevisionRaceTest extends QaKernelTestCase
{
    private Connection $committed;
    private string $table;
    private int $tableId = 0;
    private int $recordId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // A second, independent connection to the same test database: its commits are
        // really committed, which the DAMA-wrapped connection of this test never does.
        $source = $this->em->getConnection()->getParams();
        $this->committed = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $this->asString($source['host'] ?? '127.0.0.1'),
            'port' => $this->asInt($source['port'] ?? 3306),
            'user' => $this->asString($source['user'] ?? ''),
            'password' => $this->asString($source['password'] ?? ''),
            'dbname' => $this->asString($source['dbname'] ?? ''),
            'charset' => 'utf8mb4',
        ]);

        $this->table = 'qa_race_' . bin2hex(random_bytes(4));
        $grid = new DataGridFactory($this->committed);
        $this->tableId = $grid->table($this->table, ['title' => 'text']);
        $this->recordId = $grid->row($this->tableId, null, ['title' => 'original']);
    }

    protected function tearDown(): void
    {
        if ($this->tableId > 0) {
            // Cascades to the columns, rows, cells and the whole history.
            $this->committed->executeStatement('DELETE FROM data_tables WHERE id = :id', ['id' => $this->tableId]);
            $this->committed->executeStatement(
                "DELETE FROM transactions WHERE table_name = 'data_tables' AND id_table_name = :id",
                ['id' => $this->tableId],
            );
        }
        $this->committed->close();
        parent::tearDown();
    }

    public function testASecondWriterWaitsForTheLockThenIsRefusedBecauseTheVersionMoved(): void
    {
        // The first writer holds the record lock, as DataService does while it saves.
        $this->committed->beginTransaction();
        $version = $this->committed->fetchOne('SELECT version FROM data_rows WHERE id = :id FOR UPDATE', ['id' => $this->recordId]);
        self::assertSame(1, $this->asInt($version));

        $second = $this->startWriter(1, 'second writer');
        $this->waitUntilBlocked();

        // The first writer finishes: the version moves 1 -> 2 and the new value is committed.
        $this->committed->executeStatement('UPDATE data_rows SET version = 2 WHERE id = :id', ['id' => $this->recordId]);
        $this->committed->executeStatement(
            'UPDATE data_cells SET value = :v WHERE id_data_rows = :id',
            ['v' => 'first writer', 'id' => $this->recordId],
        );
        $this->committed->commit();

        $result = $this->finish($second);
        self::assertFalse($result['ok'], 'The waiting writer was based on version 1 and must be refused.');
        self::assertSame(409, $result['code'] ?? null);
        $data = $result['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(1, $data['expected_version']);
        self::assertSame(2, $data['current_version']);

        self::assertSame('first writer', $this->storedTitle(), 'The committed value was not overwritten.');
        self::assertSame(2, $this->storedVersion());
        self::assertSame(0, $this->revisionCount(), 'The refused writer left no history behind.');
    }

    public function testOfTwoSimultaneousSavesOfOneVersionExactlyOneWins(): void
    {
        $a = $this->startWriter(1, 'writer A');
        $b = $this->startWriter(1, 'writer B');

        $results = [$this->finish($a), $this->finish($b)];

        $winner = null;
        $loser = null;
        $wins = 0;
        foreach ($results as $result) {
            if ($result['ok']) {
                $winner = $result;
                ++$wins;
            } else {
                $loser = $result;
            }
        }
        self::assertSame(1, $wins, 'Exactly one of two saves of the same version succeeds: ' . json_encode($results));
        self::assertNotNull($winner);
        self::assertNotNull($loser, 'The other save was refused.');
        self::assertSame(409, $loser['code'] ?? null);
        self::assertSame(2, $winner['version'] ?? null);

        self::assertSame(2, $this->storedVersion());
        self::assertContains($this->storedTitle(), ['writer A', 'writer B']);
        // The record predates the history: its baseline plus the one accepted change.
        self::assertSame(2, $this->revisionCount());
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @return array{process: resource, pipes: array<int, resource>}
     */
    private function startWriter(int $expectedVersion, string $value): array
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/Support/Cli/save_with_expected_version.php',
            $this->table,
            (string) $this->recordId,
            (string) $expectedVersion,
            'title',
            $value,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'The writer process must start.');

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param array{process: resource, pipes: array<int, resource>} $writer
     * @return array{ok: bool, code?: int, version?: int, data?: mixed}
     */
    private function finish(array $writer): array
    {
        $output = (string) stream_get_contents($writer['pipes'][1]);
        $errors = (string) stream_get_contents($writer['pipes'][2]);
        fclose($writer['pipes'][1]);
        fclose($writer['pipes'][2]);
        proc_close($writer['process']);

        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];
        $decoded = json_decode($last, true);
        self::assertIsArray($decoded, "The writer printed no result.\nstdout: $output\nstderr: $errors");

        /** @var array{ok: bool, code?: int, version?: int, data?: mixed} $decoded */
        return $decoded;
    }

    /** Wait until another connection is queued on a row lock (the second writer reached the lock). */
    private function waitUntilBlocked(): void
    {
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            try {
                $waiting = $this->committed->fetchOne("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT'");
            } catch (\Throwable) {
                sleep(5); // no permission to look: give the other process time to reach the lock

                return;
            }
            if ($this->asInt($waiting) >= 1) {
                return;
            }
            usleep(100_000);
        }
        self::fail('The second writer never reached the record lock.');
    }

    private function storedTitle(): string
    {
        return $this->asString($this->committed->fetchOne('SELECT value FROM data_cells WHERE id_data_rows = :id', ['id' => $this->recordId]));
    }

    private function storedVersion(): int
    {
        return $this->asInt($this->committed->fetchOne('SELECT version FROM data_rows WHERE id = :id', ['id' => $this->recordId]));
    }

    private function revisionCount(): int
    {
        return $this->asInt($this->committed->fetchOne('SELECT COUNT(*) FROM data_revisions WHERE id_data_rows = :id', ['id' => $this->recordId]));
    }
}
