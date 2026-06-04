<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\Group;
use App\Service\Core\BaseService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages admin-facing registration codes stored in validation_codes.
 * Each code is single-use — on a successful registration it is marked consumed
 * (consumed timestamp) and linked to the new user (id_users) by
 * {@see \App\Service\Auth\RegistrationService}, never deleted.
 */
class RegistrationCodeService extends BaseService
{
    /** Character set for generated registration codes (uppercase alphanumeric). */
    private const CODE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** Length of a generated registration code (~2.8 trillion combinations). */
    private const CODE_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly int $totalMax = 10000,
        private readonly int $requestMax = 10000,
    ) {
    }

    /**
     * @param array{search?: string|null, id_groups?: int|null, status?: string|null, sort?: string|null, sortDirection?: string|null} $filters
     * @return array{codes: list<array{id: string, code: string, id_groups: int|null, group_name: string|null, created_at: string, consumed_at: string|null, is_consumed: bool, id_users: int|null, user_email: string|null}>, pagination: array{page: int, pageSize: int, totalCount: int, totalPages: int, hasNext: bool, hasPrevious: bool}, config: array{generate_min: int, generate_max: int}}
     */
    public function getAll(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = 'vc.code LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['id_groups'])) {
            $where[] = 'vc.id_groups = :id_groups';
            $params['id_groups'] = $filters['id_groups'];
        }

        if (($filters['status'] ?? null) === 'available') {
            $where[] = 'vc.consumed IS NULL';
        } elseif (($filters['status'] ?? null) === 'used') {
            $where[] = 'vc.consumed IS NOT NULL';
        }

        $allowedSort = ['created_at' => 'vc.created', 'consumed_at' => 'vc.consumed'];
        $sortCol = $allowedSort[$filters['sort'] ?? ''] ?? 'vc.created';
        $sortDir = strtoupper($filters['sortDirection'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $rawCount = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM validation_codes vc' . $whereClause,
            $params
        );
        $totalCount = is_numeric($rawCount) ? (int) $rawCount : 0;

        $offset = ($page - 1) * $pageSize;
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT vc.code, vc.id_groups, g.name as group_name, vc.created, vc.consumed, vc.id_users, u.email AS user_email
               FROM validation_codes vc
               LEFT JOIN `groups` g ON g.id = vc.id_groups
               LEFT JOIN `users` u ON u.id = vc.id_users'
            . $whereClause
            . " ORDER BY $sortCol $sortDir"
            . " LIMIT $pageSize OFFSET $offset",
            $params
        );

        $totalPages = (int) ceil($totalCount / $pageSize);

        return [
            'codes' => array_map(fn(array $row) => [
                'id'          => is_string($row['code']) ? $row['code'] : '',
                'code'        => is_string($row['code']) ? $row['code'] : '',
                'id_groups'   => is_numeric($row['id_groups']) ? (int) $row['id_groups'] : null,
                'group_name'  => is_string($row['group_name']) ? $row['group_name'] : null,
                'created_at'  => is_string($row['created']) ? $row['created'] : '',
                'consumed_at' => is_string($row['consumed']) ? $row['consumed'] : null,
                'is_consumed' => $row['consumed'] !== null,
                'id_users'    => is_numeric($row['id_users']) ? (int) $row['id_users'] : null,
                'user_email'  => is_string($row['user_email']) ? $row['user_email'] : null,
            ], $rows),
            'pagination' => [
                'page'        => $page,
                'pageSize'    => $pageSize,
                'totalCount'  => $totalCount,
                'totalPages'  => $totalPages,
                'hasNext'     => $page < $totalPages,
                'hasPrevious' => $page > 1,
            ],
            'config' => [
                'generate_min' => 1,
                'generate_max' => $this->requestMax,
            ],
        ];
    }

    /**
     * @param array{search?: string|null, id_groups?: int|null, status?: string|null, sort?: string|null, sortDirection?: string|null} $filters
     * @return list<array{code: string, group_name: string|null, status: string, created_at: string, consumed_at: string, user_email: string}>
     */
    public function export(array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = 'vc.code LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['id_groups'])) {
            $where[] = 'vc.id_groups = :id_groups';
            $params['id_groups'] = $filters['id_groups'];
        }

        if (($filters['status'] ?? null) === 'available') {
            $where[] = 'vc.consumed IS NULL';
        } elseif (($filters['status'] ?? null) === 'used') {
            $where[] = 'vc.consumed IS NOT NULL';
        }

        $whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT vc.code, g.name as group_name, vc.created, vc.consumed, u.email AS user_email
               FROM validation_codes vc
               LEFT JOIN `groups` g ON g.id = vc.id_groups
               LEFT JOIN `users` u ON u.id = vc.id_users'
            . $whereClause
            . ' ORDER BY vc.created DESC',
            $params
        );

        return array_map(fn(array $row) => [
            'code'        => is_string($row['code']) ? $row['code'] : '',
            'group_name'  => is_string($row['group_name']) ? $row['group_name'] : '',
            'status'      => $row['consumed'] !== null ? 'Used' : 'Available',
            'created_at'  => is_string($row['created']) ? $row['created'] : '',
            'consumed_at' => is_string($row['consumed']) ? $row['consumed'] : '',
            'user_email'  => is_string($row['user_email']) ? $row['user_email'] : '',
        ], $rows);
    }

    /**
     * Generates $count unique random 8-character alphanumeric codes and persists them in one transaction.
     *
     * Collisions are accounted for exactly: each candidate batch is first checked against the table with
     * a SELECT, only the genuinely new codes are inserted (INSERT IGNORE guards the rare race), and the
     * surrounding loop regenerates any shortfall until exactly $count codes have landed. At ~2.8 trillion
     * combinations this almost never loops.
     *
     * @return array{codes: list<array{id: string, code: string, id_groups: int, group_name: string|null, created_at: string, consumed_at: string|null, is_consumed: bool, id_users: int|null, user_email: string|null}>}
     */
    public function generate(int $count, int $groupId): array
    {
        if ($count < 1 || $count > $this->requestMax) {
            throw new \InvalidArgumentException("Count must be between 1 and {$this->requestMax}.");
        }

        $group = $this->entityManager->getRepository(Group::class)->find($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException('Group not found.');
        }

        // The overall cap limits how many AVAILABLE (unconsumed) codes may exist
        // at once. Consumed codes are historical records linked to a user and do
        // not count against the cap, so the table can keep issuing codes as old
        // ones are used.
        $rawTotal = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM validation_codes WHERE consumed IS NULL'
        );
        $currentTotal = is_numeric($rawTotal) ? (int) $rawTotal : 0;
        if ($currentTotal + $count > $this->totalMax) {
            $available = max(0, $this->totalMax - $currentTotal);
            throw new \InvalidArgumentException(
                "Cannot generate {$count} codes: the table limit of {$this->totalMax} would be exceeded. Currently {$currentTotal} codes exist; {$available} more can be created."
            );
        }

        $groupIdResolved = $group->getId() ?? 0;
        $groupName       = $group->getName();
        $conn            = $this->entityManager->getConnection();
        $now             = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $batchSize       = 500;

        /** @var list<string> $inserted */
        $inserted = [];

        $conn->beginTransaction();
        try {
            while (count($inserted) < $count) {
                $needed = $count - count($inserted);

                // Generate candidate codes for this round. $seen deduplicates
                // within the round and against codes already inserted by this
                // call; $candidates stays a list<string> so all-numeric codes
                // are never coerced to int array keys.
                /** @var array<string, true> $seen */
                $seen = array_fill_keys($inserted, true);
                /** @var list<string> $candidates */
                $candidates = [];
                while (count($candidates) < $needed) {
                    $code = $this->randomCode();
                    if (!isset($seen[$code])) {
                        $seen[$code]  = true;
                        $candidates[] = $code;
                    }
                }

                foreach (array_chunk($candidates, $batchSize) as $chunk) {
                    // INSERT IGNORE silently skips PK collisions, and its
                    // affected-row count does not reveal WHICH rows landed. So
                    // first read back the candidates that already exist and only
                    // insert + count the genuinely new ones. Any shortfall is
                    // regenerated by the surrounding while loop.
                    $existingRows = $conn->fetchFirstColumn(
                        'SELECT code FROM validation_codes WHERE code IN (?)',
                        [$chunk],
                        [ArrayParameterType::STRING]
                    );
                    /** @var array<string, true> $existing */
                    $existing = [];
                    foreach ($existingRows as $existingCode) {
                        if (is_scalar($existingCode)) {
                            $existing[(string) $existingCode] = true;
                        }
                    }

                    $toInsert = [];
                    foreach ($chunk as $code) {
                        if (!isset($existing[$code])) {
                            $toInsert[] = $code;
                        }
                    }
                    if ($toInsert === []) {
                        continue;
                    }

                    $placeholders = implode(', ', array_fill(0, count($toInsert), '(?, ?, ?)'));
                    $values       = [];
                    foreach ($toInsert as $code) {
                        $values[] = $code;
                        $values[] = $groupIdResolved;
                        $values[] = $now;
                    }

                    $conn->executeStatement(
                        "INSERT IGNORE INTO validation_codes (code, id_groups, created) VALUES $placeholders",
                        $values
                    );

                    foreach ($toInsert as $code) {
                        $inserted[] = $code;
                    }
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        $results = [];
        foreach ($inserted as $code) {
            $results[] = [
                'id'          => $code,
                'code'        => $code,
                'id_groups'   => $groupIdResolved,
                'group_name'  => $groupName,
                'created_at'  => $now,
                'consumed_at' => null,
                'is_consumed' => false,
                'id_users'    => null,
                'user_email'  => null,
            ];
        }

        return ['codes' => $results];
    }

    /**
     * Generate a single registration code guaranteed unique against the
     * validation_codes table at call time.
     *
     * Self-registration in open mode mints one fresh code per account through
     * here (see {@see RegistrationService}). The caller persists the code; the
     * primary-key constraint on validation_codes.code is the final guard
     * against a concurrent duplicate, so this MUST be called inside the
     * registration transaction. Re-rolls on the (astronomically rare) hit.
     */
    public function generateUnique(): string
    {
        $conn = $this->entityManager->getConnection();

        do {
            $code   = $this->randomCode();
            $exists = $conn->fetchOne('SELECT 1 FROM validation_codes WHERE code = :code', ['code' => $code]);
        } while ($exists !== false);

        return $code;
    }

    /**
     * Build one random uppercase-alphanumeric code string of {@see CODE_LENGTH}
     * characters with no uniqueness check. Single source of the charset/length
     * shared by {@see generate()} (batch) and {@see generateUnique()} (single).
     */
    private function randomCode(): string
    {
        $charLen = strlen(self::CODE_CHARS);
        $code    = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_CHARS[random_int(0, $charLen - 1)];
        }

        return $code;
    }
}
