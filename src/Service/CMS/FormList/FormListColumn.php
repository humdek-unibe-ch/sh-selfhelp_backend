<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

/**
 * One column of a list, as the author configured it.
 */
final readonly class FormListColumn
{
    public const FORMAT_TEXT = 'text';
    public const FORMAT_NUMBER = 'number';
    public const FORMAT_DATE = 'date';
    public const FORMAT_DATETIME = 'datetime';
    public const FORMAT_BOOLEAN = 'boolean';
    public const FORMAT_BADGE = 'badge';
    public const FORMAT_FILE = 'file';
    /** Thumbnail of the first image of a file-input field. */
    public const FORMAT_IMAGE = 'image';

    public const FORMATS = [
        self::FORMAT_TEXT,
        self::FORMAT_NUMBER,
        self::FORMAT_DATE,
        self::FORMAT_DATETIME,
        self::FORMAT_BOOLEAN,
        self::FORMAT_BADGE,
        self::FORMAT_FILE,
        self::FORMAT_IMAGE,
    ];

    /**
     * @param string $field a column `field_key` of the table or a row field (`entry_date`, `user_name`, ...)
     * @param string $label the header text in the visitor's language; empty means "use the column's own label"
     * @param bool $sortable the visitor may sort by this column
     * @param bool $searchable the visitor's text search looks into this column
     * @param string $format how clients draw the value, one of {@see self::FORMATS}
     */
    public function __construct(
        public string $field,
        public string $label,
        public bool $sortable,
        public bool $searchable,
        public string $format,
    ) {
    }
}
