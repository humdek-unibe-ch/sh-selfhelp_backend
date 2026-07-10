<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Service\CMS\Common\DataTableFilterService;
use App\Service\Cache\Core\CacheService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\DataTable;
use App\Entity\User;

/**
 * @extends ServiceEntityRepository<DataTable>
 */
class DataTableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly CacheService $cache, private readonly DataTableFilterService $dataTableFilterService)
    {
        parent::__construct($registry, DataTable::class);
    }

    /**
     * Sanitize filter parameter for use in cache keys by replacing reserved characters
     */
    private function sanitizeFilterForCacheKey(string $filter): string
    {
        // Replace reserved characters that are not allowed in cache tags
        return str_replace(
            ['(', ')', ' ', '=', '>', '<', '!', '&', '|', '*', '?', '{', '}', '[', ']', '@', ':', '/', '\\'],
            ['_LPAR_', '_RPAR_', '_SPACE_', '_EQ_', '_GT_', '_LT_', '_NOT_', '_AND_', '_OR_', '_STAR_', '_QMARK_', '_LBRACE_', '_RBRACE_', '_LBRACKET_', '_RBRACKET_', '_AT_', '_COLON_', '_SLASH_', '_BSLASH_'],
            $filter
        );
    }

    /**
     * Calls the stored procedure get_data_table_filtered and returns the result.
     *
     * @return list<array<string, mixed>>
     */
    public function getDataTableWithFilter(
        int $tableId,
        int $userId,
        string $filter,
        bool $excludeDeleted,
        int $languageId = 1,
        string $timezoneCode = 'UTC',
        string $selectedColumns = '',
    ): array {
        $filter = $this->dataTableFilterService->guardForStoredProcedure($filter);
        $selectedColumns = $this->dataTableFilterService->sanitizeSelectedColumns($selectedColumns);
        $cache = $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId);
        if ($userId > 0) {
            // withEntityScope returns a clone - reassign so the user
            // scope is actually applied to the cache key.
            $cache = $cache->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
        }

        // Sanitize the filter parameter for cache key usage
        $sanitizedFilter = $this->sanitizeFilterForCacheKey($filter);
        $sanitizedTimezone = $this->sanitizeFilterForCacheKey($timezoneCode);
        $sanitizedColumns = $this->sanitizeFilterForCacheKey($selectedColumns);

        /** @var list<array<string, mixed>> $rows */
        $rows = $cache
            ->getList("data_table_with_filter_{$tableId}_{$userId}_{$sanitizedFilter}_{$excludeDeleted}_{$languageId}_{$sanitizedTimezone}_{$sanitizedColumns}", function () use ($tableId, $userId, $filter, $excludeDeleted, $languageId, $timezoneCode, $selectedColumns) {
                $conn = $this->getEntityManager()->getConnection();
                $sql = 'CALL get_data_table_filtered(:tableId, :userId, :filter, :excludeDeleted, :languageId, :timezoneCode, :selectedColumns)';
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('tableId', $tableId, ParameterType::INTEGER);
                $stmt->bindValue('userId', $userId, ParameterType::INTEGER);
                $stmt->bindValue('filter', $filter, ParameterType::STRING);
                $stmt->bindValue('excludeDeleted', $excludeDeleted, ParameterType::BOOLEAN);
                $stmt->bindValue('languageId', $languageId, ParameterType::INTEGER);
                $stmt->bindValue('timezoneCode', $timezoneCode, ParameterType::STRING);
                $stmt->bindValue('selectedColumns', $selectedColumns, ParameterType::STRING);
                $result = $stmt->executeQuery();

                return $result->fetchAllAssociative();
            });

        return $rows;
    }

    /**
     * Calls the stored procedure get_data_table_for_user_groups for group-based user filtering.
     * Used for non-admin users who should only see data from users in their accessible groups.
     * The stored procedure internally determines accessible users based on current user's permissions.
     *
     * @return list<array<string, mixed>>
     */
    public function getDataTableWithUserGroupFilter(int $tableId, int $currentUserId, string $filter, bool $excludeDeleted, int $languageId = 1, string $timezoneCode = 'UTC'): array
    {
        $filter = $this->dataTableFilterService->guardForStoredProcedure($filter);
        $cache = $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId)            
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $currentUserId);

        $user = $this->getEntityManager()->getRepository(User::class)->find($currentUserId);
        if ($user) {
            foreach ($user->getUserRoles() as $role) {
                $roleId = $role->getId();
                assert($roleId !== null);
                // withEntityScope returns a clone - reassign so every
                // role scope is actually folded into the cache key.
                $cache = $cache->withEntityScope(CacheService::ENTITY_SCOPE_ROLE, $roleId);
            }
        }

        // Sanitize the filter parameter for cache key usage
        $sanitizedFilter = $this->sanitizeFilterForCacheKey($filter);
        $sanitizedTimezone = $this->sanitizeFilterForCacheKey($timezoneCode);

        /** @var list<array<string, mixed>> $rows */
        $rows = $cache
            ->getList("data_table_with_user_group_filter_{$tableId}_{$currentUserId}_{$sanitizedFilter}_{$excludeDeleted}_{$languageId}_{$sanitizedTimezone}", function () use ($tableId, $currentUserId, $filter, $excludeDeleted, $languageId, $timezoneCode) {
                $conn = $this->getEntityManager()->getConnection();
                $sql = 'CALL get_data_table_for_user_groups(:tableId, :currentUserId, :filter, :excludeDeleted, :languageId, :timezoneCode)';
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('tableId', $tableId, ParameterType::INTEGER);
                $stmt->bindValue('currentUserId', $currentUserId, ParameterType::INTEGER);
                $stmt->bindValue('filter', $filter, ParameterType::STRING);
                $stmt->bindValue('excludeDeleted', $excludeDeleted, ParameterType::BOOLEAN);
                $stmt->bindValue('languageId', $languageId, ParameterType::INTEGER);
                $stmt->bindValue('timezoneCode', $timezoneCode, ParameterType::STRING);
                $result = $stmt->executeQuery();

                return $result->fetchAllAssociative();
            });

        return $rows;
    }

    /**
     * Calls the stored procedure get_data_table_all_languages and returns the result.
     * This procedure returns all languages for each record (no language filtering).
     *
     * @return list<array<string, mixed>>
     */
    public function getDataTableWithAllLanguages(int $tableId, int $userId, string $filter, bool $excludeDeleted, string $timezoneCode = 'UTC'): array
    {
        $filter = $this->dataTableFilterService->guardForStoredProcedure($filter);
        $cache = $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(entityType: CacheService::ENTITY_SCOPE_DATA_TABLE, entityId: $tableId);
        if ($userId > 0) {
            $cache = $cache->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
        }

        // Sanitize the filter parameter for cache key usage
        $sanitizedFilter = $this->sanitizeFilterForCacheKey($filter);
        $sanitizedTimezone = $this->sanitizeFilterForCacheKey($timezoneCode);

        /** @var list<array<string, mixed>> $rows */
        $rows = $cache
            ->getList("data_table_with_all_languages_{$tableId}_{$userId}_{$sanitizedFilter}_{$excludeDeleted}_{$sanitizedTimezone}", function () use ($tableId, $userId, $filter, $excludeDeleted, $timezoneCode) {
                $conn = $this->getEntityManager()->getConnection();
                $sql = 'CALL get_data_table_all_languages(:tableId, :userId, :filter, :excludeDeleted, :timezoneCode)';
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('tableId', $tableId, ParameterType::INTEGER);
                $stmt->bindValue('userId', $userId, ParameterType::INTEGER);
                $stmt->bindValue('filter', $filter, ParameterType::STRING);
                $stmt->bindValue('excludeDeleted', $excludeDeleted, ParameterType::BOOLEAN);
                $stmt->bindValue('timezoneCode', $timezoneCode, ParameterType::STRING);
                $result = $stmt->executeQuery();

                return $result->fetchAllAssociative();
            });

        return $rows;
    }

    /**
     * Get data table id by name
     *
     * @param string $name Data table name
     * @return int Data table id
     */
    public function getDataTableIdByName(string $name): int
    {
        $dataTable = $this->findOneBy(['name' => $name]);
        assert($dataTable !== null);
        $id = $dataTable->getId();
        assert($id !== null);

        return $id;
    }
}
