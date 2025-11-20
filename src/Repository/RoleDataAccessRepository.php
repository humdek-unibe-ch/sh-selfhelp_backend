<?php

namespace App\Repository;

use App\Entity\Lookup;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Entity\User;
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
        // Get all role data access records for the user's roles
        $qb = $this->createQueryBuilder('rda')
            ->select('rda.idResourceTypes', 'rda.resourceId', 'rda.crudPermissions')
            ->innerJoin('rda.role', 'r')
            ->innerJoin('r.users', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('rda.idResourceTypes', 'ASC')
            ->addOrderBy('rda.resourceId', 'ASC');

        $results = $qb->getQuery()->getResult();

        // Aggregate permissions by resource type and resource_id using bitwise OR
        $aggregatedPermissions = [];
        foreach ($results as $result) {
            $resourceTypeId = $result['idResourceTypes'];
            $resourceId = $result['resourceId'];
            $permissions = $result['crudPermissions'];

            $key = $resourceTypeId . '_' . $resourceId;
            if (!isset($aggregatedPermissions[$key])) {
                $aggregatedPermissions[$key] = [
                    'id_resourceTypes' => $resourceTypeId,
                    'resource_id' => $resourceId,
                    'unified_permissions' => 0
                ];
            }

            $aggregatedPermissions[$key]['unified_permissions'] |= $permissions;
        }

        return array_values($aggregatedPermissions);
    }

    /**
     * Get permissions for a specific resource type
     */
    public function getUserPermissionsForResourceType(int $userId, int $resourceTypeId): array
    {
        // Get all role data access records for the user's roles and resource type
        $qb = $this->createQueryBuilder('rda')
            ->select('rda.resourceId', 'rda.crudPermissions')
            ->innerJoin('rda.role', 'r')
            ->innerJoin('r.users', 'u')
            ->where('u.id = :userId')
            ->andWhere('rda.idResourceTypes = :resourceTypeId')
            ->setParameter('userId', $userId)
            ->setParameter('resourceTypeId', $resourceTypeId)
            ->orderBy('rda.resourceId', 'ASC');

        $results = $qb->getQuery()->getResult();

        // Aggregate permissions by resource_id using bitwise OR
        $aggregatedPermissions = [];
        foreach ($results as $result) {
            $resourceId = $result['resourceId'];
            $permissions = $result['crudPermissions'];

            if (!isset($aggregatedPermissions[$resourceId])) {
                $aggregatedPermissions[$resourceId] = 0;
            }

            $aggregatedPermissions[$resourceId] |= $permissions;
        }

        // Convert to the expected format
        $finalResults = [];
        foreach ($aggregatedPermissions as $resourceId => $permissions) {
            $finalResults[] = [
                'resource_id' => $resourceId,
                'unified_permissions' => $permissions,
            ];
        }

        return $finalResults;
    }

    /**
     * Get permissions for a specific resource
     */
    public function getUserPermissionsForResource(int $userId, int $resourceTypeId, int $resourceId): ?int
    {
        // Get all role data access records for the specific resource
        $qb = $this->createQueryBuilder('rda')
            ->select('rda.crudPermissions')
            ->innerJoin('rda.role', 'r')
            ->innerJoin('r.users', 'u')
            ->where('u.id = :userId')
            ->andWhere('rda.idResourceTypes = :resourceTypeId')
            ->andWhere('rda.resourceId = :resourceId')
            ->setParameter('userId', $userId)
            ->setParameter('resourceTypeId', $resourceTypeId)
            ->setParameter('resourceId', $resourceId);

        $results = $qb->getQuery()->getResult();

        // Aggregate permissions using bitwise OR
        $unifiedPermissions = 0;
        foreach ($results as $result) {
            $unifiedPermissions |= $result['crudPermissions'];
        }

        return $results ? $unifiedPermissions : null;
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
        $em = $this->getEntityManager();

        // Use QueryBuilder to query roles with their data access permissions
        $qb = $em->createQueryBuilder()
            ->select([
                'r.id as role_id',
                'r.name as role_name',
                'r.description as role_description',
                'rda.idResourceTypes',
                'rda.resourceId',
                'rda.crudPermissions',
                'rt.lookupValue as resource_type_name',
                'rda.createdAt',
                'rda.updatedAt'
            ])
            ->from(Role::class, 'r')
            ->leftJoin(RoleDataAccess::class, 'rda', 'WITH', 'rda.role = r')
            ->leftJoin(Lookup::class, 'rt', 'WITH', 'rt.id = rda.idResourceTypes AND rt.typeCode = :resourceTypes')
            ->setParameter('resourceTypes', 'resourceTypes')
            ->orderBy('r.name', 'ASC')
            ->addOrderBy('rda.idResourceTypes', 'ASC')
            ->addOrderBy('rda.resourceId', 'ASC');

        $results = $qb->getQuery()->getResult();

        // Convert to the expected associative array format
        return array_map(function ($result) {
            return [
                'role_id' => $result['role_id'],
                'role_name' => $result['role_name'],
                'role_description' => $result['role_description'],
                'id_resourceTypes' => $result['idResourceTypes'],
                'resource_id' => $result['resourceId'],
                'crud_permissions' => $result['crudPermissions'],
                'resource_type_name' => $result['resource_type_name'],
                'created_at' => $result['createdAt']?->format('Y-m-d H:i:s'),
                'updated_at' => $result['updatedAt']?->format('Y-m-d H:i:s'),
            ];
        }, $results);
    }

    /**
     * Get effective permissions for a role (including multiple roles if user has them)
     */
    public function getEffectivePermissionsForUser(int $userId): array
    {
        $em = $this->getEntityManager();

        // Use QueryBuilder to get all role data access for user's roles
        $qb = $em->createQueryBuilder()
            ->select([
                'r.id as role_id',
                'r.name as role_name',
                'rda.idResourceTypes',
                'rda.resourceId',
                'rt.lookupValue as resource_type_name',
                'rda.crudPermissions'
            ])
            ->from(User::class, 'u')
            ->innerJoin('u.roles', 'r')
            ->leftJoin(RoleDataAccess::class, 'rda', 'WITH', 'rda.role = r')
            ->leftJoin(Lookup::class, 'rt', 'WITH', 'rt.id = rda.idResourceTypes AND rt.typeCode = :resourceTypes')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->setParameter('resourceTypes', 'resourceTypes')
            ->orderBy('r.name', 'ASC')
            ->addOrderBy('rda.idResourceTypes', 'ASC')
            ->addOrderBy('rda.resourceId', 'ASC');

        $results = $qb->getQuery()->getResult();

        // Group and aggregate the results in PHP
        $groupedResults = [];
        foreach ($results as $result) {
            $roleId = $result['role_id'];
            $resourceTypeId = $result['idResourceTypes'];
            $resourceId = $result['resourceId'];

            // Create a unique key for grouping
            $key = $roleId . '_' . ($resourceTypeId ?? 'null') . '_' . ($resourceId ?? 'null');

            if (!isset($groupedResults[$key])) {
                $groupedResults[$key] = [
                    'id_roles' => $roleId,
                    'role_name' => $result['role_name'],
                    'id_resourceTypes' => $resourceTypeId,
                    'resource_id' => $resourceId,
                    'resource_type_name' => $result['resource_type_name'],
                    'unified_permissions' => 0,
                    'individual_permissions' => []
                ];
            }

            // Aggregate permissions
            if ($result['crudPermissions'] !== null) {
                $groupedResults[$key]['unified_permissions'] |= $result['crudPermissions'];
                $groupedResults[$key]['individual_permissions'][] = $result['crudPermissions'];
            }
        }

        // Convert individual permissions to comma-separated string and remove duplicates
        $finalResults = [];
        foreach ($groupedResults as $result) {
            $result['individual_permissions'] = implode(',', array_unique($result['individual_permissions']));
            $finalResults[] = $result;
        }

        return $finalResults;
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
