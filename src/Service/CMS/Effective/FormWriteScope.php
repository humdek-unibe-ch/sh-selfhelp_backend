<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Effective;

use App\Entity\Page;
use App\Entity\Section;
use App\Service\CMS\FormList\FormListConfig;

/**
 * What a form write has been authorized to do, once the page, the section, the
 * list it sits in and the effective configuration have been checked: the answer
 * of {@see \App\Service\CMS\FormValidationService::authorize()}.
 *
 * `form` is the effective definition of the form section (null when the section
 * is a delete action). `list` is the list the section sits in (null when it sits
 * in none); the list decides the operations, the owner scope and the table.
 */
final readonly class FormWriteScope
{
    /**
     * @param string $operation `create`, `update` or `delete`
     * @param int|null $dataTableId the table a delete acts on (null when the action names none); for forms the table is named by the section id
     */
    public function __construct(
        public string $operation,
        public Page $page,
        public Section $section,
        public EffectivePage $effective,
        public ?int $listSectionId,
        public ?FormListConfig $list,
        public ?EffectiveForm $form,
        public bool $ownEntriesOnly,
        public ?int $dataTableId,
        public bool $authenticated,
        public bool $preview,
    ) {
    }

    /** The name of the data table the form writes into. */
    public function tableName(): string
    {
        return (string) $this->section->getId();
    }
}
