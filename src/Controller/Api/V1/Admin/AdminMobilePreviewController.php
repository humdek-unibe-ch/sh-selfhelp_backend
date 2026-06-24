<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Service\Auth\UserContextService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\MobilePreview\MobilePreviewSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-only minting endpoint for the CMS mobile preview.
 *
 *   POST /cms-api/v1/admin/mobile-preview/session
 *
 * Returns a one-time code the page-editor preview panel puts in the preview
 * iframe URL. The code is bound to the calling admin (derived server-side from
 * the JWT — never sent by the browser) and an optional preview scope. The
 * route is gated by the `admin.mobile_preview.create` permission via
 * {@see \App\EventListener\ApiSecurityListener}.
 */
final class AdminMobilePreviewController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly MobilePreviewSessionService $sessionService,
        private readonly UserContextService $userContextService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * @route /admin/mobile-preview/session
     * @method POST
     */
    public function createSession(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest(
                $request,
                'requests/admin/mobile_preview_session',
                $this->jsonSchemaValidationService
            );

            $currentUser = $this->userContextService->getCurrentUser();
            if ($currentUser === null) {
                return $this->responseFormatter->formatError(
                    'User not authenticated',
                    Response::HTTP_UNAUTHORIZED
                );
            }

            $scope = [
                'keyword'     => $this->asStringOrNullField($data, 'keyword'),
                'page_id'     => $this->asIntOrNullField($data, 'page_id'),
                'language_id' => $this->asIntOrNullField($data, 'language_id'),
                'draft'       => $this->asBoolField($data, 'draft', false),
            ];

            $result = $this->sessionService->createCode((int) $currentUser->getId(), $scope);

            return $this->responseFormatter->formatSuccess(
                $result,
                'responses/admin/mobile_preview_session'
            );
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }
}
