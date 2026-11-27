<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Controller\Api\V1\Frontend;

use App\Service\CMS\LanguageService;
use App\Service\Core\ApiResponseFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

class LanguageController extends AbstractController
{
    private LanguageService $languageService;
    private ApiResponseFormatter $apiResponseFormatter;

    public function __construct(LanguageService $languageService, ApiResponseFormatter $apiResponseFormatter) {
        $this->languageService = $languageService;
        $this->apiResponseFormatter = $apiResponseFormatter;
    }

    /**
     * Real languages only (excludes the language-independent row).
     * Always returns the CMS default language first when configured, followed by other languages.
     * 
     * @return JsonResponse
     */
    public function getAllLanguages(): JsonResponse
    {
        $languages = $this->languageService->getRealLanguages();
        return $this->apiResponseFormatter->formatSuccess($languages, 'responses/languages/get_languages');
    }
}
