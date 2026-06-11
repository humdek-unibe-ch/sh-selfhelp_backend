<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\System\MaintenanceModeService;
use App\Service\System\SystemAdvisoryService;
use App\Service\System\SystemHealthService;
use App\Service\System\SystemInstanceService;
use App\Service\System\SystemUpdateService;
use App\Service\System\SystemVersionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Instance-scoped system maintenance / update endpoints.
 *
 * Thin controller: validates input, calls the system services, returns the
 * standard envelope. It never touches Docker and never accepts an instance id
 * from the client — the services resolve the current instance server-side.
 */
class SystemController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly SystemVersionService $systemVersionService,
        private readonly SystemUpdateService $systemUpdateService,
        private readonly SystemHealthService $systemHealthService,
        private readonly SystemAdvisoryService $systemAdvisoryService,
        private readonly MaintenanceModeService $maintenanceModeService,
        private readonly SystemInstanceService $systemInstanceService,
        private readonly UserContextService $userContext,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * GET /admin/system/version — current instance version summary.
     */
    public function getVersion(): JsonResponse
    {
        return $this->responseFormatter->formatSuccess(
            $this->systemVersionService->getVersion(),
            'responses/admin/system_version'
        );
    }

    /**
     * GET /admin/system/health — aggregated, instance-scoped health/status.
     */
    public function getHealth(): JsonResponse
    {
        return $this->responseFormatter->formatSuccess(
            $this->systemHealthService->getHealth(),
            'responses/admin/system_health'
        );
    }

    /**
     * GET /admin/system/advisories — security advisories from the registry feed
     * filtered to the components installed on THIS instance (under
     * `admin.system.read`). Fails soft to `available: false` when offline.
     */
    public function getAdvisories(): JsonResponse
    {
        return $this->responseFormatter->formatSuccess(
            $this->systemAdvisoryService->getAdvisories(),
            'responses/admin/system_advisories'
        );
    }

    /**
     * GET /admin/system/update/releases — core versions published in the
     * official registry (newest first) for the update version picker. Fails
     * soft to `available: false` when the registry is unreachable.
     */
    public function getUpdateReleases(): JsonResponse
    {
        return $this->responseFormatter->formatSuccess(
            $this->systemUpdateService->getAvailableReleases(),
            'responses/admin/update_releases'
        );
    }

    /**
     * GET /admin/system/update/preflight?target=<version> — compatibility
     * preflight for the current instance.
     */
    public function getUpdatePreflight(Request $request): JsonResponse
    {
        $target = $request->query->get('target');
        if (!is_string($target) || $target === '') {
            throw new ServiceException('Query parameter "target" (target version) is required.', Response::HTTP_BAD_REQUEST);
        }

        return $this->responseFormatter->formatSuccess(
            $this->systemUpdateService->getPreflight($target),
            'responses/admin/update_preflight'
        );
    }

    /**
     * POST /admin/system/update/request — request an update for the current
     * instance. Returns 202 Accepted; the SelfHelp Manager performs the work.
     */
    public function requestUpdate(Request $request): JsonResponse
    {
        // Cross-instance guard FIRST: any client-supplied instance id is denied
        // and logged before schema validation, satisfying the hard rule that
        // the backend never trusts a browser-provided instance id.
        $raw = json_decode($request->getContent(), true);
        if (is_array($raw) && array_key_exists('instance_id', $raw)) {
            $this->systemUpdateService->denyCrossInstance($raw['instance_id']);
        }

        $data = $this->validateRequest($request, 'requests/admin/update_request', $this->jsonSchemaValidationService);

        return $this->responseFormatter->formatSuccess(
            $this->systemUpdateService->requestUpdate($data),
            'responses/admin/update_request',
            Response::HTTP_ACCEPTED
        );
    }

    /**
     * GET /admin/system/update/status — status of the latest update operation
     * for the current instance.
     */
    public function getUpdateStatus(): JsonResponse
    {
        return $this->responseFormatter->formatSuccess(
            $this->systemUpdateService->getStatus(),
            'responses/admin/update_status'
        );
    }

    /**
     * GET /admin/system/maintenance — current maintenance-mode state for THIS
     * instance (under `admin.system.read`).
     */
    public function getMaintenance(): JsonResponse
    {
        return $this->responseFormatter->formatSuccess(
            $this->buildMaintenanceState(),
            'responses/admin/system_maintenance'
        );
    }

    /**
     * PUT /admin/system/maintenance — enable/disable maintenance mode for THIS
     * instance (under `admin.system.maintenance`). The env hard switch
     * (SELFHELP_MAINTENANCE_MODE) cannot be cleared from the web: a request to
     * disable while it is env-forced is rejected with a clear 409.
     */
    public function setMaintenance(Request $request): JsonResponse
    {
        $data = $this->validateRequest($request, 'requests/admin/maintenance_set', $this->jsonSchemaValidationService);

        $enabled = (bool) ($data['enabled'] ?? false);
        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';

        if (!$enabled && $this->maintenanceModeService->isForcedByEnv()) {
            throw new ServiceException(
                'Maintenance mode is enforced by server configuration (SELFHELP_MAINTENANCE_MODE) and cannot be disabled from the CMS. Clear it in the instance environment.',
                Response::HTTP_CONFLICT
            );
        }

        $actor = $this->resolveActor();
        if ($enabled) {
            $this->maintenanceModeService->enable($message, $actor);
        } else {
            $this->maintenanceModeService->disable();
        }

        return $this->responseFormatter->formatSuccess(
            $this->buildMaintenanceState(),
            'responses/admin/system_maintenance'
        );
    }

    /**
     * Maintenance state enriched with the (read-only) safe-mode flag so the UI
     * can show both operational toggles in one place. Safe mode is intentionally
     * not web-writable — it is toggled by the operator via the env hard switch or
     * the `selfhelp:plugin:safe-mode` CLI while plugins are broken.
     *
     * @return array{enabled: bool, forced_by_env: bool, message: string, since: string, updated_by: string, safe_mode: bool}
     */
    private function buildMaintenanceState(): array
    {
        $state = $this->maintenanceModeService->getState();
        $state['safe_mode'] = $this->systemInstanceService->isSafeMode();

        return $state;
    }

    /** Acting operator id for the maintenance audit field; never PII-heavy. */
    private function resolveActor(): string
    {
        $id = $this->userContext->getActualUserId();

        return $id !== null ? 'user:' . $id : 'unknown';
    }
}
