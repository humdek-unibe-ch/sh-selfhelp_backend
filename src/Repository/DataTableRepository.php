<?php

namespace App\Repository;

use App\Service\Cache\Core\CacheService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PDO;
use App\Entity\DataTable;
use App\Entity\User;

class DataTableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly CacheService $cache)
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
     * Calls the stored procedure get_dataTable_with_filter and returns the result.
     */
    public function getDataTableWithFilter(int $tableId, int $userId, string $filter, bool $excludeDeleted, int $languageId = 1, string $timezoneCode = 'UTC'): array
    {
        $cache = $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId);
        if ($userId > 0) {
            $cache->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
        }

        // Sanitize the filter parameter for cache key usage
        $sanitizedFilter = $this->sanitizeFilterForCacheKey($filter);
        $sanitizedTimezone = $this->sanitizeFilterForCacheKey($timezoneCode);

        return $cache
            ->getList("data_table_with_filter_{$tableId}_{$userId}_{$sanitizedFilter}_{$excludeDeleted}_{$languageId}_{$sanitizedTimezone}", function () use ($tableId, $userId, $filter, $excludeDeleted, $languageId, $timezoneCode) {
                $conn = $this->getEntityManager()->getConnection();
                $sql = 'CALL get_dataTable_with_filter(:tableId, :userId, :filter, :excludeDeleted, :languageId, :timezoneCode)';
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('tableId', $tableId, PDO::PARAM_INT);
                $stmt->bindValue('userId', $userId, PDO::PARAM_INT);
                $stmt->bindValue('filter', $filter, PDO::PARAM_STR);
                $stmt->bindValue('excludeDeleted', $excludeDeleted, PDO::PARAM_BOOL);
                $stmt->bindValue('languageId', $languageId, PDO::PARAM_INT);
                $stmt->bindValue('timezoneCode', $timezoneCode, PDO::PARAM_STR);
                $result = $stmt->executeQuery();

                return $result->fetchAllAssociative();
            });
    }

    /**
     * Calls the stored procedure get_dataTable_with_user_group_filter for group-based user filtering.
     * Used for non-admin users who should only see data from users in their accessible groups.
     * The stored procedure internally determines accessible users based on current user's permissions.
     */
    public function getDataTableWithUserGroupFilter(int $tableId, int $currentUserId, string $filter, bool $excludeDeleted, int $languageId = 1, string $timezoneCode = 'UTC'): array
    {
        $cache = $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_DATA_TABLE, $tableId)            
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $currentUserId);

        $user = $this->getEntityManager()->getRepository(User::class)->find($currentUserId);
        if ($user) {
            foreach ($user->getUserRoles() as $role) {
                $cache->withEntityScope(CacheService::ENTITY_SCOPE_ROLE, $role->getId());
            }
        }

        // Sanitize the filter parameter for cache key usage
        $sanitizedFilter = $this->sanitizeFilterForCacheKey($filter);
        $sanitizedTimezone = $this->sanitizeFilterForCacheKey($timezoneCode);

        return $cache
            ->getList("data_table_with_user_group_filter_{$tableId}_{$currentUserId}_{$sanitizedFilter}_{$excludeDeleted}_{$languageId}_{$sanitizedTimezone}", function () use ($tableId, $currentUserId, $filter, $excludeDeleted, $languageId, $timezoneCode) {
                $conn = $this->getEntityManager()->getConnection();
                $sql = 'CALL get_dataTable_with_user_group_filter(:tableId, :currentUserId, :filter, :excludeDeleted, :languageId, :timezoneCode)';
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('tableId', $tableId, PDO::PARAM_INT);
                $stmt->bindValue('currentUserId', $currentUserId, PDO::PARAM_INT);
                $stmt->bindValue('filter', $filter, PDO::PARAM_STR);
                $stmt->bindValue('excludeDeleted', $excludeDeleted, PDO::PARAM_BOOL);
                $stmt->bindValue('languageId', $languageId, PDO::PARAM_INT);
                $stmt->bindValue('timezoneCode', $timezoneCode, PDO::PARAM_STR);
                $result = $stmt->executeQuery();

                return $result->fetchAllAssociative();
            });
    }

    /**
     * Calls the stored procedure get_dataTable_with_all_languages and returns the result.
     * This procedure returns all languages for each record (no language filtering).
     */
    public function getDataTableWithAllLanguages(int $tableId, int $userId, string $filter, bool $excludeDeleted, string $timezoneCode = 'UTC'): array
    {
        $cache = $this->cache
            ->withCategory(CacheService::CATEGORY_DATA_TABLES)
            ->withEntityScope(entityType: CacheService::ENTITY_SCOPE_DATA_TABLE, entityId: $tableId);
        if ($userId > 0) {
            $cache->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
        }

        // Sanitize the filter parameter for cache key usage
        $sanitizedFilter = $this->sanitizeFilterForCacheKey($filter);
        $sanitizedTimezone = $this->sanitizeFilterForCacheKey($timezoneCode);

        return $cache
            ->getList("data_table_with_all_languages_{$tableId}_{$userId}_{$sanitizedFilter}_{$excludeDeleted}_{$sanitizedTimezone}", function () use ($tableId, $userId, $filter, $excludeDeleted, $timezoneCode) {
                $conn = $this->getEntityManager()->getConnection();
                $sql = 'CALL get_dataTable_with_all_languages(:tableId, :userId, :filter, :excludeDeleted, :timezoneCode)';
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('tableId', $tableId, PDO::PARAM_INT);
                $stmt->bindValue('userId', $userId, PDO::PARAM_INT);
                $stmt->bindValue('filter', $filter, PDO::PARAM_STR);
                $stmt->bindValue('excludeDeleted', $excludeDeleted, PDO::PARAM_BOOL);
                $stmt->bindValue('timezoneCode', $timezoneCode, PDO::PARAM_STR);
                $result = $stmt->executeQuery();

                return $result->fetchAllAssociative();
            });
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
        return $dataTable->getId();
    }
}
