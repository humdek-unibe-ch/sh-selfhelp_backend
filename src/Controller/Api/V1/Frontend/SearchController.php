<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Controller\Api\V1\Frontend;

use App\Service\CMS\NavigationSearchService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SearchController extends AbstractController
{
    public function __construct(
        private readonly NavigationSearchService $navigationSearchService,
        private readonly ApiResponseFormatter $responseFormatter,
    ) {
    }

    /**
     * GET /cms-api/v1/search
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $query = $request->query->getString('query');
            $languageId = $request->query->getInt('language_id') ?: null;
            $limit = $request->query->getInt('limit') ?: null;
            $mode = $this->resolveMode($request);
            $results = $this->navigationSearchService->search(
                $query,
                $mode,
                $languageId,
                $limit > 0 ? $limit : null
            );

            return $this->responseFormatter->formatSuccess(
                ['results' => $results],
                'responses/frontend/search',
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
     * GET /cms-api/v1/search/pages
     */
    public function searchPages(Request $request): JsonResponse
    {
        try {
            $query = $request->query->getString('query');
            $languageId = $request->query->getInt('language_id') ?: null;
            $limit = $request->query->getInt('limit') ?: null;
            $mode = $this->resolveMode($request);
            $results = $this->navigationSearchService->searchPageMetadataOnly(
                $query,
                $mode,
                $languageId,
                $limit > 0 ? $limit : null
            );

            return $this->responseFormatter->formatSuccess(
                ['results' => $results],
                'responses/frontend/search',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599)
                ? $e->getCode()
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            return $this->responseFormatter->formatError($e->getMessage(), $statusCode);
        }
    }

    private function resolveMode(Request $request): string
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
