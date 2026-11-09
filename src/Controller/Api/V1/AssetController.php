<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\CMS\Admin\AssetFolderAclService;
use App\Service\CMS\Admin\AssetStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * ACL-enforced asset delivery.
 *
 *   GET /cms-api/v1/assets/{folder}/{filename}
 *
 * This is the ONLY way asset bytes reach a client. Files live outside the
 * document root ({@see AssetStorageService}), so the web server cannot serve
 * them directly and every request is authorized here against the folder ACLs.
 *
 * The route carries no permission link in `api_routes` (like the public health
 * and plugin-manifest routes) because access is resource-side, not route-side:
 * an open-access folder must be readable by anonymous callers, while a closed
 * folder is restricted per the caller's groups. `assertCanRead()` makes that
 * decision — a route-level permission would wrongly reject anonymous readers of
 * public folders.
 *
 * A missing file and a forbidden folder are deliberately distinguishable only
 * to callers who may read the folder: {@see AssetStorageService::resolveForDelivery}
 * returns null for both traversal attempts and genuinely absent files, and the
 * ACL check runs FIRST so a denied caller never learns whether a file exists.
 *
 * This route performs NO content negotiation: the response is always the file
 * bytes with the file's own Content-Type, regardless of the request's `Accept`
 * header. Frontend BFF proxies send `Accept: application/json` on every proxied
 * call; that must keep returning the image, so do not add Accept-based
 * branching here.
 */
final class AssetController extends AbstractController
{
    public function __construct(
        private readonly AssetFolderAclService $folderAclService,
        private readonly AssetStorageService $storage,
    ) {
    }

    public function serve(string $folder, string $filename, Request $request): Response
    {
        // Authorize BEFORE touching the filesystem so a denied caller cannot
        // probe for the existence of files via response-code differences.
        $this->folderAclService->assertCanRead($folder);

        $absolutePath = $this->storage->resolveForDelivery($folder, $filename);
        if ($absolutePath === null) {
            return new Response('Asset not found', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);

        // Static delivery gave us conditional requests for free; keep them so
        // image-heavy pages do not re-download bytes on every view.
        $response->setAutoEtag();
        $response->setAutoLastModified();
        $response->isNotModified($request);

        // Private: an ACL-scoped asset must never be cached by a shared proxy
        // where another user could receive it. Open-access folders are still
        // revalidated cheaply via ETag/304.
        $response->setPrivate();
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
