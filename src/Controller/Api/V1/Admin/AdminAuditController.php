<?php

namespace App\Controller\Api\V1\Admin;

use App\Service\CMS\Admin\AdminAuditService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin Audit Controller
 *
 * Handles audit log management for data access security monitoring
 * Provides APIs for viewing and analyzing security audit trails
 */
class AdminAuditController extends AbstractController
{
    public function __construct(
        private readonly AdminAuditService $adminAuditService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService
    ) {
    }

    /**
     * Get data access audit logs with filtering and pagination
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDataAccessLogs(Request $request): JsonResponse
    {
        try {
            $result = $this->adminAuditService->getDataAccessLogs($request);

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/audit/data_access_logs');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                'Failed to retrieve audit logs: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get specific audit log details
     *
     * @route GET /admin/audit/data-access/{id}
     */
    public function getDataAccessLog(int $id): JsonResponse
    {
        try {
            $auditLog = $this->adminAuditService->getDataAccessLog($id);

            if (!$auditLog) {
                return $this->responseFormatter->formatError(
                    'Audit log not found',
                    Response::HTTP_NOT_FOUND
                );
            }

            return $this->responseFormatter->formatSuccess($auditLog, 'responses/admin/audit/data_access_log');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                'Failed to retrieve audit log: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get audit statistics and summaries
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDataAccessStats(Request $request): JsonResponse
    {
        try {
            $stats = $this->adminAuditService->getDataAccessStats($request);

            return $this->responseFormatter->formatSuccess($stats, 'responses/admin/audit/data_access_stats');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                'Failed to retrieve audit statistics: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
