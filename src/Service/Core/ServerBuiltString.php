<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\Core;

/**
 * A string the server already built for HTML (a path for an attribute, or
 * ready `<a>` markup). Mustache must not escape it again.
 *
 * Only the code that builds `__url` / `__links` wraps values in this type.
 * User content stays a plain string and is escaped.
 */
final class ServerBuiltString implements \Stringable, \JsonSerializable
{
    public function __construct(
        private readonly string $value,
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * If a marker ever reaches JSON (page cache, API), keep the string — never
     * an empty object. Prefer marking only at render time.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
