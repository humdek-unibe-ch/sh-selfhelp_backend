<?php

namespace App\Repository;

use App\Entity\RoleDataAccess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Role Data Access Repository
 *
 * Handles database operations for role-based data access permissions
 * Provides methods for permission checking and management
 */
class RoleDataAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoleDataAccess::class);
    }

    /**
     * Get unified permissions for a user across all their roles
     * Returns BIT_OR aggregated permissions grouped by resource type and resource ID
     */
    public function getUserUnifiedPermissions(int $userId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                rda.id_resourceTypes,
                rda.resource_id,
                BIT_OR(rda.crud_permissions) as unified_permissions
            FROM role_data_access rda
            INNER JOIN users_roles ur ON ur.id_roles = rda.id_roles
            WHERE ur.id_users = :user_id
            GROUP BY rda.id_resourceTypes, rda.resource_id
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
     * Get permissions for a specific resource type
     */
    public function getUserPermissionsForResourceType(int $userId, int $resourceTypeId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                rda.resource_id,
                BIT_OR(rda.crud_permissions) as unified_permissions
            FROM role_data_access rda
            INNER JOIN users_roles ur ON ur.id_roles = rda.id_roles
            WHERE ur.id_users = :user_id
                AND rda.id_resourceTypes = :resource_type_id
            GROUP BY rda.resource_id
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('resource_type_id', $resourceTypeId, \PDO::PARAM_INT);
        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
     * Get permissions for a specific resource
     */
    public function getUserPermissionsForResource(int $userId, int $resourceTypeId, int $resourceId): ?int
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT BIT_OR(rda.crud_permissions) as unified_permissions
            FROM role_data_access rda
            INNER JOIN users_roles ur ON ur.id_roles = rda.id_roles
            WHERE ur.id_users = :user_id
                AND rda.id_resourceTypes = :resource_type_id
                AND rda.resource_id = :resource_id
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('resource_type_id', $resourceTypeId, \PDO::PARAM_INT);
        $stmt->bindValue('resource_id', $resourceId, \PDO::PARAM_INT);
        $result = $stmt->executeQuery();

        $row = $result->fetchAssociative();

        return $row ? (int) $row['unified_permissions'] : null;
    }

    /**
     * Get all permissions for a role
     */
    public function getRolePermissions(int $roleId): array
    {
        return $this->createQueryBuilder('rda')
            ->leftJoin('rda.resourceType', 'rt')
            ->addSelect('rt')
            ->where('rda.idRoles = :roleId')
            ->setParameter('roleId', $roleId)
            ->orderBy('rda.idResourceTypes', 'ASC')
            ->addOrderBy('rda.resourceId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find permission by role, resource type, and resource ID
     */
    public function findPermission(int $roleId, int $resourceTypeId, int $resourceId): ?RoleDataAccess
    {
        return $this->createQueryBuilder('rda')
            ->where('rda.idRoles = :roleId')
            ->andWhere('rda.idResourceTypes = :resourceTypeId')
            ->andWhere('rda.resourceId = :resourceId')
            ->setParameter('roleId', $roleId)
            ->setParameter('resourceTypeId', $resourceTypeId)
            ->setParameter('resourceId', $resourceId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all roles with their data access permissions
     */
    public function getAllRolesWithPermissions(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                r.id as role_id,
                r.name as role_name,
                r.description as role_description,
                rda.id_resourceTypes,
                rda.resource_id,
                rda.crud_permissions,
                rt.lookup_value as resource_type_name,
                rda.created_at,
                rda.updated_at
            FROM roles r
            LEFT JOIN role_data_access rda ON r.id = rda.id_roles
            LEFT JOIN lookups rt ON rda.id_resourceTypes = rt.id AND rt.type_code = 'resourceTypes'
            ORDER BY r.name, rda.id_resourceTypes, rda.resource_id
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
     * Get effective permissions for a role (including multiple roles if user has them)
     */
    public function getEffectivePermissionsForUser(int $userId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                ur.id_roles,
                r.name as role_name,
                rda.id_resourceTypes,
                rda.resource_id,
                rt.lookup_value as resource_type_name,
                BIT_OR(rda.crud_permissions) as unified_permissions,
                GROUP_CONCAT(DISTINCT rda.crud_permissions) as individual_permissions
            FROM users_roles ur
            INNER JOIN roles r ON ur.id_roles = r.id
            LEFT JOIN role_data_access rda ON ur.id_roles = rda.id_roles
            LEFT JOIN lookups rt ON rda.id_resourceTypes = rt.id AND rt.type_code = 'resourceTypes'
            WHERE ur.id_users = :user_id
            GROUP BY ur.id_roles, r.name, rda.id_resourceTypes, rda.resource_id
            ORDER BY r.name, rda.id_resourceTypes, rda.resource_id
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
     * Delete permissions for a role
     */
    public function deletePermissionsForRole(int $roleId): int
    {
        return $this->createQueryBuilder('rda')
            ->delete()
            ->where('rda.idRoles = :roleId')
            ->setParameter('roleId', $roleId)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete specific permission
     */
    public function deletePermission(int $roleId, int $resourceTypeId, int $resourceId): int
    {
        return $this->createQueryBuilder('rda')
            ->delete()
            ->where('rda.idRoles = :roleId')
            ->andWhere('rda.idResourceTypes = :resourceTypeId')
            ->andWhere('rda.resourceId = :resourceId')
            ->setParameter('roleId', $roleId)
            ->setParameter('resourceTypeId', $resourceTypeId)
            ->setParameter('resourceId', $resourceId)
            ->getQuery()
            ->execute();
    }
}
