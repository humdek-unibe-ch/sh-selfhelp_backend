<?php

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\AdminActionTranslationService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminActionTranslationController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly AdminActionTranslationService $adminActionTranslationService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService
    ) {
    }

    /**
     * Get all translations for an action
     * @route /admin/actions/{actionId}/translations
     * @method GET
     */
    public function getTranslations(Request $request, int $actionId): JsonResponse
    {
        try {
            $languageId = $request->query->get('language_id') ? (int) $request->query->get('language_id') : null;

            $result = $this->adminActionTranslationService->getTranslations($actionId, $languageId);

            return $this->responseFormatter->formatSuccess(
                $result,
                'responses/admin/actions/translations_list_envelope'
            );
        } catch (\Throwable $e) {
            $message = $e instanceof ServiceException || $_ENV['APP_DEBUG'] ? $e->getMessage() : 'Internal Server Error';
            $status = $e instanceof ServiceException ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($message, $status);
        }
    }
}