<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Users;

use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Answers which users a caller manages (contract C20) with the data-access
 * rule the admin screens already use for groups.
 *
 *  - {@see mayManage()} asks {@see DataAccessSecurityService::hasPermission()}
 *    for each group of the user, exactly like `AdminUserService::canAccessUser`,
 *    so a refusal is audited the same way and a role that holds the bit through
 *    several roles counts as one.
 *  - {@see managedUserIds()} is the same rule as one set query: the groups on
 *    which the union of the caller's role grants holds the update bit, and the
 *    members of those groups. A listing is not a decision about one user, so it
 *    writes no audit row, like the admin user list.
 *
 * Nothing is cached here: the grant rows are read when asked (rule 3.5, a yes or
 * no about access is never cached).
 */
final class PluginUserScope implements PluginUserScopeInterface
{
    private const PERMISSION_BITS = [
        DataAccessSecurityService::PERMISSION_CREATE,
        DataAccessSecurityService::PERMISSION_READ,
        DataAccessSecurityService::PERMISSION_UPDATE,
        DataAccessSecurityService::PERMISSION_DELETE,
    ];

    /** The most users one call returns. */
    public const MAX_LIMIT = 10000;

    public function __construct(
        private readonly Connection $connection,
        private readonly LookupService $lookupService,
        private readonly DataAccessSecurityService $dataAccess,
    ) {
    }

    public function mayManage(int $callerId, int $userId, int $bit = DataAccessSecurityService::PERMISSION_UPDATE): bool
    {
        if (!in_array($bit, self::PERMISSION_BITS, true)) {
            throw new \InvalidArgumentException(sprintf('"%d" is not a permission bit (1 create, 2 read, 4 update, 8 delete).', $bit));
        }
        if ($callerId <= 0 || $userId <= 0) {
            return false;
        }
        if ($callerId === $userId || $this->dataAccess->userIsSuperuser($callerId)) {
            return true;
        }

        foreach ($this->groupIdsOf($userId) as $groupId) {
            if ($this->dataAccess->hasPermission($callerId, LookupService::RESOURCE_TYPES_GROUP, $groupId, $bit)) {
                return true;
            }
        }

        return false;
    }

    public function managedUserIds(int $callerId, ?array $groupIds = null, int $limit = 1000): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf('The limit must be 1 to %d, "%d" given.', self::MAX_LIMIT, $limit));
        }
        if ($callerId <= 0 || $groupIds === []) {
            return [];
        }

        $groupFilter = $groupIds === null ? null : array_values(array_unique(array_map('intval', $groupIds)));

        if ($this->dataAccess->userIsSuperuser($callerId)) {
            $ids = $groupFilter === null
                ? $this->connection->fetchFirstColumn(sprintf('SELECT id FROM users ORDER BY id LIMIT %d', $limit))
                : $this->connection->fetchFirstColumn(
                    sprintf('SELECT ug.id_users FROM rel_groups_users ug WHERE ug.id_groups IN (:groups) GROUP BY ug.id_users ORDER BY ug.id_users LIMIT %d', $limit),
                    ['groups' => $groupFilter],
                    ['groups' => ArrayParameterType::INTEGER],
                );

            return self::toIds($ids);
        }

        $groupTypeId = $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, LookupService::RESOURCE_TYPES_GROUP);
        if ($groupTypeId === null) {
            return [];
        }

        $bit = DataAccessSecurityService::PERMISSION_UPDATE;
        $sql = 'SELECT ug.id_users FROM rel_groups_users ug'
            . ' INNER JOIN ('
            . 'SELECT rda.resource_id AS id_groups FROM role_data_access rda'
            . ' INNER JOIN rel_roles_users ru ON ru.id_roles = rda.id_roles'
            . ' WHERE ru.id_users = :caller AND rda.id_resource_types = :type AND rda.crud_permissions > 0'
            . sprintf(' GROUP BY rda.resource_id HAVING (BIT_OR(rda.crud_permissions) & %d) = %d', $bit, $bit)
            . ') granted ON granted.id_groups = ug.id_groups';
        $params = ['caller' => $callerId, 'type' => $groupTypeId];
        $types = [];
        if ($groupFilter !== null) {
            $sql .= ' WHERE ug.id_groups IN (:groups)';
            $params['groups'] = $groupFilter;
            $types['groups'] = ArrayParameterType::INTEGER;
        }
        $sql .= sprintf(' GROUP BY ug.id_users ORDER BY ug.id_users LIMIT %d', $limit);

        return self::toIds($this->connection->fetchFirstColumn($sql, $params, $types));
    }

    /**
     * @return list<int>
     */
    private function groupIdsOf(int $userId): array
    {
        return self::toIds($this->connection->fetchFirstColumn(
            'SELECT id_groups FROM rel_groups_users WHERE id_users = :user ORDER BY id_groups',
            ['user' => $userId],
        ));
    }

    /**
     * @param list<mixed> $column
     * @return list<int>
     */
    private static function toIds(array $column): array
    {
        $ids = [];
        foreach ($column as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
