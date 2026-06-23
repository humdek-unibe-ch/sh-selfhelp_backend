<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Controller\Api\V1\Frontend;

use App\Service\Core\ApiResponseFormatter;
use App\Service\CMS\Frontend\PageService;
use App\Service\Core\LookupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API V1 Content Controller
 *
 * Handles content-related endpoints for API v1.
 *
 * Platform / mode resolution
 * --------------------------
 * The frontend is web (Mantine) and the mobile app is Expo + HeroUI Native.
 * We support three values for the page-access "mode" stored on the
 * `pages.id_page_access_types` lookup:
 *
 *   - {@see LookupService::PAGE_ACCESS_TYPES_WEB} (`web`)
 *   - {@see LookupService::PAGE_ACCESS_TYPES_MOBILE} (`mobile`)
 *   - {@see LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB} (`mobile_and_web`)
 *
 * The controller derives the requested mode from (in priority order):
 *   1. `X-Client-Type` request header.
 *   2. `?platform` query string.
 *   3. Default: `web` (back-compat with the existing web app).
 *
 * The same value is implicitly forwarded to {@see ConditionService} via
 * {@see VariableResolverService::getPlatform()} (it reads the same
 * header/query) so JSON-Logic conditions like
 * `{"==": [{"var":"platform"}, "mobile"]}` evaluate consistently.
 */
class PageController extends AbstractController
{
    /**
     * Constructor
     */
    public function __construct(
        private readonly PageService $pageService,
        private readonly ApiResponseFormatter $responseFormatter
    ) {
    }

    /**
     * Return the ACL-filtered page tree for the current caller.
     *
     * Routes are DB-defined (loaded by {@see \App\Routing\ApiRouteLoader}),
     * not PHP attributes — see the `pages_get_all` / `pages_get_all_with_language`
     * rows seeded in migration `Version20260501000300`:
     *   - GET /cms-api/v1/pages
     *   - GET /cms-api/v1/pages/language/{language_id}
     */
    public function getPages(Request $request, ?int $language_id = null): JsonResponse
    {
        try {
            $mode = $this->resolvePageAccessMode($request);
            $pages = $this->pageService->getAllAccessiblePagesForUser($mode, false, $language_id);
            return $this->responseFormatter->formatSuccess(
                $pages,
                'responses/common/_acl_page_definition',
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $statusCode
            );
        }
    }

    /**
     * Get a page by its unique keyword. This is the single public page-content
     * endpoint, used by the web/mobile BFF to resolve a slug directly to full
     * page content without the nav->id waterfall.
     *
     * Route is DB-defined (loaded by {@see \App\Routing\ApiRouteLoader}), not a
     * PHP attribute — see the `pages_get_by_keyword` row seeded in migration
     * `Version20260501000300` (GET /cms-api/v1/pages/by-keyword/{keyword},
     * keyword requirement `[a-zA-Z0-9_\-]+`).
     *
     * Query params: language_id, preview. `preview=true` requires authentication.
     */
    public function getPageByKeyword(Request $request, string $keyword): JsonResponse
    {
        try {
            $language_id = $request->query->get('language_id') ? (int) $request->query->get('language_id') : null;
            $preview = $request->query->getBoolean('preview', false);
            $mode = $this->resolvePageAccessMode($request);

            $page = $this->pageService->getPageByKeyword($keyword, $language_id, $preview, $mode);

            $response = $this->responseFormatter->formatSuccess(
                $page,
                'responses/frontend/get_page',
                Response::HTTP_OK
            );

            if ($preview) {
                $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Expires', '0');
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }

            return $response;
        } catch (\Throwable $e) {
            $statusCode = (is_int($e->getCode()) && $e->getCode() >= 100 && $e->getCode() <= 599) ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError(
                $e->getMessage(),
                $statusCode
            );
        }
    }

    /**
     * Determine the page-access mode to filter by based on the incoming
     * request. Mirrors {@see VariableResolverService::getPlatform()} so
     * the page list and the condition variable agree.
     *
     * Priority:
     *   1. `X-Client-Type` header (`mobile` | `web` | `mobile_and_web`).
     *   2. `?platform` query parameter.
     *   3. `?mobile` legacy flag (truthy => mobile).
     *   4. Default: web.
     */
    private function resolvePageAccessMode(Request $request): string
    {
        $header = $request->headers->get('X-Client-Type');
        if (is_string($header) && $header !== '') {
            $normalised = strtolower($header);
            if ($normalised === LookupService::PAGE_ACCESS_TYPES_MOBILE) {
                return LookupService::PAGE_ACCESS_TYPES_MOBILE;
            }
            if ($normalised === LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB) {
                return LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB;
            }
            if ($normalised === LookupService::PAGE_ACCESS_TYPES_WEB) {
                return LookupService::PAGE_ACCESS_TYPES_WEB;
            }
        }

        $queryPlatform = $request->query->get('platform');
        if (is_string($queryPlatform) && $queryPlatform !== '') {
            $normalised = strtolower($queryPlatform);
            if ($normalised === LookupService::PAGE_ACCESS_TYPES_MOBILE) {
                return LookupService::PAGE_ACCESS_TYPES_MOBILE;
            }
            if ($normalised === LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB) {
                return LookupService::PAGE_ACCESS_TYPES_MOBILE_AND_WEB;
            }
            if ($normalised === LookupService::PAGE_ACCESS_TYPES_WEB) {
                return LookupService::PAGE_ACCESS_TYPES_WEB;
            }
        }

        if ($request->query->get('mobile') || $request->request->get('mobile')) {
            return LookupService::PAGE_ACCESS_TYPES_MOBILE;
        }

        return LookupService::PAGE_ACCESS_TYPES_WEB;
    }
}
