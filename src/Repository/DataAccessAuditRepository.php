<?php

namespace App\Repository;

use App\Entity\DataAccessAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

/**
 * Data Access Audit Repository
 *
 * Handles database operations for data access audit logs
 * Provides filtering and pagination for audit management APIs
 */
class DataAccessAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataAccessAudit::class);
    }

    /**
     * Find audit logs with filtering and pagination
     * Returns array results for memory efficiency
     */
    public function findAuditLogs(
        ?int $userId = null,
        ?string $resourceType = null,
        ?string $action = null,
        ?string $permissionResult = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $httpMethod = null,
        int $page = 1,
        int $pageSize = 20
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->select([
                'a.id',
                'a.idUsers',
                'a.idResourceTypes',
                'a.resourceId',
                'a.idActions',
                'a.idPermissionResults',
                'a.crudPermission',
                'a.httpMethod',
                'a.requestBodyHash',
                'a.ipAddress',
                'a.userAgent',
                'a.requestUri',
                'a.notes',
                'a.createdAt',
                'u.user_name as username',
                'u.email',
                'u.name',
                'rt.lookupValue as resourceTypeName',
                'rt.lookupCode as resourceTypeCode',
                'act.lookupValue as actionName',
                'act.lookupCode as actionCode',
                'pr.lookupValue as permissionResultName',
                'pr.lookupCode as permissionResultCode'
            ])
            ->leftJoin('a.user', 'u')
            ->leftJoin('a.resourceType', 'rt')
            ->leftJoin('a.action', 'act')
            ->leftJoin('a.permissionResult', 'pr');

        // Apply filters
        if ($userId !== null) {
            $qb->andWhere('a.idUsers = :userId')
               ->setParameter('userId', $userId);
        }

        if ($resourceType !== null) {
            $qb->andWhere('rt.lookupCode = :resourceType')
               ->setParameter('resourceType', $resourceType);
        }

        if ($action !== null) {
            $qb->andWhere('act.lookupCode = :action')
               ->setParameter('action', $action);
        }

        if ($permissionResult !== null) {
            $qb->andWhere('pr.lookupCode = :permissionResult')
               ->setParameter('permissionResult', $permissionResult);
        }

        if ($dateFrom !== null) {
            $qb->andWhere('a.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo !== null) {
            $qb->andWhere('a.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        if ($httpMethod !== null) {
            $qb->andWhere('a.httpMethod = :httpMethod')
               ->setParameter('httpMethod', $httpMethod);
        }

        // Order by creation date descending
        $qb->orderBy('a.createdAt', 'DESC');

        // Get total count with separate optimized query
        $countQb = clone $qb;
        $countQb->select('COUNT(a.id)')
                ->resetDQLPart('orderBy')
                ->setFirstResult(null)
                ->setMaxResults(null);
        $totalCount = (int) $countQb->getQuery()->getSingleScalarResult();

        // Apply pagination and get results
        $qb->setFirstResult(($page - 1) * $pageSize)
           ->setMaxResults($pageSize);

        $results = $qb->getQuery()->getArrayResult();

        return [
            'data' => $results,
            'total' => $totalCount,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => (int) ceil($totalCount / $pageSize)
        ];
    }

    /**
     * Get audit statistics
     */
    public function getAuditStatistics(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select([
                'COUNT(a.id) as totalLogs',
                'SUM(CASE WHEN pr.lookupCode = :denied THEN 1 ELSE 0 END) as deniedAttempts',
                'COUNT(DISTINCT a.resourceId) as uniqueResources',
                'COUNT(DISTINCT a.idUsers) as uniqueUsers'
            ])
            ->leftJoin('a.permissionResult', 'pr');

        $qb->setParameter('denied', 'denied');

        if ($dateFrom !== null) {
            $qb->andWhere('a.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo !== null) {
            $qb->andWhere('a.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        $result = $qb->getQuery()->getSingleResult();

        // Get most accessed resources
        $mostAccessedResources = $this->getMostAccessedResources($dateFrom, $dateTo);

        // Get recent denied attempts
        $recentDeniedAttempts = $this->getRecentDeniedAttempts();

        return [
            'totalLogs' => (int) $result['totalLogs'],
            'deniedAttempts' => (int) $result['deniedAttempts'],
            'uniqueResources' => (int) $result['uniqueResources'],
            'uniqueUsers' => (int) $result['uniqueUsers'],
            'mostAccessedResources' => $mostAccessedResources,
            'recentDeniedAttempts' => $recentDeniedAttempts
        ];
    }

    /**
     * Get most accessed resources
     */
    private function getMostAccessedResources(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select([
                'rt.lookupValue as resourceType',
                'a.resourceId',
                'COUNT(a.id) as accessCount'
            ])
            ->leftJoin('a.resourceType', 'rt')
            ->groupBy('rt.lookupValue, a.resourceId')
            ->orderBy('accessCount', 'DESC')
            ->setMaxResults(10);

        if ($dateFrom !== null) {
            $qb->andWhere('a.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo !== null) {
            $qb->andWhere('a.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get recent denied attempts
     * Uses array results for memory efficiency
     */
    private function getRecentDeniedAttempts(): array
    {
        return $this->createQueryBuilder('a')
            ->select([
                'a.id',
                'a.idUsers',
                'a.resourceId',
                'a.crudPermission',
                'a.httpMethod',
                'a.ipAddress',
                'a.createdAt',
                'u.user_name as username',
                'u.email',
                'u.name',
                'rt.lookupValue as resourceTypeName',
                'act.lookupValue as actionName',
                'pr.lookupValue as permissionResultName'
            ])
            ->leftJoin('a.user', 'u')
            ->leftJoin('a.resourceType', 'rt')
            ->leftJoin('a.action', 'act')
            ->leftJoin('a.permissionResult', 'pr')
            ->where('pr.lookupCode = :denied')
            ->setParameter('denied', 'denied')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Find audit log by ID with relationships
     * Returns array result for memory efficiency
     */
    public function findAuditLogById(int $id): ?array
    {
        $result = $this->createQueryBuilder('a')
            ->select([
                'a.id',
                'a.idUsers',
                'a.idResourceTypes',
                'a.resourceId',
                'a.idActions',
                'a.idPermissionResults',
                'a.crudPermission',
                'a.httpMethod',
                'a.requestBodyHash',
                'a.ipAddress',
                'a.userAgent',
                'a.requestUri',
                'a.notes',
                'a.createdAt',
                'u.user_name as username',
                'u.email',
                'u.name',
                'rt.lookupValue as resourceTypeName',
                'rt.lookupCode as resourceTypeCode',
                'act.lookupValue as actionName',
                'act.lookupCode as actionCode',
                'pr.lookupValue as permissionResultName',
                'pr.lookupCode as permissionResultCode'
            ])
            ->leftJoin('a.user', 'u')
            ->leftJoin('a.resourceType', 'rt')
            ->leftJoin('a.action', 'act')
            ->leftJoin('a.permissionResult', 'pr')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getArrayResult();

        return $result[0] ?? null;
    }
}
