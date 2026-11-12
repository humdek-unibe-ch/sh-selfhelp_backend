<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use App\Service\CMS\Admin\AssetAccessService;
use App\Service\CMS\Admin\AssetFileTypes;
use App\Service\CMS\Admin\AssetStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * ACL-enforced asset delivery.
 *
 *   GET /cms-api/v1/assets/{assetUuid}
 *
 * This is the ONLY way asset bytes reach a client. Files live outside the
 * document root ({@see AssetStorageService}), so the web server cannot serve
 * them directly and every request is authorized here against the asset's
 * groups ({@see AssetAccessService}).
 *
 * The route carries no permission link in `api_routes` (like the public health
 * and plugin-manifest routes) because access is resource-side, not route-side:
 * an asset with no groups must be readable by anonymous callers.
 *
 * This route performs NO content negotiation: the response is always the file
 * bytes with the file's own Content-Type, regardless of the request's `Accept`
 * header. Frontend BFF proxies send `Accept: application/json` on every proxied
 * call; that must keep returning the image, so do not add Accept-based
 * branching here.
 */
final class AssetController extends AbstractController
{
    /**
     * MIME types that browsers can execute script from when navigated to
     * directly. Served with a sandboxing CSP so an uploaded SVG/HTML cannot run
     * script on the app's origin.
     */
    private const SCRIPTABLE_MIME_TYPES = [
        'image/svg+xml',
        'text/html',
        'application/xhtml+xml',
        'text/xml',
        'application/xml',
    ];

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetAccessService $accessService,
        private readonly AssetStorageService $storage,
    ) {
    }

    public function serveByUuid(string $assetUuid, Request $request): Response
    {
        $asset = $this->assetRepository->findOneByUuid($assetUuid);
        if ($asset === null) {
            return new Response('Asset not found', Response::HTTP_NOT_FOUND);
        }

        // Authorize BEFORE touching the filesystem.
        $this->accessService->assertCanView($asset);

        $absolutePath = $this->storage->resolveForDelivery($asset->getUuid());
        if ($absolutePath === null) {
            return new Response('Asset not found', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        // Stored under a uuid without extension, so the type comes from the DB
        // (recorded at upload) rather than from the file name.
        $mimeType = $asset->getMimeType() ?? $response->getFile()->getMimeType() ?? 'application/octet-stream';
        $response->headers->set('Content-Type', $mimeType);
        // Shown in the browser only for kinds it can show (images, audio,
        // video, PDF, plain text); everything else is saved under the
        // asset's name: `<name>-<uuid>.<extension>`.
        $fileName = $this->storage->downloadName($asset);
        $response->setContentDisposition(
            AssetFileTypes::isInline($mimeType) ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $fileName,
            (string) preg_replace('/[^\x20-\x7e]/', '_', $fileName)
        );

        // Conditional requests keep image-heavy pages from re-downloading bytes.
        $response->setAutoEtag();
        $response->setAutoLastModified();
        $response->isNotModified($request);

        // Private: an ACL-scoped asset must never be cached by a shared proxy
        // where another user could receive it.
        $response->setPrivate();
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Markup files (SVG, HTML, XML) can carry script that would run on the
        // app's own origin when opened directly; sandbox them. Not applied to
        // every file because it would block the browser's PDF viewer.
        if (in_array($mimeType, self::SCRIPTABLE_MIME_TYPES, true)) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
        }

        return $response;
    }
}
