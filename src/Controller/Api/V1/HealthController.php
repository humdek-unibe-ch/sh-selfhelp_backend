<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Core\ApiResponseFormatter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public deployment readiness probe.
 *
 *   GET /cms-api/v1/health
 *
 * Purpose: a cheap, unauthenticated signal that a freshly deployed
 * instance has booted and can talk to its primary datastore. It is the
 * first thing the post-deploy smoke tier checks (plan §18.3) and is
 * suitable for a load-balancer / orchestrator readiness check.
 *
 * The route is DB-backed like every other `/cms-api` route (registered in
 * `api_routes` with no permission link, exactly like the public
 * `plugins_manifest` route), so reaching the controller already proves the
 * database route table is readable. The explicit `SELECT 1` then confirms
 * the application can run a real query — i.e. the connection is healthy for
 * actual work, not just for the read the route loader performed.
 *
 * The payload is intentionally minimal and leaks nothing: no version
 * numbers, no host names, no schema details, no secrets. Anything richer
 * (plugin doctor, cache health) lives behind the authenticated admin
 * endpoints.
 */
final class HealthController extends AbstractController
{
    public function __construct(
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @route /health
     * @method GET
     */
    public function health(): JsonResponse
    {
        try {
            // Cheapest possible round-trip that proves the connection can
            // execute a statement. Cast keeps static analysis happy.
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            // Readiness, not liveness: a booted app whose database is
            // unreachable is NOT ready to serve traffic. 503 is the
            // conventional "try again shortly" signal for orchestrators.
            return $this->responseFormatter->formatError(
                'Database connection is not available.',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['status' => 'degraded', 'database' => 'down'],
            );
        }

        return $this->responseFormatter->formatSuccess(
            ['status' => 'ok', 'database' => 'ok'],
            'responses/frontend/health',
        );
    }
}
