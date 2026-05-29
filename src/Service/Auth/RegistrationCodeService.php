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
    ) {
    }

    /**
     * @return array<int, array{id: string, code: string, id_groups: int|null, group_name: string|null, created_at: string, consumed_at: string|null, is_consumed: bool}>
     */
    public function getAll(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT vc.code, vc.id_groups, g.name as group_name, vc.created, vc.consumed
               FROM validation_codes vc
               LEFT JOIN `groups` g ON g.id = vc.id_groups
              ORDER BY vc.created DESC'
        );

        return array_map(fn(array $row) => [
            'id'          => is_string($row['code']) ? $row['code'] : '',
            'code'        => is_string($row['code']) ? $row['code'] : '',
            'id_groups'   => is_numeric($row['id_groups']) ? (int) $row['id_groups'] : null,
            'group_name'  => is_string($row['group_name']) ? $row['group_name'] : null,
            'created_at'  => is_string($row['created']) ? $row['created'] : '',
            'consumed_at' => is_string($row['consumed']) ? $row['consumed'] : null,
            'is_consumed' => $row['consumed'] !== null,
        ], $rows);
    }

    /**
     * @return array{id: string, code: string, id_groups: int, group_name: string|null, created_at: string}
     */
    public function create(string $code, int $groupId): array
    {
        $code = trim($code);

        if ($code === '') {
            throw new \InvalidArgumentException('Code cannot be empty.');
        }

        $existing = $this->entityManager->getRepository(ValidationCode::class)->find($code);
        if ($existing !== null) {
            throw new \InvalidArgumentException('A registration code with this value already exists.');
        }

        $group = $this->entityManager->getRepository(Group::class)->find($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException('Group not found.');
        }

        $vc = new ValidationCode();
        $vc->setCode($code);
        $vc->setGroup($group);

        $this->entityManager->persist($vc);
        $this->entityManager->flush();

        return [
            'id'         => $vc->getCode() ?? $code,
            'code'       => $vc->getCode() ?? $code,
            'id_groups'  => $group->getId() ?? $groupId,
            'group_name' => $group->getName(),
            'created_at' => $vc->getCreated()->format(\DateTimeInterface::ATOM),
        ];
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
