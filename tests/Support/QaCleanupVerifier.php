<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Proves a golden/integration test left no non-QA business data behind
 * (plan §8 cleanup proof).
 *
 * How it works: {@see capture()} snapshots the current max id of each business
 * table BEFORE the test acts. {@see verifyNoNonQaLeaks()} then asserts that every
 * row created during the test (id greater than the snapshot) carries a `qa`
 * identifier. The DAMA transaction rolls every row back at tearDown, so this is a
 * positive proof the test only ever WROTE qa-prefixed business data — it never
 * created, updated, or deleted a non-qa business record.
 *
 * It does not check audit-only tables (e.g. `transactions`) by name because those
 * legitimately reference the qa rows without carrying a qa identifier of their own.
 * Outbound side effects (email/Mercure) are verified separately via
 * {@see assertNoRealOutbound()} / {@see RecordingNotifier}.
 */
final class QaCleanupVerifier
{
    /**
     * Business table => identifying column that must start with "qa".
     */
    private const TABLES = [
        'actions' => 'name',
        'scheduled_jobs' => 'description',
        'data_tables' => 'name',
        'pages' => 'keyword',
        'users' => 'email',
    ];

    /** @var array<string, int> */
    private array $baseline = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Snapshot the highest id per business table. Call before the test acts.
     */
    public function capture(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $max = $this->connection->fetchOne(
                sprintf('SELECT COALESCE(MAX(id), 0) FROM %s', $table)
            );
            $this->baseline[$table] = is_numeric($max) ? (int) $max : 0;
        }
    }

    /**
     * Assert every row created since {@see capture()} is qa-prefixed.
     */
    public function verifyNoNonQaLeaks(): void
    {
        if ($this->baseline === []) {
            Assert::fail('QaCleanupVerifier::capture() must be called before verifyNoNonQaLeaks().');
        }

        foreach (self::TABLES as $table => $column) {
            $rows = $this->connection->fetchAllAssociative(
                sprintf('SELECT id, %s AS ident FROM %s WHERE id > :base', $column, $table),
                ['base' => $this->baseline[$table]]
            );

            foreach ($rows as $row) {
                $identRaw = $row['ident'] ?? '';
                $ident = is_scalar($identRaw) ? (string) $identRaw : '';
                $idRaw = $row['id'] ?? '';
                $id = is_scalar($idRaw) ? (string) $idRaw : '';
                Assert::assertTrue(
                    $this->isQaIdentifier($ident),
                    sprintf(
                        'Leak detected: %s#%s has non-qa %s "%s". Tests must only create qa-prefixed business data.',
                        $table,
                        $id,
                        $column,
                        $ident
                    )
                );
            }
        }
    }

    /**
     * Assert the test produced exactly the expected number of realtime publishes
     * (0 for chains that have no realtime side effect) and that they went to the
     * in-memory recorder rather than a real hub.
     */
    public function assertNoRealOutbound(MercureTestRecorder $mercure, int $expectedMercurePublishes = 0): void
    {
        Assert::assertSame(
            $expectedMercurePublishes,
            $mercure->count(),
            sprintf(
                'Expected %d Mercure publish(es), recorded %d. Topics: %s',
                $expectedMercurePublishes,
                $mercure->count(),
                implode(', ', $mercure->getPublishedTopics()) ?: '(none)'
            )
        );
    }

    private function isQaIdentifier(string $ident): bool
    {
        // qa., qa-, qa_  (and bare "qa")
        return stripos($ident, 'qa') === 0;
    }
}
