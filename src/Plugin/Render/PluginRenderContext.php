<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Render;

/**
 * Who is reading the page and which version is served (contract C6).
 *
 * Built by the host for every resolve call. `userId` is 0 for a guest.
 * `platform` is the caller's mode (`web` or `mobile`) when the request
 * named one, otherwise null.
 */
final readonly class PluginRenderContext
{
    public function __construct(
        public int $userId,
        public int $pageId,
        public int $languageId,
        public bool $servesPublished,
        public bool $preview,
        public ?string $platform,
    ) {
    }
}
