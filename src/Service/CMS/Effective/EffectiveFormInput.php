<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

/**
 * One input of a form as the effective page defines it: what it is called, what
 * it stores and the rules a value must satisfy before the server stores it.
 *
 * Only rules the author configured are rules; an input that configures none
 * accepts any value of its type. The server applies them to every write of the
 * form (a submission, a quick action, a restore): the form in the browser is a
 * convenience, never the check.
 */
final readonly class EffectiveFormInput
{
    /**
     * @param string $key the stable storage key, `section_<input section id>`
     * @param string $name the name the client submits the value under
     * @param string $style the input's style (`text-input`, `select`, `file-input`, ...)
     * @param string $valueType the declared value type ({@see \App\Service\Core\LookupService} `DATA_COL_VALUE_TYPES_*`)
     * @param bool $writable false for a disabled or read-only input: the server refuses a value for it
     * @param bool $visible false when a condition of the input or of a section above it (inside the form) does not hold for the visitor
     * @param bool $multiple the input takes a list of values
     * @param list<string>|null $options the values a choice input offers; null: any value
     * @param bool $creatable a choice input that also takes values outside its options
     * @param string|null $format `email` or `url` for an `input` of that type
     * @param list<string>|null $accept the file types of a file input (`.pdf`, `image/*`); null: any allowed type
     * @param string|null $onValue the value a switch, checkbox or chip stores when it is on
     * @param string|null $offValue the value it stores when it is off
     */
    public function __construct(
        public int $sectionId,
        public string $key,
        public string $name,
        public string $style,
        public string $valueType,
        public bool $required,
        public bool $writable,
        public bool $visible,
        public bool $isFile,
        public bool $translatable,
        public bool $multiple = false,
        public ?float $min = null,
        public ?float $max = null,
        public ?int $maxLength = null,
        public ?array $options = null,
        public bool $creatable = false,
        public ?int $maxSelections = null,
        public ?string $format = null,
        public ?string $minDate = null,
        public ?string $maxDate = null,
        public ?int $maxFiles = null,
        public ?array $accept = null,
        public ?string $onValue = null,
        public ?string $offValue = null,
    ) {
    }
}
