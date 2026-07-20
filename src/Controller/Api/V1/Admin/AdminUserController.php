<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\AdminUserService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            $status = $request->query->getString('status') ?: null;
            $groupId = $request->query->getInt('id_groups') ?: null;
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
                $sortDirection,
                $status,
                $groupId
            );



            return $this->responseFormatter->formatSuccess($result, 'responses/admin/users/users_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get user counts per status bucket for the admin Users page tiles.
     *
     * Scoped to the caller's visible users so the tiles reconcile with the
     * list; see AdminUserService::STATUS_BUCKETS for the precedence rule.
     *
     * @route /admin/users/stats
     * @method GET
     */
    public function getUserStats(): JsonResponse
    {
        try {
            $userId = $this->userContextService->getCurrentUser()?->getId();

            if ($userId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $stats = $this->adminUserService->getUserStats($userId);

            return $this->responseFormatter->formatSuccess($stats, 'responses/admin/users/user_stats_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Delete several users, reporting per-user failures.
     *
     * @route /admin/users/bulk-delete
     * @method POST
     */
    public function bulkDeleteUsers(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/bulk_user_ids', $this->jsonSchemaValidationService);
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $result = $this->adminUserService->bulkDeleteUsers(
                $currentUserId,
                $this->userIdsFrom($data)
            );

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/users/bulk_operation_result');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Add several users to several groups.
     *
     * @route /admin/users/bulk-add-to-group
     * @method POST
     */
    public function bulkAddUsersToGroups(Request $request): JsonResponse
    {
        try {
            $data = $this->toAssocArray(
                $this->validateRequest($request, 'requests/admin/bulk_group_membership', $this->jsonSchemaValidationService)
            );
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $result = $this->adminUserService->bulkAddUsersToGroups(
                $currentUserId,
                is_array($data['user_ids'] ?? null) ? $data['user_ids'] : [],
                is_array($data['group_ids'] ?? null) ? $data['group_ids'] : []
            );

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/users/bulk_operation_result');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Remove several users from several groups.
     *
     * @route /admin/users/bulk-remove-from-group
     * @method POST
     */
    public function bulkRemoveUsersFromGroups(Request $request): JsonResponse
    {
        try {
            $data = $this->toAssocArray(
                $this->validateRequest($request, 'requests/admin/bulk_group_membership', $this->jsonSchemaValidationService)
            );
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $result = $this->adminUserService->bulkRemoveUsersFromGroups(
                $currentUserId,
                is_array($data['user_ids'] ?? null) ? $data['user_ids'] : [],
                is_array($data['group_ids'] ?? null) ? $data['group_ids'] : []
            );

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/users/bulk_operation_result');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Send the activation mail to several users.
     *
     * @route /admin/users/bulk-send-activation
     * @method POST
     */
    public function bulkSendActivationMail(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/bulk_user_ids', $this->jsonSchemaValidationService);
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $result = $this->adminUserService->bulkSendActivationMail(
                $currentUserId,
                $this->userIdsFrom($data)
            );

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/users/bulk_operation_result');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Export the filtered user set as CSV.
     *
     * Returns raw CSV rather than the JSON envelope; the frontend reads the
     * filename from Content-Disposition.
     *
     * @route /admin/users/export
     * @method GET
     */
    public function exportUsers(Request $request): Response
    {
        $currentUserId = $this->userContextService->getCurrentUser()?->getId();

        if ($currentUserId === null) {
            return $this->responseFormatter->formatError(
                'User not authenticated',
                Response::HTTP_UNAUTHORIZED
            );
        }

        try {
            $rows = $this->adminUserService->exportUsers(
                $currentUserId,
                $request->query->getString('search') ?: null,
                $request->query->getString('status') ?: null,
                $request->query->getInt('id_groups') ?: null
            );
        } catch (\Exception $e) {
            // An invalid filter must surface as a normal API error, not as a
            // half-written CSV download.
            return $this->responseFormatter->formatThrowable($e);
        }

        $filename = 'users_' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd_His') . '.csv';
        $columns = AdminUserService::exportColumns();

        $response = new StreamedResponse(function () use ($rows, $columns): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // PHP 8.4: pass $escape explicitly (its default changes to '' in 9.0);
            // '' yields RFC 4180-correct CSV with no backslash escaping.
            fputcsv($out, $columns, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(static fn(string $c): string => $row[$c] ?? '', $columns), escape: '');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    /**
     * Import users from an uploaded CSV.
     *
     * @route /admin/users/import
     * @method POST
     */
    public function importUsers(Request $request): JsonResponse
    {
        try {
            $file = $request->files->get('file');

            if (!$file instanceof UploadedFile) {
                return $this->responseFormatter->formatError(
                    'A CSV file is required in the "file" field',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $result = $this->adminUserService->importUsers($this->parseCsvUpload($file));

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/users/user_import_result');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Parse an uploaded CSV into associative rows keyed by header.
     *
     * A missing required header fails the whole request: without it we cannot
     * tell which column is which, so importing would silently write garbage.
     *
     * @return list<array<string, string>>
     */
    private function parseCsvUpload(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'csv') {
            throw new ServiceException('Only .csv files are supported', Response::HTTP_BAD_REQUEST);
        }

        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            throw new ServiceException('Could not read the uploaded file', Response::HTTP_BAD_REQUEST);
        }

        try {
            $header = fgetcsv($handle, escape: '');
            if (!is_array($header)) {
                throw new ServiceException('The CSV file is empty', Response::HTTP_BAD_REQUEST);
            }

            // Strip a UTF-8 BOM so a spreadsheet-exported file's first header
            // does not silently fail the required-column check below.
            $header = array_map(
                static fn(mixed $c): string => strtolower(trim(ltrim((string) $c, "\xEF\xBB\xBF"))),
                $header
            );

            $missing = array_diff(AdminUserService::importColumns(), $header);
            if (!empty($missing)) {
                throw new ServiceException(
                    'Missing required CSV column(s): ' . implode(', ', $missing),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $rows = [];
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                // Skip blank trailing lines rather than reporting them as errors.
                // fgetcsv() yields [null] for an empty line.
                if ($values === [null] || $values === ['']) {
                    continue;
                }

                $row = [];
                foreach ($header as $i => $column) {
                    $row[$column] = trim((string) ($values[$i] ?? ''));
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
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

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

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
            return $this->responseFormatter->formatThrowable($e);
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

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Check if user can create users in the specified groups
            if (isset($data['group_ids']) && is_array($data['group_ids'])) {
                foreach ($data['group_ids'] as $groupId) {
                    $groupIdInt = is_numeric($groupId) ? (int) $groupId : 0;
                    if (!$this->dataAccessSecurityService->hasPermission(
                        $currentUserId,
                        LookupService::RESOURCE_TYPES_GROUP,
                        $groupIdInt,
                        DataAccessSecurityService::PERMISSION_CREATE
                    )) {
                        return $this->responseFormatter->formatError(
                            'Access denied: Cannot create users in group ' . $groupIdInt,
                            Response::HTTP_FORBIDDEN
                        );
                    }
                }
            }

            $user = $this->adminUserService->createUser($this->toAssocArray($data));

            return $this->responseFormatter->formatSuccess(
                $user,
                'responses/admin/users/user_envelope',
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
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

            $user = $this->adminUserService->updateUser($currentUserId, $userId, $this->toAssocArray($data));

            return $this->responseFormatter->formatSuccess($user, 'responses/admin/users/user_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
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
            return $this->responseFormatter->formatThrowable($e);
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

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

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

            // Require an explicit boolean: previously a missing/invalid body
            // silently defaulted to blocking the user, which is a surprising
            // and risky default for a destructive toggle.
            $data = json_decode($request->getContent(), true);
            if (!is_array($data) || !array_key_exists('blocked', $data) || !is_bool($data['blocked'])) {
                return $this->responseFormatter->formatError(
                    "Validation failed: 'blocked' must be provided as a boolean",
                    Response::HTTP_BAD_REQUEST
                );
            }

            $user = $this->adminUserService->toggleUserBlock($userId, $data['blocked']);

            return $this->responseFormatter->formatSuccess($user, 'responses/admin/users/user_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
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

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

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
            return $this->responseFormatter->formatThrowable($e);
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

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

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
            return $this->responseFormatter->formatThrowable($e);
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

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

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
            $groupIds = is_array($data) ? ($data['group_ids'] ?? []) : [];

            if (!is_array($groupIds) || empty($groupIds)) {
                return $this->responseFormatter->formatError(
                    'group_ids array is required',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $groups = $this->adminUserService->addGroupsToUser($userId, $groupIds);

            return $this->responseFormatter->formatSuccess(['groups' => $groups]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
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
            $groupIds = is_array($data) ? ($data['group_ids'] ?? []) : [];
            
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
            return $this->responseFormatter->formatThrowable($e);
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
            $roleIds = is_array($data) ? ($data['role_ids'] ?? []) : [];
            
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
            return $this->responseFormatter->formatThrowable($e);
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
            $roleIds = is_array($data) ? ($data['role_ids'] ?? []) : [];
            
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
            return $this->responseFormatter->formatThrowable($e);
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
            return $this->responseFormatter->formatThrowable($e);
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
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Start an impersonation session.
     *
     * Returns a short-lived JWT plus the target email and expiry. Callers
     * are expected to put the JWT into an httpOnly cookie (see the BFF
     * route `/api/admin/users/{id}/impersonate` on the Next.js side) and
     * never expose it to JavaScript.
     *
     * @route /admin/users/{userId}/impersonate
     * @method POST
     */
    public function impersonateUser(int $userId): JsonResponse
    {
        try {
            $currentUser = $this->userContextService->getCurrentUser();
            if ($currentUser === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $result = $this->adminUserService->impersonateUser((int) $currentUser->getId(), $userId);
            return $this->responseFormatter->formatSuccess($result);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Stop an impersonation session.
     *
     * The endpoint must be called WITH the impersonation JWT in the
     * Authorization header (i.e. while still impersonating). Server-side
     * we recover the original admin id from the `act.sub` claim, blacklist
     * the token so it cannot be replayed, and write an audit entry.
     *
     * Idempotent: calling without an impersonation token returns 200 with
     * `stopped: false` so the BFF can safely retry.
     *
     * @route /admin/users/stop-impersonate
     * @method POST
     */
    public function stopImpersonateUser(Request $request): JsonResponse
    {
        try {
            $currentUser = $this->userContextService->getCurrentUser();
            if ($currentUser === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $authHeader = $request->headers->get('Authorization', '');
            if (!str_starts_with($authHeader, 'Bearer ')) {
                return $this->responseFormatter->formatSuccess(['stopped' => false]);
            }

            $token = substr($authHeader, 7);
            $payload = $request->attributes->get('_jwt_payload');
            if (!is_array($payload)) {
                return $this->responseFormatter->formatSuccess(['stopped' => false]);
            }

            if (empty($payload['impersonation'])) {
                return $this->responseFormatter->formatSuccess(['stopped' => false]);
            }

            $act = is_array($payload['act'] ?? null) ? $payload['act'] : [];
            $adminRaw = $act['id_users'] ?? $act['sub'] ?? $payload['impersonated_by'] ?? 0;
            $adminUserId = is_numeric($adminRaw) ? (int) $adminRaw : 0;
            $targetRaw = $payload['id_users'] ?? $currentUser->getId();
            $targetUserId = is_numeric($targetRaw) ? (int) $targetRaw : 0;

            if ($adminUserId <= 0) {
                return $this->responseFormatter->formatError(
                    'Invalid impersonation token',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $result = $this->adminUserService->stopImpersonateUser($adminUserId, $targetUserId, $token);
            return $this->responseFormatter->formatSuccess($result);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Read `user_ids` from a schema-validated bulk request body.
     *
     * @return array<mixed>
     */
    private function userIdsFrom(mixed $data): array
    {
        $userIds = $this->toAssocArray($data)['user_ids'] ?? null;

        return is_array($userIds) ? $userIds : [];
    }

    /**
     * Get the group ID for a user (helper method for permission checks)
     */
    private function getUserGroupId(int $userId): int
    {
        try {
            // Get user's primary group ID
            $conn = $this->entityManager->getConnection();
            $sql = "SELECT id_groups FROM rel_groups_users WHERE id_users = :user_id LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue('user_id', $userId, \Doctrine\DBAL\ParameterType::INTEGER);
            $result = $stmt->executeQuery();
            $row = $result->fetchAssociative();

            if ($row === false) {
                return 0;
            }
            $idGroups = $row['id_groups'] ?? null;

            return is_numeric($idGroups) ? (int) $idGroups : 0;
        } catch (\Exception $e) {
            return 0; // Default to no access if we can't determine group
        }
    }
} 