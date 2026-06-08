<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Manager;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\ServiceException;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\System\SystemUpdateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manager-facing update loop endpoints (SelfHelp Manager -> CMS).
 *
 * These routes are intentionally registered WITHOUT permission rows, so the
 * JWT/ACL pipeline in {@see \App\EventListener\ApiSecurityListener} treats them
 * as public. Access is instead gated by a per-instance manager bearer token
 * (`SELFHELP_MANAGER_TOKEN`) verified in-controller with a constant-time
 * comparison. The CMS only ever exposes operations for ITS OWN instance — the
 * service layer scopes every read/write to the server-derived instance id, so a
 * manager bound to one instance can never read or affect another.
 *
 * The browser never calls these endpoints; the admin UI uses the
 * `/admin/system/update/*` routes which require real admin permissions.
 */
class SystemManagerController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly SystemUpdateService $systemUpdateService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
        private readonly string $managerToken,
    ) {
    }

    /**
     * GET /manager/system/update/pending — claim the next pending operation for
     * THIS instance. Returns 404 when nothing is claimable so the manager loop
     * can treat "no work" and "wrong instance" identically.
     */
    public function getPending(Request $request): JsonResponse
    {
        $this->assertManagerToken($request);

        $operation = $this->systemUpdateService->claimPendingOperation();
        if ($operation === null) {
            return $this->responseFormatter->formatError(
                'No pending update operation for this instance.',
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->responseFormatter->formatSuccess(
            $operation,
            'responses/manager/update_pending'
        );
    }

    /**
     * POST /manager/system/update/{operationId}/status — write a lifecycle
     * status/progress update back for an operation owned by THIS instance.
     */
    public function postStatus(Request $request, string $operationId): JsonResponse
    {
        $this->assertManagerToken($request);

        $data = $this->validateRequest($request, 'requests/manager/update_status', $this->jsonSchemaValidationService);

        $status = is_string($data['status'] ?? null) ? $data['status'] : '';
        $progressPercent = is_int($data['progress_percent'] ?? null) ? $data['progress_percent'] : 0;
        $message = isset($data['message']) && is_string($data['message']) ? $data['message'] : null;

        // Normalize the freeform steps payload to array<int, array<string,mixed>>
        // so the service contract (and stored JSON) stays well-typed.
        $steps = null;
        $rawSteps = $data['steps'] ?? null;
        if (is_array($rawSteps)) {
            $steps = [];
            foreach ($rawSteps as $step) {
                if (!is_array($step)) {
                    continue;
                }
                $normalized = [];
                foreach ($step as $key => $value) {
                    $normalized[(string) $key] = $value;
                }
                $steps[] = $normalized;
            }
        }

        return $this->responseFormatter->formatSuccess(
            $this->systemUpdateService->recordManagerStatus($operationId, $status, $progressPercent, $steps, $message),
            'responses/manager/update_status_ack'
        );
    }

    /**
     * Verify the per-instance manager bearer token in constant time. When no
     * token is configured the manager loop is disabled and every call is denied,
     * so an unconfigured instance can never be driven by an anonymous caller.
     */
    private function assertManagerToken(Request $request): void
    {
        $configured = $this->managerToken;
        if ($configured === '') {
            throw new ServiceException('Manager loop is not enabled for this instance.', Response::HTTP_UNAUTHORIZED);
        }

        $header = (string) $request->headers->get('Authorization', '');
        $presented = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1) {
            $presented = trim($m[1]);
        }

        if ($presented === '' || !hash_equals($configured, $presented)) {
            throw new ServiceException('Invalid manager token.', Response::HTTP_UNAUTHORIZED);
        }
    }
}
