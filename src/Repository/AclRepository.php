<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Repository;

use App\Service\Cache\Core\CacheService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Page;

/**
 * Repository for ACL operations
 * Uses the unified CacheService for caching ACL data
 *
 * @extends ServiceEntityRepository<Page>
 */
class AclRepository extends ServiceEntityRepository
{

    public function __construct(
        ManagerRegistry $registry,
        private readonly CacheService $cache
    ) {
        parent::__construct($registry, Page::class);
    }

    /**
     * Get all ACLs for a user, optionally filtered by page
     * 
     * Uses unified CacheService for caching ACL data within permissions category
     * 
     * @param int $userId User ID to get ACLs for
     * @param int $pageId Optional page ID to filter by (-1 for all pages)
     * @return list<array<string, mixed>> Array of page ACLs with access rights
     */
    public function getUserAcl(int $userId, ?int $pageId = -1): array
    {
        $aclCache = $this->cache
            ->withCategory(CacheService::CATEGORY_PERMISSIONS)
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId);
        if ($pageId > 0) {
            $aclCache->withEntityScope(CacheService::ENTITY_SCOPE_PAGE, $pageId);
        }
        return $aclCache
            ->withEntityScope(CacheService::ENTITY_SCOPE_USER, $userId)
            ->getList("user_acl_{$pageId}", function () use ($userId, $pageId) {
                $conn = $this->getEntityManager()->getConnection();

                // Call the stored procedure directly
                $sql = 'CALL get_user_acl(:userId, :pageId)';
                $stmt = $conn->prepare($sql);
                $stmt->bindValue('userId', $userId, \Doctrine\DBAL\ParameterType::INTEGER);
                $stmt->bindValue('pageId', $pageId, \Doctrine\DBAL\ParameterType::INTEGER);

                return $stmt->executeQuery()->fetchAllAssociative();
            });
    }

    /**
     * Every page with full rights, in the shape {@see getUserAcl()} returns.
     *
     * For the admin role, which may open any page by its address
     * (`ACLService::hasAccess`): menus, search and page lists must answer
     * "may this caller see the page?" the same way (F89). Not cached here; the
     * caller caches it in the permissions category, which a page change clears.
     *
     * @return list<array<string, mixed>>
     */
    public function getFullAccessToAllPages(int $userId): array
    {
        $sql = <<<'SQL'
            SELECT
                CAST(:userId AS SIGNED) AS id_users,
                p.id AS id_pages,
                1 AS acl_select,
                1 AS acl_insert,
                1 AS acl_update,
                1 AS acl_delete,
                p.keyword,
                p.url,
                p.id_parent_page,
                p.is_headless,
                p.id_page_types,
                p.id_page_access_types,
                p.is_system
            FROM pages p
            SQL;

        return $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['userId' => $userId])
            ->fetchAllAssociative();
    }

}
