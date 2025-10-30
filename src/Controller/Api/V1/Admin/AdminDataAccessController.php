<?php

namespace App\Controller\Api\V1\Admin;

use App\Repository\RoleDataAccessRepository;
use App\Service\CMS\Admin\AdminDataAccessService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Admin Data Access Controller
 *
 * Handles management of custom data access permissions for roles
 * Provides APIs for configuring role-based data access control
 */
class AdminDataAccessController extends AbstractController
{
    public function __construct(
        private readonly RoleDataAccessRepository $roleDataAccessRepository,
        private readonly AdminDataAccessService $adminDataAccessService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        private readonly ValidatorInterface $validator
    ) {
    }

    /**
     * Get all roles with their custom data access permissions
     */
    public function getRolesWithPermissions(): JsonResponse
    {
        try {
            $rolesWithPermissions = $this->roleDataAccessRepository->getAllRolesWithPermissions();

            // Group by role for better structure
            $grouped = [];
            foreach ($rolesWithPermissions as $row) {
                $roleId = $row['role_id'];

                if (!isset($grouped[$roleId])) {
                    $grouped[$roleId] = [
                        'role_id' => $row['role_id'],
                        'role_name' => $row['role_name'],
                        'role_description' => $row['role_description'],
                        'permissions' => []
                    ];
                }

                if ($row['id_resourceTypes']) {
                    $grouped[$roleId]['permissions'][] = [
                        'resource_type_id' => $row['id_resourceTypes'],
                        'resource_type_name' => $row['resource_type_name'],
                        'resource_id' => $row['resource_id'],
                        'crud_permissions' => $row['crud_permissions'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at']
                    ];
                }
            }

            return $this->responseFormatter->formatSuccess(array_values($grouped), 'responses/admin/data_access/roles_with_permissions');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                'Failed to retrieve roles with permissions: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Set all permissions for a role (bulk operation)
     * Replaces all existing permissions for the role with the provided set
     * Send empty array to remove all permissions
     */
    public function setRolePermissions(Request $request, int $roleId): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), false);

            // Validate request data using JSON schema
            $validationErrors = $this->jsonSchemaValidationService->validate(
                $data,
                'requests/admin/data_access_role_permissions_set'
            );

            if (!empty($validationErrors)) {
                return $this->responseFormatter->formatError(
                    'Validation failed: ' . $validationErrors[0],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Use the bulk set functionality from the service
            $result = $this->adminDataAccessService->setRolePermissions($roleId, $data->permissions);

            return $this->responseFormatter->formatSuccess(
                [
                    'role_id' => $roleId,
                    'message' => 'Role permissions updated successfully',
                    'changes' => $result
                ],
                'responses/admin/data_access/bulk_operation_result',
                Response::HTTP_OK
            );
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                'Failed to set role permissions: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }


    /**
     * Get effective permissions for a role (including multiple roles if user has them)
     */
    public function getRoleEffectivePermissions(int $roleId): JsonResponse
    {
        try {
            $result = $this->adminDataAccessService->getRoleEffectivePermissions($roleId);

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/data_access/effective_permissions');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                'Failed to retrieve effective permissions: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
