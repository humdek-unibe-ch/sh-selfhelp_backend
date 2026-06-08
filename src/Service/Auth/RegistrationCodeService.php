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
     * @return array{codes: list<array{id: string, code: string, id_groups: int|null, group_name: string|null, group_ids: list<int>, group_names: list<string>, created_at: string, consumed_at: string|null, is_consumed: bool, id_users: int|null, user_email: string|null}>, pagination: array{page: int, pageSize: int, totalCount: int, totalPages: int, hasNext: bool, hasPrevious: bool}, config: array{generate_min: int, generate_max: int}}
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
            // Match the code's primary group OR any of its granted groups, so
            // filtering by a group also finds multi-group codes that include it.
            $where[] = '(vc.id_groups = :id_groups OR EXISTS (SELECT 1 FROM validation_code_groups vcg WHERE vcg.code = vc.code AND vcg.id_groups = :id_groups))';
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

        /** @var list<string> $codeValues */
        $codeValues = [];
        foreach ($rows as $row) {
            if (is_string($row['code'])) {
                $codeValues[] = $row['code'];
            }
        }
        $groupsByCode = $this->fetchGroupsByCode($codeValues);

        $totalPages = (int) ceil($totalCount / $pageSize);

        return [
            'codes' => array_map(function (array $row) use ($groupsByCode) {
                $code        = is_string($row['code']) ? $row['code'] : '';
                $primaryId   = is_numeric($row['id_groups']) ? (int) $row['id_groups'] : null;
                $primaryName = is_string($row['group_name']) ? $row['group_name'] : null;
                $multi       = $groupsByCode[$code] ?? null;

                return [
                    'id'          => $code,
                    'code'        => $code,
                    'id_groups'   => $primaryId,
                    'group_name'  => $primaryName,
                    'group_ids'   => $multi['ids'] ?? ($primaryId !== null ? [$primaryId] : []),
                    'group_names' => $multi['names'] ?? ($primaryName !== null ? [$primaryName] : []),
                    'created_at'  => is_string($row['created']) ? $row['created'] : '',
                    'consumed_at' => is_string($row['consumed']) ? $row['consumed'] : null,
                    'is_consumed' => $row['consumed'] !== null,
                    'id_users'    => is_numeric($row['id_users']) ? (int) $row['id_users'] : null,
                    'user_email'  => is_string($row['user_email']) ? $row['user_email'] : null,
                ];
            }, $rows),
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
            // Match the code's primary group OR any of its granted groups, so
            // filtering by a group also finds multi-group codes that include it.
            $where[] = '(vc.id_groups = :id_groups OR EXISTS (SELECT 1 FROM validation_code_groups vcg WHERE vcg.code = vc.code AND vcg.id_groups = :id_groups))';
            $params['id_groups'] = $filters['id_groups'];
        }

        if (($filters['status'] ?? null) === 'available') {
            $where[] = 'vc.consumed IS NULL';
        } elseif (($filters['status'] ?? null) === 'used') {
            $where[] = 'vc.consumed IS NOT NULL';
        }

        $whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        // group_name aggregates every granted group (multi-group codes), joined
        // by "; ", and falls back to the primary group for legacy single codes.
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT vc.code,
                    COALESCE(
                        (SELECT GROUP_CONCAT(g2.name ORDER BY g2.name SEPARATOR '; ')
                           FROM validation_code_groups vcg
                           JOIN `groups` g2 ON g2.id = vcg.id_groups
                          WHERE vcg.code = vc.code),
                        g.name
                    ) AS group_name,
                    vc.created, vc.consumed, u.email AS user_email
               FROM validation_codes vc
               LEFT JOIN `groups` g ON g.id = vc.id_groups
               LEFT JOIN `users` u ON u.id = vc.id_users"
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
     * Load every granted group (id + name) for the given codes from
     * `validation_code_groups`, keyed by code. Codes without link rows are
     * simply absent (callers fall back to the primary `id_groups`).
     *
     * @param list<string> $codes
     * @return array<string, array{ids: list<int>, names: list<string>}>
     */
    private function fetchGroupsByCode(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT vcg.code, vcg.id_groups, g.name AS group_name
               FROM validation_code_groups vcg
               JOIN `groups` g ON g.id = vcg.id_groups
              WHERE vcg.code IN (?)
              ORDER BY g.name ASC',
            [$codes],
            [ArrayParameterType::STRING]
        );

        /** @var array<string, array{ids: list<int>, names: list<string>}> $byCode */
        $byCode = [];
        foreach ($rows as $row) {
            $code = is_string($row['code']) ? $row['code'] : '';
            if ($code === '') {
                continue;
            }
            if (!isset($byCode[$code])) {
                $byCode[$code] = ['ids' => [], 'names' => []];
            }
            if (is_numeric($row['id_groups'])) {
                $byCode[$code]['ids'][] = (int) $row['id_groups'];
            }
            if (is_string($row['group_name'])) {
                $byCode[$code]['names'][] = $row['group_name'];
            }
        }

        return $byCode;
    }

    /**
     * Generates $count unique random 8-character alphanumeric codes and persists them in one transaction.
     *
     * Each code may grant several groups: the full set is written to
     * `validation_code_groups`, while `validation_codes.id_groups` keeps the
     * first selected group as the primary (for backward-compatible listing,
     * filtering and CSV export).
     *
     * Collisions are accounted for exactly: each candidate batch is first checked against the table with
     * a SELECT, only the genuinely new codes are inserted (INSERT IGNORE guards the rare race), and the
     * surrounding loop regenerates any shortfall until exactly $count codes have landed. At ~2.8 trillion
     * combinations this almost never loops.
     *
     * @param list<int> $groupIds Group IDs to grant; the first is stored as the primary group. Must contain at least one id.
     * @return array{codes: list<array{id: string, code: string, id_groups: int, group_name: string|null, group_ids: list<int>, group_names: list<string>, created_at: string, consumed_at: string|null, is_consumed: bool, id_users: int|null, user_email: string|null}>}
     */
    public function generate(int $count, array $groupIds): array
    {
        if ($count < 1 || $count > $this->requestMax) {
            throw new \InvalidArgumentException("Count must be between 1 and {$this->requestMax}.");
        }

        $groupIds = array_values(array_unique($groupIds));
        if ($groupIds === []) {
            throw new \InvalidArgumentException('At least one group must be selected.');
        }

        $groupRepository = $this->entityManager->getRepository(Group::class);

        /** @var list<int> $resolvedGroupIds */
        $resolvedGroupIds = [];
        /** @var list<string> $resolvedGroupNames */
        $resolvedGroupNames = [];
        foreach ($groupIds as $groupId) {
            $group = $groupRepository->find($groupId);
            if ($group === null) {
                throw new \InvalidArgumentException('Group not found.');
            }
            $resolvedGroupIds[]   = $group->getId() ?? 0;
            $resolvedGroupNames[] = (string) ($group->getName() ?? '');
        }

        $primaryGroupId   = $resolvedGroupIds[0];
        $primaryGroupName = $resolvedGroupNames[0];

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

        $conn      = $this->entityManager->getConnection();
        $now       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $batchSize = 500;

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
                        $values[] = $primaryGroupId;
                        $values[] = $now;
                    }

                    $conn->executeStatement(
                        "INSERT IGNORE INTO validation_codes (code, id_groups, created) VALUES $placeholders",
                        $values
                    );

                    // Record every granted group for each new code so a single
                    // code can enrol a user into several groups at once. Both
                    // $toInsert and $resolvedGroupIds are non-empty here, so at
                    // least one (code, group) row is always written.
                    $groupPlaceholders = [];
                    $groupValues       = [];
                    foreach ($toInsert as $code) {
                        foreach ($resolvedGroupIds as $resolvedGroupId) {
                            $groupPlaceholders[] = '(?, ?)';
                            $groupValues[]       = $code;
                            $groupValues[]       = $resolvedGroupId;
                        }
                    }
                    $conn->executeStatement(
                        'INSERT IGNORE INTO validation_code_groups (code, id_groups) VALUES ' . implode(', ', $groupPlaceholders),
                        $groupValues
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
                'id_groups'   => $primaryGroupId,
                'group_name'  => $primaryGroupName !== '' ? $primaryGroupName : null,
                'group_ids'   => $resolvedGroupIds,
                'group_names' => $resolvedGroupNames,
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
