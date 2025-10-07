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

    /**
     * Create or update a translation
     * @route /admin/actions/{actionId}/translations
     * @method POST
     */
    public function createTranslation(Request $request, int $actionId): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/create_action_translation', $this->jsonSchemaValidationService);

            $result = $this->adminActionTranslationService->createTranslation($actionId, (array) $data);

            return $this->responseFormatter->formatSuccess(
                $result,
                'responses/admin/actions/translation_envelope',
                Response::HTTP_CREATED
            );
        } catch (\Throwable $e) {
            $message = $e instanceof ServiceException || $_ENV['APP_DEBUG'] ? $e->getMessage() : 'Internal Server Error';
            $status = $e instanceof ServiceException ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($message, $status);
        }
    }

    /**
     * Update an existing translation
     * @route /admin/actions/{actionId}/translations/{translationId}
     * @method PUT
     */
    public function updateTranslation(Request $request, int $actionId, int $translationId): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/update_action_translation', $this->jsonSchemaValidationService);

            $result = $this->adminActionTranslationService->updateTranslation($actionId, $translationId, (array) $data);

            return $this->responseFormatter->formatSuccess(
                $result,
                'responses/admin/actions/translation_envelope'
            );
        } catch (\Throwable $e) {
            $message = $e instanceof ServiceException || $_ENV['APP_DEBUG'] ? $e->getMessage() : 'Internal Server Error';
            $status = $e instanceof ServiceException ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($message, $status);
        }
    }

    /**
     * Delete a translation
     * @route /admin/actions/{actionId}/translations/{translationId}
     * @method DELETE
     */
    public function deleteTranslation(int $actionId, int $translationId): JsonResponse
    {
        try {
            $this->adminActionTranslationService->deleteTranslation($actionId, $translationId);

            return $this->responseFormatter->formatSuccess(
                ['deleted' => true],
                'responses/admin/actions/translation_deleted_envelope'
            );
        } catch (\Throwable $e) {
            $message = $e instanceof ServiceException || $_ENV['APP_DEBUG'] ? $e->getMessage() : 'Internal Server Error';
            $status = $e instanceof ServiceException ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($message, $status);
        }
    }

    /**
     * Bulk create/update translations
     * @route /admin/actions/{actionId}/translations/bulk
     * @method POST
     */
    public function bulkCreateTranslations(Request $request, int $actionId): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/bulk_create_action_translations', $this->jsonSchemaValidationService);

            $result = $this->adminActionTranslationService->bulkCreateTranslations($actionId, (array) $data['translations']);

            return $this->responseFormatter->formatSuccess(
                $result,
                'responses/admin/actions/bulk_translations_envelope',
                Response::HTTP_CREATED
            );
        } catch (\Throwable $e) {
            $message = $e instanceof ServiceException || $_ENV['APP_DEBUG'] ? $e->getMessage() : 'Internal Server Error';
            $status = $e instanceof ServiceException ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($message, $status);
        }
    }

    /**
     * Get missing translations for an action and language
     * @route /admin/actions/{actionId}/translations/missing
     * @method GET
     */
    public function getMissingTranslations(Request $request, int $actionId): JsonResponse
    {
        try {
            $languageId = (int) $request->query->get('language_id');

            $result = $this->adminActionTranslationService->getMissingTranslations($actionId, $languageId);

            return $this->responseFormatter->formatSuccess(
                ['missing_keys' => $result],
                'responses/admin/actions/missing_translations_envelope'
            );
        } catch (\Throwable $e) {
            $message = $e instanceof ServiceException || $_ENV['APP_DEBUG'] ? $e->getMessage() : 'Internal Server Error';
            $status = $e instanceof ServiceException ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            return $this->responseFormatter->formatError($message, $status);
        }
    }
}
