<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Controller\Api\V1\Frontend;

use App\Service\Auth\UserContextService;
use App\Service\CMS\NavigationMenuService;
use App\Service\CMS\UserNavigationStateService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class NavigationController extends AbstractController
{
    public function __construct(
        private readonly NavigationMenuService $navigationMenuService,
        private readonly UserNavigationStateService $userNavigationStateService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly UserContextService $userContextService,
    ) {
    }

    /**
     * GET /cms-api/v1/navigation
     */
    public function getNavigation(Request $request): JsonResponse
    {
        try {
            $mode = $this->resolvePageAccessMode($request);
            $languageId = $request->query->getInt('language_id') ?: null;
            $payload = $this->navigationMenuService->getPublicNavigationPayload($mode, $languageId);

            return $this->responseFormatter->formatSuccess(
                $payload,
                'responses/frontend/get_navigation',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599)
                ? $e->getCode()
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    /**
     * PUT /cms-api/v1/navigation/last-visited
     */
    public function recordLastVisited(Request $request): JsonResponse
    {
        try {
            $user = $this->userContextService->getCurrentUser();
            if ($user === null) {
                return $this->responseFormatter->formatError('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            /** @var array<string, mixed> $payload */
            $payload = json_decode($request->getContent(), true) ?? [];
            $platform = $request->headers->get('X-Client-Type')
                ?? $request->query->getString('platform')
                ?: LookupService::PAGE_ACCESS_TYPES_WEB;

            $result = $this->userNavigationStateService->recordLastVisited($user, $platform, $payload);

            return $this->responseFormatter->formatSuccess($result, null, Response::HTTP_OK);
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599)
                ? $e->getCode()
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    private function resolvePageAccessMode(Request $request): string
    {
        $header = $request->headers->get('X-Client-Type');
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $platform = $request->query->getString('platform');
        if ($platform !== '') {
            return $platform;
        }

        return LookupService::PAGE_ACCESS_TYPES_WEB;
    }
}
