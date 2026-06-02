<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\Group;
use App\Entity\ValidationCode;
use App\Service\Core\BaseService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages admin-facing registration codes stored in validation_codes.
 * Each code is single-use — it is deleted when a user successfully registers with it.
 */
class RegistrationCodeService extends BaseService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly int $totalMax = 10000,
        private readonly int $requestMax = 10000,
    ) {
    }

    /**
     * @param array{search?: string|null, id_groups?: int|null, status?: string|null, sort?: string|null, sortDirection?: string|null} $filters
     * @return array{codes: list<array{id: string, code: string, id_groups: int|null, group_name: string|null, created_at: string, consumed_at: string|null, is_consumed: bool}>, pagination: array{page: int, pageSize: int, totalCount: int, totalPages: int, hasNext: bool, hasPrevious: bool}, config: array{generate_min: int, generate_max: int}}
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
            'SELECT vc.code, vc.id_groups, g.name as group_name, vc.created, vc.consumed
               FROM validation_codes vc
               LEFT JOIN `groups` g ON g.id = vc.id_groups'
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
     * @return list<array{code: string, group_name: string|null, status: string, created_at: string, consumed_at: string}>
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
            'SELECT vc.code, g.name as group_name, vc.created, vc.consumed
               FROM validation_codes vc
               LEFT JOIN `groups` g ON g.id = vc.id_groups'
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
        ], $rows);
    }

    /**
     * Generates $count unique random 8-character alphanumeric codes and persists them in one transaction.
     *
     * Uses INSERT IGNORE so duplicate PKs are silently skipped. After each insert the affected-row
     * count tells us exactly how many landed. Any shortfall (collision) triggers another round of
     * generation until the total reaches $count. At ~2.8 trillion combinations this almost never loops.
     *
     * @return array{codes: list<array{id: string, code: string, id_groups: int, group_name: string|null, created_at: string, consumed_at: string|null, is_consumed: bool}>}
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

        $rawTotal = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM validation_codes WHERE id_users IS NULL'
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
        $chars           = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $charLen         = strlen($chars);
        $now             = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $batchSize       = 500;

        /** @var list<string> $inserted */
        $inserted = [];

        $conn->beginTransaction();
        try {
            while (count($inserted) < $count) {
                $needed = $count - count($inserted);

                // Generate exactly as many candidates as still needed;
                // hash-map keys deduplicate within this round and against already-inserted codes
                /** @var array<string, true> $candidate */
                $candidate = [];
                while (count($candidate) < $needed) {
                    $code = '';
                    for ($j = 0; $j < 8; $j++) {
                        $code .= $chars[random_int(0, $charLen - 1)];
                    }
                    if (!isset($candidate[$code])) {
                        $candidate[$code] = true;
                    }
                }

                // INSERT IGNORE in 500-row chunks; affected rows = how many actually landed
                foreach (array_chunk(array_keys($candidate), $batchSize) as $chunk) {
                    $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
                    $values       = [];
                    foreach ($chunk as $code) {
                        $values[] = $code;
                        $values[] = $groupIdResolved;
                        $values[] = $now;
                    }

                    $affected = $conn->executeStatement(
                        "INSERT IGNORE INTO validation_codes (code, id_groups, created) VALUES $placeholders",
                        $values
                    );

                    // Take only the first $affected codes from the chunk — those are the ones MySQL inserted
                    for ($k = 0; $k < (int) $affected; $k++) {
                        $inserted[] = (string) $chunk[$k];
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
            ];
        }

        return ['codes' => $results];
    }

    public function delete(string $code): void
    {
        $vc = $this->entityManager->getRepository(ValidationCode::class)->find($code);
        if ($vc === null) {
            throw new \InvalidArgumentException('Registration code not found.');
        }

        $this->entityManager->remove($vc);
        $this->entityManager->flush();
    }
}
