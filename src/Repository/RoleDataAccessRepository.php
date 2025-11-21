<?php

namespace App\Repository;

use App\Entity\DataTable;
use App\Entity\Group;
use App\Entity\Lookup;
use App\Entity\Page;
use App\Entity\Role;
use App\Entity\RoleDataAccess;
use App\Entity\User;
use App\Entity\UsersGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
    public function getAccessibleUsersForUser(int $userId, int $resourceTypeId, int $page = 1, int $pageSize = 20, ?string $search = null, ?string $sort = null, string $sortDirection = 'asc'): array
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
            ->leftJoin('u.roles', 'ur')
            ->leftJoin('u.userActivities', 'ua')
            ->leftJoin('u.validationCodes', 'vc')
            ->leftJoin('u.status', 'us')
            ->leftJoin('u.userType', 'ut')
            ->where('u.id IN (' . $subQuery . ')')
            ->andWhere('u.intern = :intern')
            ->andWhere('u.id_status > 0')
            ->setParameter('groupIds', $accessibleGroupIds)
            ->setParameter('intern', false)
            ->groupBy('u.id');

        // Apply search filter
        $this->applyUserSearchFilter($qb, $search);

        // Apply sorting
        $this->applyUserSorting($qb, $sort, $sortDirection);

        // Get total count - use a simple query to avoid complex join issues
        $countSql = 'SELECT COUNT(DISTINCT u.id) FROM users u WHERE u.intern = 0 AND u.id_status > 0';
        $params = [];

        if ($search) {
            $countSql .= ' AND (u.email LIKE :search OR u.name LIKE :search OR u.user_name LIKE :search OR CAST(u.id AS CHAR) LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $conn = $this->getEntityManager()->getConnection();
        $stmt = $conn->prepare($countSql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $result = $stmt->executeQuery();
        $totalCount = (int) $result->fetchOne();

        // Apply pagination
        $offset = ($page - 1) * $pageSize;
        $qb->setFirstResult($offset)->setMaxResults($pageSize);

        $users = $qb->getQuery()->getResult();

        // Format users
        $formattedUsers = array_map(
            fn($user) => $this->formatUserForResponse($user, 15), // Full permissions for accessible groups
            $users
        );

        // Return paginated response with pagination info
        return [
            'users' => $formattedUsers,
            'pagination' => $this->createPaginationInfo($page, $pageSize, $totalCount)
        ];
    }

    /**
     * Get all users for admin users (SQL-based, no pagination)
     * Returns all users formatted to match AdminUserService output
     *
     * @return array Array of formatted users
     */
    public function getAllUsersForAdmin(int $page = 1, int $pageSize = 20, ?string $search = null, ?string $sort = null, string $sortDirection = 'asc'): array
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
            ->addSelect('ut', 'ug', 'g', 'ua', 'vc', 'ur', 'us');

        // Apply search filter
        $this->applyUserSearchFilter($qb, $search);

        // Apply sorting
        $this->applyUserSorting($qb, $sort, $sortDirection);

        // Get total count - use a simple query to avoid complex join issues
        $countSql = 'SELECT COUNT(DISTINCT u.id) FROM users u WHERE u.intern = 0 AND u.id_status > 0';
        $params = [];

        if ($search) {
            $countSql .= ' AND (u.email LIKE :search OR u.name LIKE :search OR u.user_name LIKE :search OR CAST(u.id AS CHAR) LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $conn = $this->getEntityManager()->getConnection();
        $stmt = $conn->prepare($countSql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $result = $stmt->executeQuery();
        $totalCount = (int) $result->fetchOne();

        // Apply pagination
        $offset = ($page - 1) * $pageSize;
        $qb->setFirstResult($offset)->setMaxResults($pageSize);

        $users = $qb->getQuery()->getResult();

        // Format users
        $formattedUsers = array_map(
            fn($user) => $this->formatUserForResponse($user, 15), // Full permissions for admin
            $users
        );

        // Return paginated response with pagination info
        return [
            'users' => $formattedUsers,
            'pagination' => $this->createPaginationInfo($page, $pageSize, $totalCount)
        ];
    }

    /**
     * Get groups that a user can access based on their role permissions
     * Returns groups with pagination and user count
     *
     * @param int $userId User ID performing the operation
     * @param int $resourceTypeId Resource type ID for groups
     * @param int $page Page number (1-based)
     * @param int $pageSize Number of groups per page
     * @param string|null $search Search term for name or description
     * @param string|null $sort Sort field
     * @param string $sortDirection Sort direction ('asc' or 'desc')
     * @param bool $isAdmin Whether the user is an admin (has access to all groups)
     * @return array Array of groups with pagination info
     */
    public function getAccessibleGroupsForUser(int $userId, int $resourceTypeId, int $page = 1, int $pageSize = 20, ?string $search = null, ?string $sort = null, string $sortDirection = 'asc', bool $isAdmin = false): array
    {
        // Admin users get all groups
        if ($isAdmin) {
            $accessibleGroupIds = null; // Will query all groups below
        } else {
            // Get all groups that the user can access
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
                return [
                    'groups' => [],
                    'pagination' => $this->createPaginationInfo($page, $pageSize, 0)
                ];
            }
        }

        // Get groups with their related data
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('g')
            ->from(Group::class, 'g')
            ->leftJoin('g.usersGroups', 'ug')
            ->leftJoin('ug.user', 'u')
            ->groupBy('g.id');

        // Apply group ID filter only for non-admin users
        if ($accessibleGroupIds !== null) {
            $qb->where('g.id IN (:groupIds)')
                ->setParameter('groupIds', $accessibleGroupIds);
        }

        // Add user count subquery
        $userCountSubQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(ug2.user)')
            ->from(UsersGroup::class, 'ug2')
            ->where('ug2.group = g.id')
            ->getDQL();

        $qb->addSelect("($userCountSubQuery) as users_count");

        // Apply search filter
        if ($search) {
            $qb->andWhere('(g.name LIKE :search OR g.description LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        // Apply sorting
        $allowedSortFields = ['name', 'description', 'requires_2fa'];
        if ($sort && in_array($sort, $allowedSortFields)) {
            $qb->orderBy('g.' . $sort, $sortDirection);
        } else {
            $qb->orderBy('g.name', 'asc');
        }

        // Get total count for pagination
        $countQb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(DISTINCT g.id)')
            ->from(Group::class, 'g');

        // Apply group ID filter only for non-admin users
        if ($accessibleGroupIds !== null) {
            $countQb->where('g.id IN (:groupIds)')
                ->setParameter('groupIds', $accessibleGroupIds);
        }

        // Apply search filter
        if ($search) {
            $countQb->andWhere('(g.name LIKE :search OR g.description LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        $totalCount = $countQb->getQuery()->getSingleScalarResult();

        // Apply pagination
        $qb->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        $groups = $qb->getQuery()->getResult();

        // Format groups for response
        $formattedGroups = [];
        foreach ($groups as $row) {
            $group = $row[0];
            $userCount = $row['users_count'];

            $formattedGroups[] = [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'description' => $group->getDescription(),
                'id_group_types' => $group->getIdGroupTypes(),
                'requires_2fa' => $group->isRequires2fa(),
                'users_count' => (int) $userCount,
                'crud' => 15 // Full permissions for accessible groups
            ];
        }

        // Return paginated response
        return [
            'groups' => $formattedGroups,
            'pagination' => $this->createPaginationInfo($page, $pageSize, $totalCount)
        ];
    }

    /**
     * Get user permissions for multiple resources in batch
     * Returns array mapping resource IDs to permission bitmasks
     *
     * @param int $userId User ID
     * @param int $resourceTypeId Resource type ID
     * @param array|null $resourceIds Specific resource IDs to check, null for all accessible
     * @return array Array mapping resource IDs to permission values
     */
    public function getUserPermissionsForResources(int $userId, int $resourceTypeId, ?array $resourceIds = null): array
    {
        $qb = $this->createQueryBuilder('rda')
            ->select('rda.resourceId, BIT_OR(rda.crudPermissions) as permissions')
            ->innerJoin('rda.role', 'r')
            ->innerJoin('r.users', 'u')
            ->where('u.id = :userId')
            ->andWhere('rda.idResourceTypes = :resourceTypeId')
            ->andWhere('rda.crudPermissions > 0')
            ->setParameter('userId', $userId)
            ->setParameter('resourceTypeId', $resourceTypeId)
            ->groupBy('rda.resourceId');

        if ($resourceIds !== null) {
            $qb->andWhere('rda.resourceId IN (:resourceIds)')
                ->setParameter('resourceIds', $resourceIds);
        }

        $results = $qb->getQuery()->getResult();

        // Convert to associative array
        $permissions = [];
        foreach ($results as $result) {
            $permissions[$result['resourceId']] = (int) $result['permissions'];
        }

        return $permissions;
    }


    /**
     * Create pagination information array
     *
     * @param int $page Current page number
     * @param int $pageSize Page size
     * @param int $totalCount Total number of items
     * @return array Pagination information
     */
    private function createPaginationInfo(int $page, int $pageSize, int $totalCount): array
    {
        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $totalCount,
            'totalPages' => (int) ceil($totalCount / $pageSize),
            'hasNext' => $page < ceil($totalCount / $pageSize),
            'hasPrevious' => $page > 1
        ];
    }

    /**
     * Apply search filter to user query
     *
     * @param QueryBuilder $qb
     * @param string|null $search
     */
    private function applyUserSearchFilter(QueryBuilder $qb, ?string $search): void
    {
        if ($search) {
            $qb->andWhere('(u.email LIKE :search OR u.name LIKE :search OR u.user_name LIKE :search OR CAST(u.id AS string) LIKE :search OR vc.code LIKE :search OR ur.name LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }
    }

    /**
     * Apply sorting to user query
     *
     * @param QueryBuilder $qb
     * @param string|null $sort
     * @param string $sortDirection
     */
    private function applyUserSorting(QueryBuilder $qb, ?string $sort, string $sortDirection): void
    {
        $validSortFields = ['email', 'name', 'last_login', 'blocked', 'user_type', 'code', 'id'];

        if ($sort && in_array($sort, $validSortFields)) {
            switch ($sort) {
                case 'user_type':
                    $qb->orderBy('ut.lookupValue', $sortDirection);
                    break;
                case 'last_login':
                    $qb->orderBy('u.last_login', $sortDirection);
                    break;
                default:
                    $qb->orderBy('u.' . $sort, $sortDirection);
                    break;
            }
        } else {
            $qb->orderBy('u.email', 'asc');
        }
    }

    /**
     * Format a user entity for API response
     *
     * @param User $user
     * @param int $crudPermissions
     * @return array
     */
    private function formatUserForResponse(User $user, int $crudPermissions = 15): array
    {
        $lastLogin = $user->getLastLogin();
        $lastLoginFormatted = 'never';
        if ($lastLogin) {
            $daysDiff = (new \DateTime())->diff($lastLogin)->days;
            $lastLoginFormatted = $lastLogin->format('Y-m-d') . ' (' . $daysDiff . ' days ago)';
        }

        $groups = array_map(fn($group) => $group->getName(), $user->getGroups()->toArray());
        $roles = array_map(fn($role) => $role->getName(), $user->getUserRoles()->toArray());

        $validationCode = '';
        $validationCodes = $user->getValidationCodes();
        if (!$validationCodes->isEmpty()) {
            $validationCode = $validationCodes->first()->getCode();
        }

        return [
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
            'crud' => $crudPermissions
        ];
    }

    /**
     * Get all users who have custom data access permissions
     *
     * Finds users who have roles with custom data access permissions defined.
     * These users can access other users' data based on role-based rules.
     * Used for cache invalidation when user-group relationships change.
     *
     * @return array Array of user IDs who have custom data access permissions
     */
    public function getUsersWithDataAccessPermissions(): array
    {
        $qb = $this->createQueryBuilder('rda')
            ->select('DISTINCT u.id')
            ->innerJoin('rda.role', 'r')
            ->innerJoin('r.users', 'u')
            ->orderBy('u.id', 'ASC');

        $result = $qb->getQuery()->getScalarResult();

        return array_column($result, 'id');
    }
}
