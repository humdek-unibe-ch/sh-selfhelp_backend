<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Controller\Api\V1\Frontend;

use App\Exception\ServiceException;
use App\Service\Auth\UserContextService;
use App\Service\CMS\FormFileUploadService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Access-checked delivery of files uploaded through a form:
 *
 *   GET /cms-api/v1/forms/files/{record_id}/{field}?index=0
 *
 * Form files live outside the document root. Allowed here: the user who
 * submitted the record, or a user with READ on the form's data table (CMS /
 * data browser). Page viewers open files through the list route
 * `GET /lists/{section}/records/{record}/files/{field}` (F165). The field's
 * `files_public` switch no longer means "anyone with the link".
 */
final class FormFileController extends AbstractController
{
    /**
     * Documents that are never shown inline, only downloaded. SVG stays inline
     * so it can be viewed: the sandbox CSP below blocks its scripts.
     */
    private const DOWNLOAD_ONLY_MIME_TYPES = [
        'text/html',
        'application/xhtml+xml',
        'text/xml',
        'application/xml',
    ];

    public function __construct(
        private readonly FormFileUploadService $formFileUploadService,
        private readonly UserContextService $userContextService,
        private readonly DataAccessSecurityService $dataAccessSecurityService,
    ) {
    }

    public function serve(int $record_id, string $field, Request $request): Response
    {
        $file = $this->formFileUploadService->findRecordFile($record_id, $field, $request->query->getInt('index'));
        $denied = $this->deny($file);
        if ($denied !== null || $file === null) {
            return $denied ?? new Response('File not found', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($file['absolutePath']);
        $mimeType = $response->getFile()->getMimeType();
        $downloadOnly = in_array($mimeType, self::DOWNLOAD_ONLY_MIME_TYPES, true);
        $response->setContentDisposition(
            $downloadOnly ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $this->formFileUploadService->originalName($file['absolutePath'])
        );

        return $this->harden($response);
    }

    /**
     * GET /cms-api/v1/forms/files/{record_id}/{field}/zip — every file of a
     * multi-file field as one zip, under the same access rule as {@see serve()}.
     */
    public function zip(int $record_id, string $field): Response
    {
        $files = $this->formFileUploadService->findRecordFiles($record_id, $field);
        $denied = $this->deny($files);
        if ($denied !== null || $files === null) {
            return $denied ?? new Response('File not found', Response::HTTP_NOT_FOUND);
        }

        $entries = array_map(
            fn (string $path): array => ['entry' => $this->formFileUploadService->originalName($path), 'absolutePath' => $path],
            $files['absolutePaths']
        );
        try {
            $zipPath = $this->formFileUploadService->buildZip($entries);
        } catch (ServiceException $e) {
            return new Response($e->getMessage(), $e->getCode());
        }

        return self::zipResponse($zipPath, sprintf('record_%d_%s.zip', $record_id, $field));
    }

    /** A sent zip archive, deleted from disk afterwards. */
    public static function zipResponse(string $zipPath, string $downloadName): BinaryFileResponse
    {
        $response = new BinaryFileResponse($zipPath, Response::HTTP_OK, ['Content-Type' => 'application/zip']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);
        $response->deleteFileAfterSend();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Access decision shared by single files and zips. Owner and table READ
     * only; page viewers use the list current-file route (F165).
     *
     * @param array{dataTableId: int, ownerId: int|null, public: bool}|null $file
     */
    private function deny(?array $file): ?Response
    {
        $user = $this->userContextService->getCurrentUser();
        if ($user === null) {
            return new Response('Authentication required', Response::HTTP_UNAUTHORIZED);
        }
        if ($file === null) {
            return null;
        }
        if (!$this->canRead((int) $user->getId(), $file['ownerId'], $file['dataTableId'])) {
            // Same answer as a missing file: do not reveal that the record exists.
            return new Response('File not found', Response::HTTP_NOT_FOUND);
        }

        return null;
    }

    private function harden(BinaryFileResponse $response): BinaryFileResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");

        return $response;
    }

    /** The record's submitter, or a user with READ on the form's data table. */
    private function canRead(int $userId, ?int $ownerId, int $dataTableId): bool
    {
        return $ownerId === $userId
            || $this->dataAccessSecurityService->hasPermission(
                $userId,
                LookupService::RESOURCE_TYPES_DATA_TABLE,
                $dataTableId,
                DataAccessSecurityService::PERMISSION_READ
            );
    }
}
