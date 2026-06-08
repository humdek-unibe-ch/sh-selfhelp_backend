<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\ServiceException;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
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
}
