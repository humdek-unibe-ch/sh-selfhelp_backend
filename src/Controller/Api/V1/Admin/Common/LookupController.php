<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin\Common;

use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\LookupService;
use App\Service\CMS\Admin\SectionFieldService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LookupController extends AbstractController
{
    public function __construct(
        private readonly LookupService $lookupService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly SectionFieldService $sectionFieldService
    ) {
    }

    /**
     * Get all system lookups (timezones, type codes, weekdays, audit
     * categories, …).
     *
     * Authenticated-only — the JWT firewall rejects anonymous callers —
     * but NOT admin-gated, because public frontend styles such as
     * `ProfileStyle` consume the timezone list. The class still lives
     * under `App\Controller\Api\V1\Admin\Common` for historical reasons;
     * the route itself was demoted from `/admin/lookups` (route name
     * `admin_lookups`) to `/lookups` (route name `system_lookups`) in
     * Doctrine migration `Version20260508160000`.
     *
     * @route /lookups
     * @method GET
     */
    public function getAllLookups(): JsonResponse
    {
        try {
            $all_lookups = $this->lookupService->getAllLookups();
            return $this->responseFormatter->formatSuccess(
                $all_lookups,
                'responses/admin/common/lookups',
                Response::HTTP_OK // Explicitly pass the status code
            );
        } catch (\Throwable $e) {
            // Attempt to get a valid HTTP status code from the exception, default to 500
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $statusCode
            );
        }
    }

    /**
     * Get page keywords
     *
     * @route /admin/page-keywords
     * @method GET
     */
    public function getPageKeywords(): JsonResponse
    {
        try {
            $page_keywords = $this->sectionFieldService->getPageKeywords();

            return $this->responseFormatter->formatSuccess(
                $page_keywords,
                'responses/admin/common/page-keywords',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            // Attempt to get a valid HTTP status code from the exception, default to 500
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $statusCode
            );
        }
    }
}