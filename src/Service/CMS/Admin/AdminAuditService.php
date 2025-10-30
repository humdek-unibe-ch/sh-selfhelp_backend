<?php

namespace App\Service\CMS\Admin;

use App\Repository\DataAccessAuditRepository;
use App\Service\Core\BaseService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service for handling audit-related operations in the admin panel
 * ENTITY RULE
 */
class AdminAuditService extends BaseService
{
    public function __construct(
        private readonly DataAccessAuditRepository $auditRepository
    ) {
    }

    /**
     * Get data access audit logs with filtering and pagination
     * Processes array results from repository for memory efficiency
     */
    public function getDataAccessLogs(Request $request): array
    {
        $userId = $request->query->get('user_id') ? (int)$request->query->get('user_id') : null;
        $resourceType = $request->query->get('resource_type');
        $action = $request->query->get('action');
        $permissionResult = $request->query->get('permission_result');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $httpMethod = $request->query->get('http_method');
        $page = max(1, (int)$request->query->get('page', 1));
        $pageSize = min(100, max(1, (int)$request->query->get('pageSize', 20)));

        $result = $this->auditRepository->findAuditLogs(
            $userId,
            $resourceType,
            $action,
            $permissionResult,
            $dateFrom,
            $dateTo,
            $httpMethod,
            $page,
            $pageSize
        );

        // Format the array results for API response
        $result['data'] = array_map(function ($auditLog) {
            return $this->formatAuditLogForApi($auditLog);
        }, $result['data']);

        return $result;
    }

    /**
     * Get specific audit log details by ID
     */
    public function getDataAccessLog(int $id): ?array
    {
        $auditLog = $this->auditRepository->findAuditLogById($id);

        if (!$auditLog) {
            return null;
        }

        return $this->formatAuditLogForApi($auditLog);
    }

    /**
     * Get audit statistics and summaries
     */
    public function getDataAccessStats(Request $request): array
    {
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        return $this->auditRepository->getAuditStatistics($dateFrom, $dateTo);
    }

    /**
     * Format audit log array data for API response
     */
    private function formatAuditLogForApi(array $auditLog): array
    {
        return [
            // Raw database fields as required by schema
            'id' => $auditLog['id'],
            'idUsers' => $auditLog['idUsers'],
            'idResourceTypes' => $auditLog['idResourceTypes'],
            'resourceId' => $auditLog['resourceId'],
            'idActions' => $auditLog['idActions'],
            'idPermissionResults' => $auditLog['idPermissionResults'],
            'crudPermission' => $auditLog['crudPermission'],
            'httpMethod' => $auditLog['httpMethod'],
            'requestBodyHash' => $auditLog['requestBodyHash'],
            'ipAddress' => $auditLog['ipAddress'],
            'userAgent' => $auditLog['userAgent'],
            'requestUri' => $auditLog['requestUri'],
            'notes' => $auditLog['notes'],
            'createdAt' => $auditLog['createdAt']?->format('c'),

            // Formatted nested objects as required by schema
            'user' => [
                'id' => $auditLog['idUsers'],
                'username' => $auditLog['username'] ?? null,
                'email' => $auditLog['email'] ?? null,
            ],
            'resourceType' => [
                'id' => $auditLog['idResourceTypes'],
                'lookupCode' => $auditLog['resourceTypeCode'] ?? null,
                'lookupValue' => $auditLog['resourceTypeName'] ?? null,
                'code' => $auditLog['resourceTypeCode'] ?? null,  // User-friendly alias
                'name' => $auditLog['resourceTypeName'] ?? null,  // User-friendly alias
            ],
            'action' => [
                'id' => $auditLog['idActions'],
                'lookupCode' => $auditLog['actionCode'] ?? null,
                'lookupValue' => $auditLog['actionName'] ?? null,
                'code' => $auditLog['actionCode'] ?? null,  // User-friendly alias
                'name' => $auditLog['actionName'] ?? null,  // User-friendly alias
            ],
            'permissionResult' => [
                'id' => $auditLog['idPermissionResults'],
                'lookupCode' => $auditLog['permissionResultCode'] ?? null,
                'lookupValue' => $auditLog['permissionResultName'] ?? null,
                'code' => $auditLog['permissionResultCode'] ?? null,  // User-friendly alias
                'name' => $auditLog['permissionResultName'] ?? null,  // User-friendly alias
            ],
        ];
    }
}
