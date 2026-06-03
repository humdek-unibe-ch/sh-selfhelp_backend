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
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        return $this->responseFormatter->formatSuccess($result, 'responses/admin/registration_codes_list', Response::HTTP_OK, true);
    }

    /**
     * @route GET /admin/registration-codes/export
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = [
            'search'        => $request->query->getString('search') ?: null,
            'id_groups'     => $request->query->getInt('id_groups') ?: null,
            'status'        => $request->query->getString('status') ?: null,
        ];

        $rows     = $this->registrationCodeService->export($filters);
        $filename = 'registration_codes_' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd_His') . '.csv';

        $response = new StreamedResponse(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // PHP 8.4: pass $escape explicitly (its default changes to '' in 9.0);
            // '' yields RFC 4180-correct CSV with no backslash escaping.
            fputcsv($out, ['code', 'group_name', 'status', 'created_at', 'consumed_at', 'user_email'], escape: '');

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['code'],
                    $row['group_name'],
                    $row['status'],
                    $row['created_at'],
                    $row['consumed_at'],
                    $row['user_email'],
                ], escape: '');
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    /**
     * @route POST /admin/registration-codes/generate
     */
    public function generate(Request $request): JsonResponse
    {
        try {
            $data = $this->validateRequest($request, 'requests/admin/registration_code_generate', $this->jsonSchemaValidationService);

            $count   = is_int($data['count'])    ? $data['count']    : 1;
            $groupId = is_int($data['id_groups']) ? $data['id_groups'] : 0;

            $result = $this->registrationCodeService->generate($count, $groupId);

            return $this->responseFormatter->formatSuccess($result, 'responses/admin/registration_codes_generate', Response::HTTP_CREATED, true);
        } catch (RequestValidationException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return $this->responseFormatter->formatError($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            return $this->responseFormatter->formatError('Failed to generate registration codes.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

}
