<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Service\CMS\DataQuery\DataQuery;
use App\Service\CMS\Effective\EffectiveForm;
use App\Service\CMS\Effective\EffectivePage;

/**
 * Everything one list request needs, after the page, the section and the
 * visitor have been checked: the configuration, the table, who asks and in
 * which language.
 */
final readonly class FormListContext
{
    /**
     * @param int $pageId the page the list is on
     * @param string $pageKeyword the page's keyword
     * @param FormListConfig $config the list's validated configuration
     * @param int|null $tableId the table the list reads; null while the table does not exist yet
     * @param int|null $userId the signed-in user; null for a guest
     * @param int $languageId the visitor's language
     * @param string $locale the visitor's locale (`en-GB`)
     * @param string $timezone the visitor's time zone code
     * @param int|null $formSectionId the inline form of an editable list
     * @param array<string, string> $optionalColumns the columns the inline form can write, by key with the value type:
     *                                              until a record holds a value for one, the table has no such column
     * @param list<array<string, mixed>> $template the sections placed inside a data view with the layout `custom`: the
     *                                             layout of one record, as the page tree holds them (translated, not yet
     *                                             rendered); empty for every other list
     * @param EffectiveForm|null $form the inline form of an editable list as the effective page defines it: the rules every
     *                                 write of the list (a quick action, a restore) is checked against
     * @param string $source `published` or `draft`: the version of the page this context was read from
     * @param array<string, string> $routeParams the route parameters of the page the list is shown on (`{{route.*}}`),
     *                                           validated against the page's routes; empty on a page without a route
     * @param array<string, string> $routeRequirements what the route that matched requires of each parameter
     * @param mixed $templateDataConfig the stored `data_config` of a data view with the layout `custom`: the supporting
     *                                  values its row template may read; null for every other list
     */
    public function __construct(
        public int $pageId,
        public string $pageKeyword,
        public FormListConfig $config,
        public ?int $tableId,
        public ?int $userId,
        public int $languageId,
        public string $locale,
        public string $timezone,
        public ?int $formSectionId,
        public array $optionalColumns = [],
        public array $template = [],
        public ?EffectiveForm $form = null,
        public string $source = EffectivePage::SOURCE_PUBLISHED,
        public array $routeParams = [],
        public array $routeRequirements = [],
        public mixed $templateDataConfig = null,
    ) {
    }

    /** Who owns the records the list shows. */
    public function ownerScope(): string
    {
        return $this->config->ownEntriesOnly ? DataQuery::OWNER_USER : DataQuery::OWNER_ANY;
    }
}
