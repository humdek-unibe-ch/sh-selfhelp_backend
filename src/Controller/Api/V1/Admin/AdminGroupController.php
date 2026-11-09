<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\AdminGroupService;
use App\Service\CMS\Admin\AssetFolderAclService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\Security\DataAccessSecurityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin Group Controller
 * 
 * Handles all group management operations for admin interface
 */
class AdminGroupController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly AdminGroupService $adminGroupService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
        private readonly UserContextService $userContextService,
        private readonly AssetFolderAclService $assetFolderAclService
    ) {
    }

    /**
     * Get groups with pagination, search, and sorting
     * 
     */
    #[Route('/cms-api/v1/admin/groups', name: 'admin_groups_list', methods: ['GET'])]
    public function getGroups(Request $request): JsonResponse
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

            // Use service-level filtering for groups with permission checking
            $result = $this->adminGroupService->getFilteredGroups(
                $userId,
                $page,
                $pageSize,
                $search,
                $sort,
                $sortDirection
            );

            return $this->responseFormatter->formatSuccess($result);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get single group by ID with ACLs
     * Filtered by group access permissions
     *
     * @route /admin/groups/{groupId}
     * @method GET
     */
    public function getGroupById(int $groupId): JsonResponse
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Check if user has permission to read this specific group
            // Admin users bypass permission checks
            if (!$this->dataAccessSecurityService->userHasAdminRole($currentUserId)) {
                if (!$this->dataAccessSecurityService->hasPermission(
                    $currentUserId,
                    LookupService::RESOURCE_TYPES_GROUP,
                    $groupId,
                    DataAccessSecurityService::PERMISSION_READ
                )) {
                    return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
                }
            }

            $group = $this->adminGroupService->getGroupById($groupId);
            return $this->responseFormatter->formatSuccess($group);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * List the members of a group.
     */
    public function getGroupMembers(int $groupId): JsonResponse
    {
        try {
            $currentUserId = $this->userContextService->getCurrentUser()?->getId();

            if ($currentUserId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Same access rule as getGroupById: admins bypass; others need
            // READ on this specific group.
            if (!$this->dataAccessSecurityService->userHasAdminRole($currentUserId)) {
                if (!$this->dataAccessSecurityService->hasPermission(
                    $currentUserId,
                    LookupService::RESOURCE_TYPES_GROUP,
                    $groupId,
                    DataAccessSecurityService::PERMISSION_READ
                )) {
                    return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
                }
            }

            $members = $this->adminGroupService->getGroupMembers($groupId);
            return $this->responseFormatter->formatSuccess($members, 'responses/admin/common/group_members');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Create new group
     *
     * @route /admin/groups
     * @method POST
     */
    public function createGroup(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/create_group', $this->jsonSchemaValidationService);
            
            $group = $this->adminGroupService->createGroup($this->toAssocArray($data));
            
            return $this->responseFormatter->formatSuccess(
                $group,
                null,
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Update existing group
     * Filtered by group access permissions
     *
     * @route /admin/groups/{groupId}
     * @method PUT
     */
    public function updateGroup(int $groupId, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_group', $this->jsonSchemaValidationService);
            $userId = $this->userContextService->getCurrentUser()?->getId();

            if ($userId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Check if user has permission to update this specific group
            // Admin users bypass permission checks
            if (!$this->dataAccessSecurityService->userHasAdminRole($userId)) {
                if (!$this->adminGroupService->canAccessGroup($userId, $groupId, DataAccessSecurityService::PERMISSION_UPDATE)) {
                    return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
                }
            }

            $group = $this->adminGroupService->updateGroup($userId, $groupId, $this->toAssocArray($data));
            // Group cache is automatically invalidated by the service

            return $this->responseFormatter->formatSuccess($group);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Delete group
     * Filtered by group access permissions
     *
     * @route /admin/groups/{groupId}
     * @method DELETE
     */
    public function deleteGroup(int $groupId): JsonResponse
    {
        try {
            $userId = $this->userContextService->getCurrentUser()?->getId();

            if ($userId === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            // Check if user has permission to delete this specific group
            // Admin users bypass permission checks
            if (!$this->dataAccessSecurityService->userHasAdminRole($userId)) {
                if (!$this->adminGroupService->canAccessGroup($userId, $groupId, DataAccessSecurityService::PERMISSION_DELETE)) {
                    return $this->responseFormatter->formatError('Access denied', Response::HTTP_FORBIDDEN);
                }
            }

            $this->adminGroupService->deleteGroup($userId, $groupId);

            // Group cache is automatically invalidated by the service

            return $this->responseFormatter->formatSuccess(['deleted' => true]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get group ACLs
     * 
     * @route /admin/groups/{groupId}/acls
     * @method GET
     */
    public function getGroupAcls(int $groupId): JsonResponse
    {
        try {
            $acls = $this->adminGroupService->getGroupAcls($groupId);
            return $this->responseFormatter->formatSuccess(['acls' => $acls]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Update group ACLs (bulk update)
     * 
     * @route /admin/groups/{groupId}/acls
     * @method PUT
     */
    public function updateGroupAcls(int $groupId, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_group_acls', $this->jsonSchemaValidationService);
            
            $acls = $this->adminGroupService->updateGroupAcls($groupId, $this->asListOfArrays($data['acls'] ?? null));

            // Permissions cache is automatically invalidated by the service

            return $this->responseFormatter->formatSuccess(['acls' => $acls]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get the asset-folder ACLs a group holds.
     *
     * @route /admin/groups/{groupId}/asset-acls
     * @method GET
     */
    public function getGroupAssetAcls(int $groupId): JsonResponse
    {
        try {
            $result = $this->assetFolderAclService->getGroupAssetAcls($groupId);
            return $this->responseFormatter->formatSuccess($result, 'responses/admin/groups/group_asset_acls_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Replace the asset-folder ACL set a group holds (bulk update).
     *
     * @route /admin/groups/{groupId}/asset-acls
     * @method PUT
     */
    public function updateGroupAssetAcls(int $groupId, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_group_asset_acls', $this->jsonSchemaValidationService);

            $result = $this->assetFolderAclService->updateGroupAssetAcls(
                $groupId,
                $this->normalizeAssetAclEntries($data['acls'] ?? null)
            );

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/groups/group_asset_acls_envelope');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Coerce the validated `acls` payload into the service's typed entry list.
     *
     * @param mixed $raw
     * @return list<array{folder: string, access_level: string}>
     */
    private function normalizeAssetAclEntries(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $entries = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entries[] = [
                'folder' => is_string($item['folder'] ?? null) ? $item['folder'] : '',
                'access_level' => is_string($item['access_level'] ?? null) ? $item['access_level'] : '',
            ];
        }

        return $entries;
    }
}