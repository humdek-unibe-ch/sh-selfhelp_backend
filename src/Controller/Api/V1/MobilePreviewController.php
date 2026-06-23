<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Controller\Trait\RequestValidatorTrait;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use App\Service\MobilePreview\MobilePreviewSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public exchange endpoint for the CMS mobile preview.
 *
 *   POST /cms-api/v1/mobile-preview/session/exchange
 *
 * PUBLIC route (no JWT, like the health + plugins-manifest routes): the
 * one-time code IS the credential. The mobile-preview web image POSTs the code
 * it received in its iframe URL and receives a short-lived scoped JWT
 * (`purpose: 'mobile_preview'`) in return. Replays/guesses fail with a generic
 * 401 that never reveals whether a code existed.
 */
final class MobilePreviewController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly MobilePreviewSessionService $sessionService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * @route /mobile-preview/session/exchange
     * @method POST
     */
    public function exchange(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest(
                $request,
                'requests/frontend/mobile_preview_exchange',
                $this->jsonSchemaValidationService
            );

            $code = $this->asStringField($data, 'code');
            $result = $this->sessionService->exchange($code);

            return $this->responseFormatter->formatSuccess(
                $result,
                'responses/frontend/mobile_preview_exchange'
            );
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }
}
