<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\CmsAppService;
use App\Service\CMS\Admin\CmsAppWizardService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-class CMS apps admin API (ID-stable mutations; slug resolve for UI).
 */
class AdminCmsAppController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly CmsAppService $cmsAppService,
        private readonly CmsAppWizardService $cmsAppWizardService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    public function listApps(): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->cmsAppService->listApps(),
                'responses/admin/cms_apps/list',
                Response::HTTP_OK
            );
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function createApp(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/cms_apps/create', $this->jsonSchemaValidationService);
            $payload = $this->toAssocArray($data);
            $result = $this->cmsAppService->createApp(
                $this->asStringField($payload, 'name'),
                $this->asStringField($payload, 'slug'),
                $this->asStringOrNullField($payload, 'description'),
            );

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/cms_apps/create', Response::HTTP_CREATED);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getApp(int $id): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->cmsAppService->getApp($id),
                'responses/admin/cms_apps/detail',
                Response::HTTP_OK
            );
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getBySlug(string $slug): JsonResponse
    {
        try {
            return $this->responseFormatter->formatSuccess(
                $this->cmsAppService->getAppBySlug($slug),
                'responses/admin/cms_apps/detail',
                Response::HTTP_OK
            );
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function updateApp(int $id, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/cms_apps/update', $this->jsonSchemaValidationService);
            $result = $this->cmsAppService->updateApp($id, $this->toAssocArray($data));

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/cms_apps/update', Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function deleteApp(int $id): JsonResponse
    {
        try {
            $this->cmsAppService->deleteApp($id);

            return $this->responseFormatter->formatSuccess(['deleted' => true], 'responses/admin/cms_apps/delete', Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function assignPage(int $id, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/cms_apps/assign_page', $this->jsonSchemaValidationService);
            $payload = $this->toAssocArray($data);
            $result = $this->cmsAppService->assignPage(
                $id,
                $this->asIntField($payload, 'page_id'),
                $this->asStringField($payload, 'role'),
            );

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/cms_apps/assign_page', Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function changePageRole(int $id, int $page_id, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/cms_apps/change_page_role', $this->jsonSchemaValidationService);
            $payload = $this->toAssocArray($data);
            $result = $this->cmsAppService->changePageRole($id, $page_id, $this->asStringField($payload, 'role'));

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/cms_apps/change_page_role', Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function unassignPage(int $id, int $page_id): JsonResponse
    {
        try {
            $result = $this->cmsAppService->unassignPage($id, $page_id);

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/cms_apps/unassign_page', Response::HTTP_OK);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function scaffold(int $id, Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/cms_apps/scaffold', $this->jsonSchemaValidationService);
            $result = $this->cmsAppWizardService->scaffoldCmsApp($id, $this->toAssocArray($data));

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/cms_apps/scaffold', Response::HTTP_CREATED);
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        } catch (\Throwable $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
