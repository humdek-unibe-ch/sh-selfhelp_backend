<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

use App\Repository\PageRepository;
use App\Repository\PageVersionRepository;
use App\Service\CMS\DataRevision\ContentRef;

/**
 * The content reference (contract C19) of a write made through a form of a page:
 * the page version the visitor was served when they wrote.
 *
 *  - published version: `page:<page id>@v<version number>`;
 *  - the draft (an editor's preview, or a page that was never published): `page:<page id>@draft`.
 *
 * Only the number of the version is read, never its content, and only on a write:
 * a read costs nothing extra. A page version is immutable, so the number alone
 * says what the participant saw ({@see \App\Entity\PageVersion}).
 */
class PageContentRefService
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly PageVersionRepository $versions,
    ) {
    }

    /**
     * The reference of the page as one request was served it (the page the form endpoints already resolved).
     */
    public function forEffective(EffectivePage $effective): string
    {
        $version = $effective->isPublished() && $effective->versionId !== null
            ? $this->versions->find($effective->versionId)
            : null;

        return ContentRef::page($effective->pageId, $version?->getVersionNumber());
    }

    /**
     * The reference of a page for a caller that knows the page and which side of it it read: the
     * `source` of a list context ({@see EffectivePage::SOURCE_PUBLISHED} or {@see EffectivePage::SOURCE_DRAFT}).
     */
    public function forPage(int $pageId, string $source): string
    {
        if ($source !== EffectivePage::SOURCE_PUBLISHED) {
            return ContentRef::page($pageId, null);
        }

        return ContentRef::page($pageId, $this->pages->find($pageId)?->getPublishedVersion()?->getVersionNumber());
    }
}
