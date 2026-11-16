<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

/**
 * One filter control the visitor sees above a list. A control narrows the
 * list inside the author's fixed boundary; it never widens it.
 */
final readonly class FormListControl
{
    public const TYPE_TEXT = 'text';
    public const TYPE_SELECT = 'select';
    public const TYPE_MULTI_SELECT = 'multi_select';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_DATE_RANGE = 'date_range';
    public const TYPE_NUMBER_RANGE = 'number_range';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_SELECT,
        self::TYPE_MULTI_SELECT,
        self::TYPE_BOOLEAN,
        self::TYPE_DATE_RANGE,
        self::TYPE_NUMBER_RANGE,
    ];

    /** How a text control compares. */
    public const MATCHES = ['contains', 'eq', 'starts_with'];

    /**
     * @param string $id the key in the URL (`<list>.f.<id>`)
     * @param string $type one of {@see self::TYPES}
     * @param string $field the column the control filters
     * @param string $label the control's label in the visitor's language; empty means the column's own label
     * @param list<array{value: string, label: string}>|null $options fixed choices of a select control
     * @param bool $optionsFromData the choices are the values that exist in the records the visitor may see
     * @param string $match comparison of a text control, one of {@see self::MATCHES}
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $field,
        public string $label,
        public ?array $options,
        public bool $optionsFromData,
        public string $match,
    ) {
    }

    public function usesChoices(): bool
    {
        return $this->type === self::TYPE_SELECT || $this->type === self::TYPE_MULTI_SELECT;
    }
}
