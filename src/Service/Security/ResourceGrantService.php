<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Plugin\Resource\RegisteredResourceTypes;
use App\Repository\RoleDataAccessRepository;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The grants of a resource, removed when the resource goes (F280).
 *
 * `role_data_access` has no foreign key to the resource it points at (the
 * type decides which table `resource_id` means), so deleting a page, a
 * group, a data table, an asset or a plugin item leaves its grant rows
 * behind. Rule 4.6f counts every row of a role, so a row for a resource that
 * no longer exists would still count as something the role holds. One place
 * removes them: delete paths call {@see revokeAll()} inside their
 * transaction and then drop the cached rights of every returned role after
 * the commit.
 */
final class ResourceGrantService
{
    /** Resource types of the core; every other type must be registered by an enabled plugin. */
    private const CORE_TYPES = [
        LookupService::RESOURCE_TYPES_PAGES,
        LookupService::RESOURCE_TYPES_GROUP,
        LookupService::RESOURCE_TYPES_DATA_TABLE,
        LookupService::RESOURCE_TYPES_ASSET,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly LookupService $lookupService,
        private readonly RegisteredResourceTypes $registeredTypes,
        private readonly TransactionService $transactionService,
    ) {
    }

    /**
     * Remove every grant on one resource.
     *
     * Each removed grant is written to the transaction log like any other grant
     * change. The rows go with the caller's open transaction; the caller
     * must call `DataAccessSecurityService::invalidateRolePermissions()` for
     * each returned role once that transaction has committed (so the
     * roles' access stamp and the `acl` notice follow, F250).
     *
     * @param string $typeCode a core type (`pages`, `group`, `data_table`, `asset`) or a type an enabled plugin registers
     * @return list<int> ids of the roles that lost a grant, sorted, without duplicates
     * @throws \InvalidArgumentException for an unknown type or an id that is not positive (a programming error)
     */
    public function revokeAll(string $typeCode, int $resourceId): array
    {
        if ($resourceId <= 0) {
            throw new \InvalidArgumentException(sprintf('A resource id must be positive, got %d.', $resourceId));
        }
        $typeId = $this->typeId($typeCode);

        $grants = $this->roleDataAccessRepository->findBy(['idResourceTypes' => $typeId, 'resourceId' => $resourceId]);
        if ($grants === []) {
            return [];
        }

        $removed = [];
        foreach ($grants as $grant) {
            // The association, not the scalar mirror: a grant made earlier in the same request has no `idRoles` yet (F80).
            $removed[] = ['id' => (int) $grant->getId(), 'role' => (int) $grant->getRole()->getId(), 'bits' => $grant->getCrudPermissions()];
            $this->entityManager->remove($grant);
        }
        $this->entityManager->flush();

        $roleIds = [];
        foreach ($removed as $row) {
            $roleIds[$row['role']] = $row['role'];
            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_DELETE,
                LookupService::TRANSACTION_BY_BY_USER,
                'role_data_access',
                $row['id'],
                false,
                sprintf(
                    'Grant removed with its resource: role %d, %s %d (rights %d)',
                    $row['role'],
                    $typeCode,
                    $resourceId,
                    $row['bits']
                )
            );
        }

        $ids = array_values($roleIds);
        sort($ids);

        return $ids;
    }

    private function typeId(string $typeCode): int
    {
        $id = in_array($typeCode, self::CORE_TYPES, true)
            ? $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, $typeCode)
            : $this->registeredTypes->lookupId($typeCode);
        if ($id === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a resource type of the core or of an enabled plugin.', $typeCode));
        }

        return $id;
    }
}
