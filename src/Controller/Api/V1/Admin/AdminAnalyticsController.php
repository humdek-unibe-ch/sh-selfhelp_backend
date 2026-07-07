<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Controller\Api\V1\Admin;

use App\Service\CMS\Admin\AdminAnalyticsService;
use App\Service\Core\ApiResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin Analytics Controller
 *
 * Read-only dashboard aggregates: anonymous page-view analytics
 * (series / totals / top pages / referrers) and the today's-operations
 * snapshot. Routes are DB-defined (`admin_analytics_summary`,
 * `admin_analytics_today`) and guarded by the `admin.analytics.read`
 * permission.
 */
class AdminAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly AdminAnalyticsService $adminAnalyticsService,
        private readonly ApiResponseFormatter $responseFormatter,
    ) {
    }

    /**
     * GET /admin/analytics/summary?from=Y-m-d&to=Y-m-d&granularity=day|month&platform=all|web|mobile
     */
    public function getSummary(Request $request): JsonResponse
    {
        try {
            $summary = $this->adminAnalyticsService->getSummary(
                $request->query->get('from'),
                $request->query->get('to'),
                (string) $request->query->get('granularity', 'day'),
                (string) $request->query->get('platform', 'all'),
            );

            return $this->responseFormatter->formatSuccess($summary, 'responses/admin/analytics/analytics_summary');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                'Failed to retrieve analytics summary: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * GET /admin/analytics/today
     */
    public function getToday(): JsonResponse
    {
        try {
            $today = $this->adminAnalyticsService->getTodayOperations();

            return $this->responseFormatter->formatSuccess($today, 'responses/admin/analytics/analytics_today');
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError(
                'Failed to retrieve today\'s operations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
