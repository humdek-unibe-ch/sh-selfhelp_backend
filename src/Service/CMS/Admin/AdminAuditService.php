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

        return $this->auditRepository->findAuditLogs(
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
    }

    /**
     * Get specific audit log details by ID
     */
    public function getDataAccessLog(int $id)
    {
        return $this->auditRepository->findAuditLogById($id);
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
}
