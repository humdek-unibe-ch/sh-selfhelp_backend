<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Service\CMS\Admin\AdminCmsPreferenceService;
use App\Service\Core\ApiResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin CMS Preference Controller
 * 
 * Handles CMS preferences management for admin interface
 */
class AdminCmsPreferenceController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly AdminCmsPreferenceService $adminCmsPreferenceService,
        private readonly ApiResponseFormatter $responseFormatter
    ) {
    }

    /**
     * Get CMS preferences
     * 
     * @route /admin/cms-preferences
     * @method GET
     */
    public function getCmsPreferences(): JsonResponse
    {
        try {
            $preferences = $this->adminCmsPreferenceService->getCmsPreferences();
            return $this->responseFormatter->formatSuccess($preferences);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatThrowable($e);
        }
    }

} 