<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Controller\Api\V1\Admin;

use App\Service\Auth\UserContextService;
use App\Service\CMS\Admin\ResourceUsageService;
use App\Service\Core\ApiResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a plugin resource is used (contract C7): pages and actions that hold
 * the item, limited to what the caller may see.
 *
 * Route `admin_resources_usage_get`, permission `admin.access`. The service
 * answers 404 for an unknown type and 403 without the view grant on the item.
 */
final class AdminResourceUsageController extends AbstractController
{
    public function __construct(
        private readonly ResourceUsageService $usage,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly UserContextService $userContext,
    ) {
    }

    public function getUsage(string $type, int $id): JsonResponse
    {
        $caller = $this->userContext->getCurrentUser();
        if ($caller === null) {
            return $this->responseFormatter->formatError('Authentication required', Response::HTTP_UNAUTHORIZED);
        }

        return $this->responseFormatter->formatSuccess(
            $this->usage->usage($type, $id, (int) $caller->getId()),
            'responses/admin/resources/usage'
        );
    }
}
