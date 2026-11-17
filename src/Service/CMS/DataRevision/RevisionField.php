<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\DataRevision;

/**
 * One field value of a record in one language, as the live record holds it or as
 * a revision reconstructs it. The key is the stable field key (`section_<id>` for
 * a core form), never the label, so the same field is recognised across renames.
 */
final readonly class RevisionField
{
    public function __construct(
        public string $key,
        public ?string $label,
        public int $languageId,
        public ?int $columnId,
        public string $value,
    ) {
    }

    /** Identity of the field inside a record. */
    public function identity(): string
    {
        return self::identityOf($this->key, $this->languageId);
    }

    public static function identityOf(string $key, int $languageId): string
    {
        return $key . '|' . $languageId;
    }
}
