<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\AdminRoleService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin Role Controller
 *
 * Handles all role management operations for admin interface
 */
class AdminRoleController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly AdminRoleService $adminRoleService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        private readonly UserContextService $userContextService,
    ) {
    }

    /**
     * Get roles with pagination, search, and sorting
     *
     * Query parameters:
     * - page: which page of results (default: 1)
     * - pageSize: how many roles per page (default: 20, max: 100)
     * - search: search term for name or description
     * - sort: sort field (name, description)
     * - sortDirection: asc or desc (default: asc)
     */
    #[Route('/cms-api/v1/admin/roles', name: 'admin_roles_list', methods: ['GET'])]
    public function getRoles(Request $request): JsonResponse
    {
        try {
            $callerId = $this->requireCallerId();
            $page = (int) $request->query->get('page', 1);
            $pageSize = (int) $request->query->get('pageSize', 20);
            $searchRaw = $request->query->get('search');
            $sortRaw = $request->query->get('sort');
            $directionRaw = $request->query->get('sortDirection');

            $result = $this->adminRoleService->getRoles(
                $callerId,
                $page,
                $pageSize,
                is_string($searchRaw) ? $searchRaw : null,
                is_string($sortRaw) ? $sortRaw : null,
                ($directionRaw === 'desc') ? 'desc' : 'asc'
            );

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/roles/roles');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get single role by ID with permissions
     */
    #[Route('/cms-api/v1/admin/roles/{roleId}', name: 'admin_roles_show', methods: ['GET'])]
    public function getRoleById(int $roleId): JsonResponse
    {
        try {
            $role = $this->adminRoleService->getRoleById($this->requireCallerId(), $roleId);
            return $this->responseFormatter->formatSuccess($role, 'responses/admin/roles/role');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Create new role
     */
    #[Route('/cms-api/v1/admin/roles', name: 'admin_roles_create', methods: ['POST'])]
    public function createRole(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/create_role', $this->jsonSchemaValidationService);

            $role = $this->adminRoleService->createRole($this->requireCallerId(), $this->toAssocArray($data));

            return $this->responseFormatter->formatSuccess(
                $role,
                'responses/admin/roles/role',
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Update existing role
     */
    #[Route('/cms-api/v1/admin/roles/{roleId}', name: 'admin_roles_update', methods: ['PUT'])]
    public function updateRole(int $roleId, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_role', $this->jsonSchemaValidationService);

            $role = $this->adminRoleService->updateRole($this->requireCallerId(), $roleId, $this->toAssocArray($data));

            return $this->responseFormatter->formatSuccess($role, 'responses/admin/roles/role');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Delete role
     */
    #[Route('/cms-api/v1/admin/roles/{roleId}', name: 'admin_roles_delete', methods: ['DELETE'])]
    public function deleteRole(int $roleId): JsonResponse
    {
        try {
            $this->adminRoleService->deleteRole($this->requireCallerId(), $roleId);

            return $this->responseFormatter->formatSuccess(['deleted' => true]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get role permissions
     */
    #[Route('/cms-api/v1/admin/roles/{roleId}/permissions', name: 'admin_roles_permissions_show', methods: ['GET'])]
    public function getRolePermissions(int $roleId): JsonResponse
    {
        try {
            $permissions = $this->adminRoleService->getRolePermissions($roleId);
            return $this->responseFormatter->formatSuccess(['permissions' => $permissions]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Add permissions to role (bulk)
     */
    #[Route('/cms-api/v1/admin/roles/{roleId}/permissions', name: 'admin_roles_permissions_add', methods: ['POST'])]
    public function addPermissionsToRole(int $roleId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $permissionIds = is_array($data) ? ($data['permission_ids'] ?? []) : [];

            if (!is_array($permissionIds) || empty($permissionIds)) {
                return $this->responseFormatter->formatError(
                    'permission_ids array is required',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $permissions = $this->adminRoleService->addPermissionsToRole(
                $this->requireCallerId(),
                $roleId,
                $this->toIntList($permissionIds)
            );
            return $this->responseFormatter->formatSuccess(['permissions' => $permissions]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Remove permissions from role (bulk)
     */
    #[Route('/cms-api/v1/admin/roles/{roleId}/permissions', name: 'admin_roles_permissions_remove', methods: ['DELETE'])]
    public function removePermissionsFromRole(int $roleId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $permissionIds = is_array($data) ? ($data['permission_ids'] ?? []) : [];

            if (!is_array($permissionIds) || empty($permissionIds)) {
                return $this->responseFormatter->formatError(
                    'permission_ids array is required',
                    Response::HTTP_BAD_REQUEST
                );
            }

            $permissions = $this->adminRoleService->removePermissionsFromRole(
                $this->requireCallerId(),
                $roleId,
                $this->toIntList($permissionIds)
            );
            return $this->responseFormatter->formatSuccess(['permissions' => $permissions]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Update role permissions (bulk replace)
     */
    #[Route('/cms-api/v1/admin/roles/{roleId}/permissions', name: 'admin_roles_permissions_update', methods: ['PUT'])]
    public function updateRolePermissions(int $roleId, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_role_permissions', $this->jsonSchemaValidationService);

            $permissions = $this->adminRoleService->updateRolePermissions(
                $this->requireCallerId(),
                $roleId,
                $this->toIntList($data['permission_ids'] ?? null)
            );
            return $this->responseFormatter->formatSuccess(['permissions' => $permissions]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get all available permissions
     *
     * @route /admin/permissions
     * @method GET
     */
    #[Route('/cms-api/v1/admin/permissions', name: 'admin_permissions_list', methods: ['GET'])]
    public function getAllPermissions(): JsonResponse
    {
        try {
            $permissions = $this->adminRoleService->getAllPermissions($this->requireCallerId());
            return $this->responseFormatter->formatSuccess(
                ['permissions' => $permissions],
                'responses/admin/permissions/permissions'
            );
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    /**
     * Get all API routes with their required permissions
     * This allows the frontend to check permissions before making API calls
     *
     * @route /cms-api/v1/admin/api-routes
     * @method GET
     */
    public function getApiRoutesWithPermissions(): JsonResponse
    {
        try {
            $routes = $this->adminRoleService->getApiRoutesWithPermissions();
            return $this->responseFormatter->formatSuccess([
                'routes' => $routes,
                'total' => count($routes)
            ]);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

    private function requireCallerId(): int
    {
        $userId = $this->userContextService->getCurrentUser()?->getId();
        if ($userId === null) {
            throw new \App\Exception\ServiceException('User not authenticated', Response::HTTP_UNAUTHORIZED);
        }

        return $userId;
    }
}
