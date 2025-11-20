<?php

namespace App\Repository;

use App\Entity\DataTable;
use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\Page;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Entity\User;
use App\Entity\UserActivity;
use App\Entity\ValidationCode;
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

    /**
     * Get accessible pages for a user with permissions joined at SQL level
     * More efficient than fetching all pages and filtering in PHP
     *
     * @param int $userId User ID
     * @param int $resourceTypeId Resource type ID for pages
     * @return array Array of pages with crud permissions in the expected format
     */
    public function getAccessiblePagesForUser(int $userId, int $resourceTypeId): array
    {
        // Join pages with role_data_access - permission filtering done in service layer
        $qb = $this->createQueryBuilder('rda')
            ->select([
                'p.id as id_pages',
                'IDENTITY(p.parentPage) as parent',
                'p.keyword',
                'p.url',
                'p.nav_position as nav_position',
                'p.footer_position as footer_position',
                'p.is_headless as is_headless',
                'p.is_open_access as is_open_access',
                'p.is_system as is_system',
                'pat.id as id_pageAccessTypes',
                'pt.id as id_type',
                'rda.crudPermissions as crud'
            ])
            ->innerJoin('rda.role', 'r')
            ->innerJoin('r.users', 'u')
            ->innerJoin(Page::class, 'p', 'WITH', 'p.id = rda.resourceId')
            ->leftJoin('p.pageAccessType', 'pat')
            ->leftJoin('p.pageType', 'pt')
            ->where('u.id = :userId')
            ->andWhere('rda.idResourceTypes = :resourceTypeId')
            ->andWhere('rda.crudPermissions > 0')
            ->setParameter('userId', $userId)
            ->setParameter('resourceTypeId', $resourceTypeId)
            ->orderBy('p.id', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get accessible dataTables for a user with permissions joined at SQL level
     * More efficient than fetching all dataTables and filtering in PHP
     *
     * @param int $userId User ID
     * @param int $resourceTypeId Resource type ID for dataTables
     * @return array Array of dataTables with crud permissions in the expected format
     */
    public function getAccessibleDataTablesForUser(int $userId, int $resourceTypeId): array
    {
        // Join dataTables with role_data_access - permission filtering done in service layer
        $qb = $this->createQueryBuilder('rda')
            ->select([
                'dt.id',
                'dt.name',
                'dt.displayName',
                'dt.timestamp as created',
                'rda.crudPermissions as crud'
            ])
            ->innerJoin('rda.role', 'r')
            ->innerJoin('r.users', 'u')
            ->innerJoin(DataTable::class, 'dt', 'WITH', 'dt.id = rda.resourceId')
            ->where('u.id = :userId')
            ->andWhere('rda.idResourceTypes = :resourceTypeId')
            ->andWhere('rda.crudPermissions > 0')
            ->setParameter('userId', $userId)
            ->setParameter('resourceTypeId', $resourceTypeId)
            ->orderBy('dt.id', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get all pages with full permissions for admin users
     * Admin users get access to all pages with full CRUD permissions
     *
     * @return array Array of all pages with full permissions (crud=15)
     */
    public function getAllPagesWithFullPermissions(): array
    {
        // Get all pages with full permissions for admin (no permission filtering)
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select([
                'p.id as id_pages',
                'IDENTITY(p.parentPage) as parent', // Use IDENTITY() to get foreign key value
                'p.keyword',
                'p.url',
                'p.nav_position as nav_position',
                'p.footer_position as footer_position',
                'p.is_headless as is_headless',
                'p.is_open_access as is_open_access',
                'p.is_system as is_system',
                'IDENTITY(p.pageAccessType) as id_pageAccessTypes',
                'IDENTITY(p.pageType) as id_type',
                '15 as crud' // Full permissions for admin
            ])
            ->from(Page::class, 'p')
            ->orderBy('p.id', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get all dataTables with full permissions for admin users
     * Admin users get access to all dataTables with full CRUD permissions
     *
     * @return array Array of all dataTables with full permissions (crud=15)
     */
    public function getAllDataTablesWithFullPermissions(): array
    {
        // Get all dataTables with full permissions for admin (no permission filtering)
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select([
                'dt.id',
                'dt.name',
                'dt.displayName',
                'dt.timestamp as created',
                '15 as crud' // Full permissions for admin
            ])
            ->from(DataTable::class, 'dt')
            ->orderBy('dt.id', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get accessible users for a user based on group permissions
     * Returns users who belong to groups that the current user can access
     *
     * @param int $userId User ID
     * @param int $resourceTypeId Resource type ID for groups
     * @return array Array of users with their group membership info
     */
    public function getAccessibleUsersForUser(int $userId, int $resourceTypeId): array
    {
        // First, get all groups that the user can access
        $accessibleGroupIds = $this->createQueryBuilder('rda')
            ->select('rda.resourceId')
            ->innerJoin('rda.role', 'r')
            ->innerJoin('r.users', 'u')
            ->where('u.id = :userId')
            ->andWhere('rda.idResourceTypes = :resourceTypeId')
            ->andWhere('rda.crudPermissions > 0')
            ->setParameter('userId', $userId)
            ->setParameter('resourceTypeId', $resourceTypeId)
            ->getQuery()
            ->getSingleColumnResult();

        if (empty($accessibleGroupIds)) {
            return [];
        }

        // Now get all users who belong to these accessible groups
        // Use a subquery approach to avoid GROUP_CONCAT issues and improve performance
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT u2.id')
            ->from(User::class, 'u2')
            ->innerJoin('u2.usersGroups', 'ug2')
            ->innerJoin('ug2.group', 'g2')
            ->where('g2.id IN (:groupIds)')
            ->andWhere('u2.intern = :intern')
            ->andWhere('u2.id_status > 0')
            ->getDQL();

        // Get users with their related data for formatting
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->leftJoin('u.usersGroups', 'ug')
            ->leftJoin('ug.group', 'g')
            ->leftJoin('u.roles', 'r')
            ->leftJoin('u.userActivities', 'ua')
            ->leftJoin('u.validationCodes', 'vc')
            ->leftJoin('u.status', 'us')
            ->leftJoin('u.userType', 'ut')
            ->where('u.id IN (' . $subQuery . ')')
            ->andWhere('u.intern = :intern')
            ->andWhere('u.id_status > 0')
            ->setParameter('groupIds', $accessibleGroupIds)
            ->setParameter('intern', false)
            ->groupBy('u.id')
            ->orderBy('u.id', 'ASC');

        $users = $qb->getQuery()->getResult();

        // Format users to match AdminUserService.formatUserForList output
        $formattedUsers = [];
        foreach ($users as $user) {
            $lastLogin = $user->getLastLogin();
            $lastLoginFormatted = 'never';
            if ($lastLogin) {
                $daysDiff = (new \DateTime())->diff($lastLogin)->days;
                $lastLoginFormatted = $lastLogin->format('Y-m-d') . ' (' . $daysDiff . ' days ago)';
            }

            $groups = array_map(fn($group) => $group->getName(), $user->getGroups()->toArray());
            $roles = array_map(fn($role) => $role->getName(), $user->getUserRoles()->toArray());

            // Get validation code (simplified - you might need to implement this logic)
            $validationCode = '';
            $validationCodes = $user->getValidationCodes();
            if (!$validationCodes->isEmpty()) {
                $validationCode = $validationCodes->first()->getCode();
            }

            $formattedUsers[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'user_name' => $user->getUserName(),
                'last_login' => $lastLoginFormatted,
                'status' => $user->getStatus()?->getLookupValue(),
                'blocked' => $user->isBlocked(),
                'code' => $validationCode,
                'groups' => implode('; ', $groups),
                'user_activity' => $user->getUserActivities()->count(),
                'user_type_code' => $user->getUserType()?->getLookupCode(),
                'user_type' => $user->getUserType()?->getLookupValue(),
                'roles' => implode('; ', $roles),
                'crud' => 15 // Full permissions since they're in accessible groups
            ];
        }

        return $formattedUsers;
    }

    /**
     * Get all users for admin users (SQL-based, no pagination)
     * Returns all users formatted to match AdminUserService output
     *
     * @return array Array of formatted users
     */
    public function getAllUsersForAdmin(): array
    {
        // Get all users with their related data (similar to AdminUserService.createUserQueryBuilder)
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->leftJoin('u.usersGroups', 'ug')
            ->leftJoin('u.roles', 'ur')
            ->leftJoin('u.userType', 'ut')
            ->leftJoin('ug.group', 'g')
            ->leftJoin('u.userActivities', 'ua')
            ->leftJoin('u.validationCodes', 'vc')
            ->leftJoin('u.status', 'us')
            ->where('u.intern = :intern')
            ->andWhere('u.id_status > 0')
            ->setParameter('intern', false)
            ->addSelect('ut', 'ug', 'g', 'ua', 'vc', 'ur', 'us')
            ->orderBy('u.id', 'ASC');

        $users = $qb->getQuery()->getResult();

        // Format users to match AdminUserService.formatUserForList output
        $formattedUsers = [];
        foreach ($users as $user) {
            $lastLogin = $user->getLastLogin();
            $lastLoginFormatted = 'never';
            if ($lastLogin) {
                $daysDiff = (new \DateTime())->diff($lastLogin)->days;
                $lastLoginFormatted = $lastLogin->format('Y-m-d') . ' (' . $daysDiff . ' days ago)';
            }

            $groups = array_map(fn($group) => $group->getName(), $user->getGroups()->toArray());
            $roles = array_map(fn($role) => $role->getName(), $user->getUserRoles()->toArray());

            // Get validation code
            $validationCode = '';
            $validationCodes = $user->getValidationCodes();
            if (!$validationCodes->isEmpty()) {
                $validationCode = $validationCodes->first()->getCode();
            }

            $formattedUsers[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'user_name' => $user->getUserName(),
                'last_login' => $lastLoginFormatted,
                'status' => $user->getStatus()?->getLookupValue(),
                'blocked' => $user->isBlocked(),
                'code' => $validationCode,
                'groups' => implode('; ', $groups),
                'user_activity' => $user->getUserActivities()->count(),
                'user_type_code' => $user->getUserType()?->getLookupCode(),
                'user_type' => $user->getUserType()?->getLookupValue(),
                'roles' => implode('; ', $roles),
                'crud' => 15 // Full permissions for admin
            ];
        }

        return $formattedUsers;
    }
}
