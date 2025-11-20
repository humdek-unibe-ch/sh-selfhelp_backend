<?php

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\AdminUserService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin User Controller
 * 
 * Handles all user management operations for admin interface
 */
class AdminUserController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly AdminUserService $adminUserService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly UserContextService $userContextService,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Get users with pagination, search, and sorting
     * Filtered by group access permissions
     */
    public function getUsers(Request $request): JsonResponse
    {
        try {
            $page = (int)$request->query->get('page', 1);
            $pageSize = (int)$request->query->get('pageSize', 20);
            $search = $request->query->get('search');
            $sort = $request->query->get('sort');
            $sortDirection = $request->query->get('sortDirection', 'asc');
            $userId = $this->userContextService->getCurrentUser()?->getId();

            if ($userId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Use service-level filtering for users with permission checking
            $result = $this->adminUserService->getFilteredUsers(
                $userId,
                $page,
                $pageSize,
                $search,
                $sort,
                $sortDirection
            );



            return $this->responseFormatter->formatSuccess($result, 'responses/admin/users/users_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get single user by ID
     * Filtered by group access permissions
     *
     * @route /admin/users/{userId}
     * @method GET
     */
    public function getUserById(int $userId): JsonResponse
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            // Check if user has permission to view this specific user
            // Admin users bypass permission checks
            if (!$this->dataAccessSecurityService->userHasAdminRole($currentUserId)) {
                if (!$this->adminUserService->canAccessUser($currentUserId, $userId, DataAccessSecurityService::PERMISSION_READ)) {
                    return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
                }
            }

            $user = $this->adminUserService->getUserById($userId);
            return $this->responseFormatter->formatSuccess($user, 'responses/admin/users/user_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Create new user
     * Checks if user can create users in specified groups
     *
     * @route /admin/users
     * @method POST
     */
    public function createUser(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/create_user', $this->jsonSchemaValidationService);
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            // Check if user can create users in the specified groups
            if (isset($data['group_ids']) && is_array($data['group_ids'])) {
                foreach ($data['group_ids'] as $groupId) {
                    if (!$this->dataAccessSecurityService->hasPermission(
                        $currentUserId,
                        LookupService::RESOURCE_TYPES_GROUP,
                        (int) $groupId,
                        DataAccessSecurityService::PERMISSION_CREATE
                    )) {
                        return $this->responseFormatter->formatError(
                            'Access denied: Cannot create users in group ' . $groupId,
                            Response::HTTP_FORBIDDEN
                        );
                    }
                }
            }

            $user = $this->adminUserService->createUser($data);

            return $this->responseFormatter->formatSuccess(
                $user,
                'responses/admin/users/user_envelope',
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Update existing user
     * Checks if user can update users in the target user's group
     *
     * @route /admin/users/{userId}
     * @method PUT
     */
    public function updateUser(int $userId, Request $request): JsonResponse
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $data = $this->validateRequest($request, 'requests/admin/update_user', $this->jsonSchemaValidationService);

            $user = $this->adminUserService->updateUser($currentUserId, $userId, $data);

            return $this->responseFormatter->formatSuccess($user, 'responses/admin/users/user_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Delete user
     * Checks if user can delete users in the target user's group
     *
     * @route /admin/users/{userId}
     * @method DELETE
     */
    public function deleteUser(int $userId): JsonResponse
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $this->adminUserService->deleteUser($currentUserId, $userId);

            return $this->responseFormatter->formatSuccess(['deleted' => true]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Block/Unblock user
     * Checks if user can update users in the target user's group
     *
     * @route /admin/users/{userId}/block
     * @method PATCH
     */
    public function toggleUserBlock(int $userId, Request $request): JsonResponse
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            // Check if user has UPDATE permission for this specific user
            $userGroupId = $this->getUserGroupId($userId);
            if (!$this->dataAccessSecurityService->hasPermission(
                $currentUserId,
                LookupService::RESOURCE_TYPES_GROUP,
                $userGroupId,
                DataAccessSecurityService::PERMISSION_UPDATE
            )) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $data = json_decode($request->getContent(), true);
            $blocked = $data['blocked'] ?? true;

            $user = $this->adminUserService->toggleUserBlock($userId, $blocked);

            return $this->responseFormatter->formatSuccess($user, 'responses/admin/users/user_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get user groups
     * Filtered by group access permissions
     *
     * @route /admin/users/{userId}/groups
     * @method GET
     */
    public function getUserGroups(int $userId): JsonResponse
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            // Check if user has permission to view this user's groups
            $userGroupId = $this->getUserGroupId($userId);
            if (!$this->dataAccessSecurityService->hasPermission(
                $currentUserId,
                LookupService::RESOURCE_TYPES_GROUP,
                $userGroupId,
                DataAccessSecurityService::PERMISSION_READ
            )) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $groups = $this->adminUserService->getUserGroups($userId);
            return $this->responseFormatter->formatSuccess(['groups' => $groups]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get user roles
     * Filtered by group access permissions
     *
     * @route /admin/users/{userId}/roles
     * @method GET
     */
    public function getUserRoles(int $userId): JsonResponse
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            // Check if user has permission to view this user's roles
            $userGroupId = $this->getUserGroupId($userId);
            if (!$this->dataAccessSecurityService->hasPermission(
                $currentUserId,
                LookupService::RESOURCE_TYPES_GROUP,
                $userGroupId,
                DataAccessSecurityService::PERMISSION_READ
            )) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $roles = $this->adminUserService->getUserRoles($userId);
            return $this->responseFormatter->formatSuccess(['roles' => $roles]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Add groups to user
     * Checks if user can update users in the target user's group
     *
     * @route /admin/users/{userId}/groups
     * @method POST
     */
    public function addGroupsToUser(int $userId, Request $request): JsonResponse
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            // Check if user has UPDATE permission for this specific user
            $userGroupId = $this->getUserGroupId($userId);
            if (!$this->dataAccessSecurityService->hasPermission(
                $currentUserId,
                LookupService::RESOURCE_TYPES_GROUP,
                $userGroupId,
                DataAccessSecurityService::PERMISSION_UPDATE
            )) {
                return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
            }

            $data = json_decode($request->getContent(), true);
            $groupIds = $data['group_ids'] ?? [];

            if (!is_array($groupIds) || empty($groupIds)) {
                return $this->responseFormatter->formatError(
                    'group_ids array is required',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $groups = $this->adminUserService->addGroupsToUser($userId, $groupIds);

            return $this->responseFormatter->formatSuccess(['groups' => $groups]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Remove groups from user
     * 
     * @route /admin/users/{userId}/groups
     * @method DELETE
     */
    public function removeGroupsFromUser(int $userId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $groupIds = $data['group_ids'] ?? [];
            
            if (!is_array($groupIds) || empty($groupIds)) {
                return $this->responseFormatter->formatError(
                    'group_ids array is required',
                    Response::HTTP_BAD_REQUEST
                );
            }
            
            $groups = $this->adminUserService->removeGroupsFromUser($userId, $groupIds);
            
            // Invalidate user and permissions cache
            // User cache is automatically invalidated by the service
            // Permissions cache is automatically invalidated by the service
            
            return $this->responseFormatter->formatSuccess(['groups' => $groups]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Add roles to user
     * 
     * @route /admin/users/{userId}/roles
     * @method POST
     */
    public function addRolesToUser(int $userId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $roleIds = $data['role_ids'] ?? [];
            
            if (!is_array($roleIds) || empty($roleIds)) {
                return $this->responseFormatter->formatError(
                    'role_ids array is required',
                    Response::HTTP_BAD_REQUEST
                );
            }
            
            $roles = $this->adminUserService->addRolesToUser($userId, $roleIds);
            
            // Invalidate user and permissions cache
            // User cache is automatically invalidated by the service
            // Permissions cache is automatically invalidated by the service
            
            return $this->responseFormatter->formatSuccess(['roles' => $roles]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Remove roles from user
     * 
     * @route /admin/users/{userId}/roles
     * @method DELETE
     */
    public function removeRolesFromUser(int $userId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $roleIds = $data['role_ids'] ?? [];
            
            if (!is_array($roleIds) || empty($roleIds)) {
                return $this->responseFormatter->formatError(
                    'role_ids array is required',
                    Response::HTTP_BAD_REQUEST
                );
            }
            
            $roles = $this->adminUserService->removeRolesFromUser($userId, $roleIds);
            
            // Invalidate user and permissions cache
            // User cache is automatically invalidated by the service
            // Permissions cache is automatically invalidated by the service
            
            return $this->responseFormatter->formatSuccess(['roles' => $roles]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Send activation mail to user
     * 
     * @route /admin/users/{userId}/send-activation-mail
     * @method POST
     */
    public function sendActivationMail(int $userId): JsonResponse
    {
        try {
            $result = $this->adminUserService->sendActivationMail($userId);
            return $this->responseFormatter->formatSuccess($result);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Clean user data
     * 
     * @route /admin/users/{userId}/clean-data
     * @method POST
     */
    public function cleanUserData(int $userId): JsonResponse
    {
        try {
            $result = $this->adminUserService->cleanUserData($userId);
            return $this->responseFormatter->formatSuccess(['cleaned' => $result]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Impersonate user
     *
     * @route /admin/users/{userId}/impersonate
     * @method POST
     */
    public function impersonateUser(int $userId): JsonResponse
    {
        try {
            $result = $this->adminUserService->impersonateUser($userId);
            return $this->responseFormatter->formatSuccess($result);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get the group ID for a user (helper method for permission checks)
     */
    private function getUserGroupId(int $userId): int
    {
        try {
            // Get user's primary group ID
            $conn = $this->entityManager->getConnection();
            $sql = "SELECT id_groups FROM users_groups WHERE id_users = :user_id LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
            $result = $stmt->executeQuery();
            $row = $result->fetchAssociative();

            return $row ? (int) $row['id_groups'] : 0;
        } catch (\Exception $e) {
            return 0; // Default to no access if we can't determine group
        }
    }
} 