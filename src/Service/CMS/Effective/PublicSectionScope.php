<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

use App\Entity\Page;

/**
 * A section a public request may use, from
 * {@see PublicSectionAccessService::forSection()}: the page, the effective
 * configuration it acts on, and the section's stored fields there.
 */
final class PublicSectionScope
{
    /**
     * @param array<string, array<int, string>> $fields field name => language id => stored content
     */
    public function __construct(
        public readonly Page $page,
        public readonly EffectivePage $effective,
        public readonly int $sectionId,
        public readonly array $fields,
    ) {
    }

    /**
     * The stored content of one field ('' when the section has none). Property
     * fields are stored once, under language id 1.
     */
    public function field(string $name, int $languageId = 1): string
    {
        return $this->effective->fieldContent($this->sectionId, $name, $languageId);
    }
}
