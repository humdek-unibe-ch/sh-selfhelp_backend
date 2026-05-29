<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Controller\Api\V1\Admin;

use App\Controller\Trait\RequestValidatorTrait;
use App\Exception\RequestValidationException;
use App\Service\Auth\RegistrationCodeService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\JSON\JsonSchemaValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminRegistrationCodeController extends AbstractController
{
    use RequestValidatorTrait;

    public function __construct(
        private readonly RegistrationCodeService $registrationCodeService,
        private readonly ApiResponseFormatter $responseFormatter,
        private readonly JsonSchemaValidationService $jsonSchemaValidationService,
    ) {
    }

    /**
     * @route GET /admin/registration-codes
     */
    public function getAll(Request $request): JsonResponse
    {
        $page      = max(1, $request->query->getInt('page', 1));
        $pageSize  = max(1, min(100, $request->query->getInt('pageSize', 20)));

        $filters = [
            'search'        => $request->query->getString('search') ?: null,
            'id_groups'     => $request->query->getInt('id_groups') ?: null,
            'status'        => $request->query->getString('status') ?: null,
            'sort'          => $request->query->getString('sort') ?: null,
            'sortDirection' => $request->query->getString('sortDirection') ?: null,
        ];

        $result = $this->registrationCodeService->getAll($filters, $page, $pageSize);

        return $this->responseFormatter->formatSuccess($result, null, Response::HTTP_OK, true);
    }

    /**
     * @route POST /admin/registration-codes
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/registration_code_create', $this->jsonSchemaValidationService);

            $code    = is_string($data['code'])     ? $data['code']     : '';
            $groupId = is_int($data['id_groups'])   ? $data['id_groups'] : 0;

            $result = $this->registrationCodeService->create($code, $groupId);

            return $this->responseFormatter->formatSuccess($result, null, Response::HTTP_CREATED, true);
        } catch (RequestValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError('Failed to create registration code.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @route DELETE /admin/registration-codes/{code}
     */
    public function delete(Request $request): JsonResponse
    {
        try {
            $code = $request->attributes->get('code');
            if (!is_string($code) || $code === '') {
                return $this->responseFormatter->formatError('Invalid code parameter.', Response::HTTP_BAD_REQUEST);
            }

            $this->registrationCodeService->delete($code);

            return $this->responseFormatter->formatSuccess([], null, Response::HTTP_OK, true);
        } catch (\InvalidArgumentException $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError('Failed to delete registration code.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
