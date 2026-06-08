<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Entity\ScheduledJobRunnerRun;
use App\Exception\RequestValidationException;
use App\Service\Auth\UserContextService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\ScheduledJobRunnerService;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API for the Docker scheduled-job runner: status, settings, enable,
 * disable, and manual "run due jobs now".
 */
class AdminScheduledJobRunnerController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly ScheduledJobRunnerService $runnerService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly UserContextService $userContextService,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * @route /admin/scheduled-jobs/runner/status
     * @method GET
     */
    public function getStatus(): JsonResponse
    {
        try {
            $payload = $this->runnerService->buildStatusPayload();

            return $this->responseFormatter->formatSuccess($payload, 'responses/admin/scheduled_jobs/runner_status');
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @route /admin/scheduled-jobs/runner/settings
     * @method PUT
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_runner_settings', $this->jsonSchemaValidationService);
            $this->runnerService->updateSettings($this->toAssocArray($data), $this->userContextService->getCurrentUser());

            return $this->responseFormatter->formatSuccess(
                $this->runnerService->buildStatusPayload(),
                'responses/admin/scheduled_jobs/runner_status'
            );
        } catch (RequestValidationException $e) {
            // Let the ApiExceptionListener format the 400 schema-validation error.
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @route /admin/scheduled-jobs/runner/enable
     * @method POST
     */
    public function enable(): JsonResponse
    {
        return $this->toggle(true);
    }

    /**
     * @route /admin/scheduled-jobs/runner/disable
     * @method POST
     */
    public function disable(): JsonResponse
    {
        return $this->toggle(false);
    }

    /**
     * @route /admin/scheduled-jobs/runner/run-now
     * @method POST
     */
    public function runNow(): JsonResponse
    {
        try {
            $result = $this->runnerService->runDueJobs(
                ScheduledJobRunnerRun::TRIGGER_MANUAL,
                null,
                true,
                false
            );

            return $this->responseFormatter->formatSuccess(
                [
                    'run' => $result->toArray(),
                    'status' => $this->runnerService->buildStatusPayload(),
                ],
                'responses/admin/scheduled_jobs/runner_run_now'
            );
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function toggle(bool $enabled): JsonResponse
    {
        try {
            $this->runnerService->setEnabled($enabled, $this->userContextService->getCurrentUser());

            return $this->responseFormatter->formatSuccess(
                $this->runnerService->buildStatusPayload(),
                'responses/admin/scheduled_jobs/runner_status'
            );
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
