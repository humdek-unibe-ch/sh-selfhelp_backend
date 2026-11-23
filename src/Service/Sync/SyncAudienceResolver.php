<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Service\Core\LookupService;
use App\Service\Mercure\MercureTopicResolver;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who learns about a change (live-sync contract C3), as Mercure topics.
 *
 * Groups see, roles edit: the people who may SEE a page or a table's data are
 * reached through the groups with `select` on a page (and `public` for an open
 * page); the people who may EDIT it through the roles with a grant on the
 * resource and the superuser roles. Own records go to their owner only.
 *
 * Every answer is read from the ACL rows (`page_acl_groups`, `role_data_access`,
 * `roles.is_superuser`) with one query each, never a loop over members or
 * holders. Nothing is kept between calls, so a role or ACL change made a moment
 * ago is always seen.
 */
final class SyncAudienceResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LookupService $lookupService,
        private readonly MercureTopicResolver $topics,
        private readonly PageTableReadIndex $tableReads,
    ) {
    }

    /**
     * Roles with a grant on the resource, and the superuser roles: the people
     * who edit it.
     *
     * @param string $resourceType a `LookupService::RESOURCE_TYPES_*` code
     * @return list<string>
     */
    public function editorTopics(string $resourceType, int $resourceId): array
    {
        $roleIds = array_merge($this->roleIdsWithGrant($resourceType, $resourceId), $this->superuserRoleIds());

        return $this->roleTopics($roleIds);
    }

    /**
     * Who sees a published page: groups with `select`, `public` when the page is
     * open to everyone.
     *
     * @return list<string>
     */
    public function pageViewerTopics(int $pageId): array
    {
        $topics = [];
        foreach ($this->groupIdsWithSelect($pageId) as $groupId) {
            $topics[] = $this->topics->syncGroupTopic($groupId);
        }
        if ($this->isOpenAccess($pageId)) {
            $topics[] = $this->topics->syncPublicTopic();
        }

        return $topics;
    }

    /**
     * Who learns about a change of a data table: its owner, the editors of the
     * table, and the viewers of every page that shows the table to all users.
     *
     * @return list<string>
     */
    public function tableTopics(int $tableId, ?int $ownerUserId): array
    {
        $topics = $this->editorTopics(LookupService::RESOURCE_TYPES_DATA_TABLE, $tableId);
        if ($ownerUserId !== null && $ownerUserId > 0) {
            $topics[] = $this->topics->syncUserTopic($ownerUserId);
        }
        foreach ($this->tableReads->pagesReadingForAllUsers($tableId) as $pageId) {
            $topics = array_merge($topics, $this->pageViewerTopics($pageId));
        }

        return array_values(array_unique($topics));
    }

    /**
     * @return list<int>
     */
    public function superuserRoleIds(): array
    {
        return $this->intColumn(
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM roles WHERE is_superuser = 1'),
        );
    }

    /**
     * @return list<int>
     */
    public function roleIdsWithGrant(string $resourceType, int $resourceId): array
    {
        $typeId = $this->lookupService->getLookupIdByCode(LookupService::RESOURCE_TYPES, $resourceType);
        if ($typeId === null) {
            return [];
        }

        return $this->intColumn($this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT id_roles FROM role_data_access'
            . ' WHERE id_resource_types = :type AND resource_id = :resource AND crud_permissions > 0',
            ['type' => $typeId, 'resource' => $resourceId],
            ['type' => ParameterType::INTEGER, 'resource' => ParameterType::INTEGER],
        ));
    }

    /**
     * @return list<int>
     */
    public function groupIdsWithSelect(int $pageId): array
    {
        return $this->intColumn($this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id_groups FROM page_acl_groups WHERE id_pages = :page AND acl_select = 1',
            ['page' => $pageId],
            ['page' => ParameterType::INTEGER],
        ));
    }

    public function isOpenAccess(int $pageId): bool
    {
        $value = $this->entityManager->getConnection()->fetchOne(
            'SELECT is_open_access FROM pages WHERE id = :page',
            ['page' => $pageId],
            ['page' => ParameterType::INTEGER],
        );

        return is_numeric($value) && (int) $value === 1;
    }

    /**
     * @param list<int> $roleIds
     * @return list<string>
     */
    private function roleTopics(array $roleIds): array
    {
        $topics = [];
        foreach (array_unique($roleIds) as $roleId) {
            $topics[] = $this->topics->syncRoleTopic($roleId);
        }

        return $topics;
    }

    /**
     * @param list<mixed> $column
     * @return list<int>
     */
    private function intColumn(array $column): array
    {
        $ids = [];
        foreach ($column as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
