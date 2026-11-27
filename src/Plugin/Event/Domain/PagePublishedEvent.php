<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event\Domain;

/**
 * A page version was published (domain event `page.published`, contract C24).
 * Delivered once per change, after the commit; read-only, see
 * {@see RecordSavedEvent}.
 */
final class PagePublishedEvent
{
    /**
     * @param int $pageId the page
     * @param int $versionNumber the number of the version that is published now
     */
    public function __construct(
        public readonly int $pageId,
        public readonly int $versionNumber,
    ) {
    }
}
