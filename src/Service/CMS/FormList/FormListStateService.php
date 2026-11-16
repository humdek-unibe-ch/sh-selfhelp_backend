<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Exception\ServiceException;
use App\Service\CMS\DataQuery\DataFilter;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQuery;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataQuery\DataSearch;
use App\Service\CMS\DataQuery\DataSort;
use App\Service\CMS\DataService;
use App\Service\CMS\FormFileUploadService;
use App\Service\CMS\Frontend\PageService;
use App\Service\Core\BaseService;
use App\Service\Security\DataAccessSecurityService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the state of a list: the page of records the visitor sees, what the
 * visitor may do with each, the controls with their choices, and the record
 * that is open. The server decides everything here; clients only draw it.
 *
 * Every read goes through {@see DataQueryEngine}: the dataset boundary (owner
 * scope plus the author's fixed filter) is part of every query, so a filter,
 * a search or a record id in the URL can only narrow what the visitor sees.
 */
class FormListStateService extends BaseService
{
    /** Longest value a list row carries; the open record carries more. */
    private const ROW_VALUE_BYTES = 1000;
    private const DETAIL_VALUE_BYTES = 8000;

    /** Quick-action conditions are checked for a page of rows in chunks this big. */
    private const CONDITION_CHUNK = 50;

    public function __construct(
        private readonly DataQueryEngine $engine,
        private readonly DataService $dataService,
        private readonly DataAccessSecurityService $security,
        private readonly FormListCurrentFileService $currentFiles,
        private readonly EntityManagerInterface $entityManager,
        private readonly PageService $pageService,
    ) {
    }

    /**
     * @return array<string, mixed> the list state of the `lists/{id}/state` response
     * @throws ServiceException 400 invalid state, 401 a guest on a list of own records, 403 a mode the caller may not use, 404 an unknown record
     */
    public function state(FormListContext $ctx, FormListUrlState $url): array
    {
        $config = $ctx->config;
        if ($config->ownEntriesOnly && $ctx->userId === null) {
            // Nobody's records are anonymous: a guest has none, and the client can say "sign in".
            throw new ServiceException('Sign in to see your entries.', Response::HTTP_UNAUTHORIZED);
        }

        $tableId = $ctx->tableId;
        $grants = $this->foreignGrants($ctx);

        $page = max(1, $url->page);
        $rows = [];
        $total = 0;
        $versions = [];
        $qualifying = [];
        if ($tableId !== null) {
            $filter = $this->filterFor($ctx, $url);
            $search = $this->searchFor($ctx, $url);
            $sorts = $url->sorts !== [] ? $url->sorts : $config->defaultSorts;
            $query = new DataQuery(
                tableId: $tableId,
                ownerScope: $ctx->ownerScope(),
                userId: $ctx->userId,
                filter: $filter,
                search: $search,
                sorts: $sorts,
                limit: $config->pageSize,
                offset: ($page - 1) * $config->pageSize,
                columns: $config->projectedFields(),
                excludeDeleted: true,
                languageId: $ctx->languageId,
                timezone: $ctx->timezone,
                withTotal: true,
                optionalColumns: $ctx->optionalColumns,
            );
            $result = $this->engine->run($query);
            $total = $result->total ?? 0;

            if ($result->rows === [] && $total > 0 && $page > 1) {
                // The page the visitor asked for no longer exists (records were
                // deleted meanwhile): show the last one.
                $page = (int) ceil($total / $config->pageSize);
                $result = $this->engine->run($query->withPage($config->pageSize, ($page - 1) * $config->pageSize));
            }
            $rows = $result->rows;

            $ids = array_map(fn (array $row): int => $this->asInt($row['record_id'] ?? null), $rows);
            $versions = $this->versionsOf($ids);
            $qualifying = $this->qualifyingActions($ctx, $ids);
        }

        $rowPayloads = [];
        foreach ($rows as $row) {
            $rowPayloads[] = $this->rowPayload($ctx, $row, $versions, $grants, $qualifying);
        }
        $rowPayloads = $this->withRowSections($ctx, $rowPayloads);

        $selected = $this->selected($ctx, $url, $grants);
        $effective = $url->onPage($page);

        return [
            'list' => [
                'section_id' => $config->sectionId,
                'page_id' => $ctx->pageId,
                'key' => $config->key,
                'style' => $config->style,
                'layout' => $config->layout,
                'form_presentation' => $config->formPresentation,
                'form_section_id' => $ctx->formSectionId,
                'table_exists' => $tableId !== null,
                'labels' => $config->labels,
                'row_css' => $config->rowCss,
                'has_template' => $ctx->template !== [],
            ],
            'columns' => array_map(fn (FormListColumn $column): array => [
                'field' => $column->field,
                'label' => $column->label,
                'format' => $column->format,
                'sortable' => $column->sortable,
                'searchable' => $column->searchable,
            ], $config->columns),
            'controls' => $this->controlsPayload($ctx),
            'state' => [
                'page' => $page,
                'page_size' => $config->pageSize,
                'q' => $effective->search,
                'sort' => array_map(
                    static fn (DataSort $sort): array => ['field' => $sort->column, 'direction' => $sort->direction],
                    $url->sorts !== [] ? $url->sorts : $config->defaultSorts,
                ),
                'filters' => (object) $effective->filters,
                'record' => $effective->recordId,
                'mode' => $effective->mode,
                'query' => (object) $effective->toQuery($config->key),
            ],
            'capabilities' => [
                'create' => $config->allowCreate && ($ctx->userId !== null || !$config->ownEntriesOnly),
                'search' => $config->searchableFields() !== [],
                'history' => $config->showHistory,
                'quick_actions' => array_map(static fn (FormListQuickAction $action): array => [
                    'id' => $action->id,
                    'label' => $action->label,
                    'confirm' => $action->confirm,
                ], $config->quickActions),
            ],
            'rows' => $rowPayloads,
            'pagination' => [
                'page' => $page,
                'page_size' => $config->pageSize,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $config->pageSize),
            ],
            'selected' => $selected,
        ];
    }

    // -- the dataset ----------------------------------------------------------------------

    /**
     * The conditions that bound the dataset: the author's fixed filter, as
     * nodes that can be merged into a group. Nothing the visitor sends is in here.
     *
     * @return list<DataFilter|DataFilterGroup>
     */
    public function boundary(FormListConfig $config): array
    {
        $fixed = $config->fixedFilter;
        if ($fixed->isEmpty()) {
            return [];
        }

        return $fixed->mode === DataFilterGroup::MODE_AND ? $fixed->children : [$fixed];
    }

    /**
     * The boundary plus what the visitor chose in the controls.
     */
    private function filterFor(FormListContext $ctx, FormListUrlState $url): DataFilterGroup
    {
        $nodes = $this->boundary($ctx->config);
        foreach ($url->filters as $id => $value) {
            $control = $ctx->config->control($id);
            if ($control === null) {
                continue; // the parser already refused unknown controls
            }
            $nodes[] = $this->controlFilter($ctx, $control, $value);
        }

        return new DataFilterGroup(DataFilterGroup::MODE_AND, $nodes);
    }

    private function searchFor(FormListContext $ctx, FormListUrlState $url): ?DataSearch
    {
        if ($url->search === null) {
            return null;
        }
        $fields = $ctx->config->searchableFields();
        if ($fields === []) {
            throw new FormListStateException('This list has no search.', 'invalid_list_parameter', $ctx->config->key . '.q');
        }

        return new DataSearch($url->search, $fields);
    }

    private function controlFilter(FormListContext $ctx, FormListControl $control, string $value): DataFilter
    {
        $parameter = $ctx->config->key . '.f.' . $control->id;
        $invalid = static fn (string $message): FormListStateException => new FormListStateException($message, 'invalid_list_parameter', $parameter);

        switch ($control->type) {
            case FormListControl::TYPE_TEXT:
                return new DataFilter($control->field, $control->match, $value);
            case FormListControl::TYPE_SELECT:
                return new DataFilter($control->field, DataFilter::OP_EQ, $value);
            case FormListControl::TYPE_MULTI_SELECT:
                $values = array_values(array_filter(
                    array_map('trim', explode(',', $value)),
                    static fn (string $item): bool => $item !== '',
                ));
                if ($values === [] || count($values) > FormListConfig::MAX_CHOICES) {
                    throw $invalid(sprintf('Choose 1 to %d values.', FormListConfig::MAX_CHOICES));
                }

                return new DataFilter($control->field, DataFilter::OP_IN, $values);
            case FormListControl::TYPE_BOOLEAN:
                $flag = match (strtolower($value)) {
                    '1', 'true', 'yes' => '1',
                    '0', 'false', 'no' => '0',
                    default => null,
                };
                if ($flag === null) {
                    throw $invalid('A yes/no filter is 1 or 0.');
                }

                return new DataFilter($control->field, DataFilter::OP_EQ, $flag);
            default: // date_range, number_range
                $parts = explode('..', $value, 2);
                if (count($parts) !== 2) {
                    throw $invalid('A range is "from..to"; either side may be empty.');
                }
                $from = trim($parts[0]);
                $to = trim($parts[1]);
                if ($from === '' && $to === '') {
                    throw $invalid('A range needs a start or an end.');
                }
                if ($to !== '' && $this->isDateTimeField($ctx, $control->field) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
                    $to .= ' 23:59:59'; // a day as the end of a range includes that whole day
                }
                if ($from !== '' && $to !== '') {
                    return new DataFilter($control->field, DataFilter::OP_BETWEEN, [$from, $to]);
                }

                return $from !== ''
                    ? new DataFilter($control->field, DataFilter::OP_GTE, $from)
                    : new DataFilter($control->field, DataFilter::OP_LTE, $to);
        }
    }

    private function isDateTimeField(FormListContext $ctx, string $field): bool
    {
        if ($field === 'entry_date') {
            return true;
        }
        if ($ctx->tableId === null) {
            return false;
        }

        return ($this->engine->columnsOf($ctx->tableId)[$field]['type'] ?? '') === 'datetime';
    }

    // -- rows ------------------------------------------------------------------------------

    /**
     * What the caller may do with records of other users in this list.
     *
     * @return array{update: bool, delete: bool}
     */
    private function foreignGrants(FormListContext $ctx): array
    {
        $none = ['update' => false, 'delete' => false];
        if ($ctx->tableId === null || $ctx->userId === null || $ctx->config->ownEntriesOnly || !$ctx->config->isEditable()) {
            return $none;
        }

        return [
            'update' => $ctx->config->allowUpdate
                && $this->security->hasPermission($ctx->userId, 'data_table', $ctx->tableId, DataAccessSecurityService::PERMISSION_UPDATE),
            'delete' => $ctx->config->allowDelete
                && $this->security->hasPermission($ctx->userId, 'data_table', $ctx->tableId, DataAccessSecurityService::PERMISSION_DELETE),
        ];
    }

    /**
     * @param array<string, mixed> $row a row of the engine
     * @param array<int, int> $versions record id => version
     * @param array{update: bool, delete: bool} $grants
     * @param array<string, array<int, true>> $qualifying quick action id => record ids it applies to
     * @return array<string, mixed>
     */
    private function rowPayload(FormListContext $ctx, array $row, array $versions, array $grants, array $qualifying): array
    {
        $config = $ctx->config;
        $recordId = $this->asInt($row['record_id'] ?? null);
        $isOwn = $ctx->userId !== null && $this->asIntOrNull($row['id_users'] ?? null) === $ctx->userId;

        $can = $this->capabilitiesOf($ctx, $isOwn, $grants);
        $actions = [];
        if ($can['update']) {
            foreach ($config->quickActions as $action) {
                if (isset($qualifying[$action->id][$recordId])) {
                    $actions[] = $action->id;
                }
            }
        }

        $values = [];
        foreach ($config->columns as $column) {
            $values[$column->field] = $this->cell(
                $ctx,
                $row[$column->field] ?? null,
                $recordId,
                $column->field,
                $column->format,
                self::ROW_VALUE_BYTES,
            );
        }

        return [
            'record_id' => $recordId,
            'entry_date' => $this->asString($row['entry_date'] ?? ''),
            'version' => $versions[$recordId] ?? 1,
            'values' => (object) $values,
            'is_own' => $isOwn,
            'can_update' => $can['update'],
            'can_delete' => $can['delete'],
            'can_view_history' => $can['history'],
            'quick_actions' => $actions,
            'detail_url' => $config->detailUrl !== null ? str_replace('{record_id}', (string) $recordId, $config->detailUrl) : null,
        ];
    }

    /**
     * Draw every record of a data view with the layout its author built from
     * sections: the template is rendered once per record with the values the row
     * itself carries, so a section can show nothing the list does not (the
     * columns the author named, the record number and the entry date; no file
     * path, no person).
     *
     * @param list<array<string, mixed>> $payloads
     * @return list<array<string, mixed>> the rows, each with its `sections`
     */
    private function withRowSections(FormListContext $ctx, array $payloads): array
    {
        if (!$ctx->config->usesRowTemplate() || $ctx->tableId === null || $payloads === []) {
            return $payloads;
        }

        $tokens = [];
        foreach ($payloads as $payload) {
            $row = [];
            foreach ((array) ($payload['values'] ?? []) as $field => $cell) {
                $key = (string) $field;
                if (is_array($cell) && isset($cell['files']) && is_array($cell['files'])) {
                    // Contract 3: {{field__url}} / {{field__files}} / {{field__links}}
                    // for the row layout (HTML links for the existing `text` style).
                    $files = $cell['files'];
                    $first = $files[0] ?? null;
                    $firstUrl = is_array($first) && is_string($first['url'] ?? null) ? $first['url'] : '';
                    $row[$key . '__url'] = new \App\Service\Core\ServerBuiltString($firstUrl);
                    $row[$key . '__files'] = json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
                    $row[$key . '__links'] = new \App\Service\Core\ServerBuiltString(
                        \App\Service\CMS\Frontend\PageService::fileLinksHtml($files),
                    );
                    continue;
                }
                if (is_array($cell)) {
                    continue;
                }
                $row[$key] = $cell ?? '';
            }
            $row['record_id'] = $payload['record_id'] ?? 0;
            $row['entry_date'] = $payload['entry_date'] ?? '';
            $tokens[] = $row;
        }

        $sections = $this->pageService->renderRowTemplate(
            $ctx->template,
            $tokens,
            $ctx->tableId,
            $ctx->userId,
            $ctx->languageId,
            $ctx->routeParams,
            $ctx->routeRequirements,
            $ctx->templateDataConfig,
        );
        foreach ($payloads as $index => $payload) {
            $payloads[$index]['sections'] = $sections[$index] ?? [];
        }

        return $payloads;
    }

    /**
     * @param array{update: bool, delete: bool} $grants
     * @return array{update: bool, delete: bool, history: bool}
     */
    private function capabilitiesOf(FormListContext $ctx, bool $isOwn, array $grants): array
    {
        $config = $ctx->config;
        if (!$config->isEditable()) {
            return ['update' => false, 'delete' => false, 'history' => false];
        }
        $signedIn = $ctx->userId !== null;

        return [
            'update' => $signedIn && $config->allowUpdate && $this->security->canUpdateOwnedRecord($config->ownEntriesOnly, $isOwn, $grants['update']),
            'delete' => $signedIn && $config->allowDelete && $this->security->canDeleteOwnedRecord($config->ownEntriesOnly, $isOwn, $grants['delete']),
            'history' => $signedIn && $config->showHistory && ($config->ownEntriesOnly || $isOwn || $grants['update']),
        ];
    }

    /**
     * A stored cell as a client receives it: text, a number for the record id,
     * or — for an uploaded file — `{files: [...]}` with server-built list URLs
     * (never a raw `uploads/form-files/...` key).
     *
     * @return string|int|array{files: list<array{index: int, name: string, mime: string|null, size: int|null, url: string}>}|null
     */
    private function cell(
        FormListContext $ctx,
        mixed $raw,
        int $recordId,
        string $field,
        string $format,
        int $maxBytes,
    ): string|int|array|null {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        $text = is_scalar($raw) ? (string) $raw : '';

        if (
            in_array($format, [FormListColumn::FORMAT_FILE, FormListColumn::FORMAT_IMAGE], true)
            || FormFileUploadService::cellHoldsFiles($text)
        ) {
            $files = $this->currentFiles->filesPayload($ctx, $recordId, $field, $text);
            if ($files !== [] || in_array($format, [FormListColumn::FORMAT_FILE, FormListColumn::FORMAT_IMAGE], true)) {
                return ['files' => $files];
            }
        }

        return strlen($text) > $maxBytes ? mb_strcut($text, 0, $maxBytes) : $text;
    }

    /**
     * @param list<int> $ids
     * @return array<int, int> record id => version
     */
    private function versionsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, version FROM data_rows WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $versions = [];
        foreach ($rows as $row) {
            $versions[$this->asInt($row['id'])] = $this->asInt($row['version']);
        }

        return $versions;
    }

    /**
     * For every quick action the records of this page it applies to. An action
     * without a condition applies to all of them; a condition is checked by the
     * engine inside the dataset boundary, so it sees what the list sees.
     *
     * @param list<int> $ids
     * @return array<string, array<int, true>>
     */
    private function qualifyingActions(FormListContext $ctx, array $ids): array
    {
        $result = [];
        if ($ctx->tableId === null || $ids === [] || !$ctx->config->isEditable()) {
            return $result;
        }

        foreach ($ctx->config->quickActions as $action) {
            $matching = [];
            if ($action->when->isEmpty()) {
                $matching = array_fill_keys($ids, true);
            } else {
                foreach (array_chunk($ids, self::CONDITION_CHUNK) as $chunk) {
                    $query = new DataQuery(
                        tableId: $ctx->tableId,
                        ownerScope: $ctx->ownerScope(),
                        userId: $ctx->userId,
                        filter: new DataFilterGroup(DataFilterGroup::MODE_AND, [
                            ...$this->boundary($ctx->config),
                            $action->when,
                            new DataFilter('record_id', DataFilter::OP_IN, $chunk),
                        ]),
                        limit: count($chunk),
                        columns: [],
                        languageId: $ctx->languageId,
                        timezone: $ctx->timezone,
                        optionalColumns: $ctx->optionalColumns,
                    );
                    foreach ($this->engine->run($query)->rows as $row) {
                        $matching[$this->asInt($row['record_id'] ?? null)] = true;
                    }
                }
            }
            $result[$action->id] = $matching;
        }

        return $result;
    }

    // -- controls --------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function controlsPayload(FormListContext $ctx): array
    {
        $payload = [];
        foreach ($ctx->config->controls as $control) {
            $item = [
                'id' => $control->id,
                'type' => $control->type,
                'field' => $control->field,
                'label' => $control->label !== '' ? $control->label : $this->labelOf($ctx, $control->field),
            ];
            if ($control->type === FormListControl::TYPE_TEXT) {
                $item['match'] = $control->match;
            }
            if ($control->usesChoices()) {
                $item['options'] = $control->options !== null
                    ? array_map(static fn (array $option): array => $option + ['count' => null], $control->options)
                    : $this->choicesFromData($ctx, $control);
            }
            $payload[] = $item;
        }

        return $payload;
    }

    /**
     * The values that exist in the dataset the visitor may see, with how many
     * records hold each. The author's boundary and the owner scope apply; the
     * visitor's own filters do not, so choosing a value does not shrink the
     * other choices to one.
     *
     * @return list<array{value: string, label: string, count: int|null}>
     */
    private function choicesFromData(FormListContext $ctx, FormListControl $control): array
    {
        if ($ctx->tableId === null || !$control->optionsFromData) {
            return [];
        }
        $query = new DataQuery(
            tableId: $ctx->tableId,
            ownerScope: $ctx->ownerScope(),
            userId: $ctx->userId,
            filter: new DataFilterGroup(DataFilterGroup::MODE_AND, $this->boundary($ctx->config)),
            limit: null,
            columns: [],
            languageId: $ctx->languageId,
            timezone: $ctx->timezone,
            optionalColumns: $ctx->optionalColumns,
        );

        $choices = [];
        foreach ($this->engine->facet($query, $control->field, FormListConfig::MAX_CHOICES) as $facet) {
            $choices[] = ['value' => $facet['value'], 'label' => $facet['value'], 'count' => $facet['count']];
        }

        return $choices;
    }

    private function labelOf(FormListContext $ctx, string $field): string
    {
        $column = $ctx->config->column($field);
        if ($column !== null && $column->label !== '') {
            return $column->label;
        }

        return $field;
    }

    // -- the open record ---------------------------------------------------------------------

    /**
     * @param array{update: bool, delete: bool} $grants
     * @return array<string, mixed>|null
     */
    private function selected(FormListContext $ctx, FormListUrlState $url, array $grants): ?array
    {
        if ($url->recordId === null || $ctx->tableId === null) {
            if ($url->recordId !== null) {
                throw new ServiceException('Record not found', Response::HTTP_NOT_FOUND);
            }

            return null;
        }
        $config = $ctx->config;

        // The open record is read inside the dataset boundary and the owner
        // scope, never inside the visitor's search or filters: a link to a record
        // keeps working after the visitor narrows the list, and can never reach a
        // record the list does not show.
        $query = (new DataQuery(
            tableId: $ctx->tableId,
            ownerScope: $ctx->ownerScope(),
            userId: $ctx->userId,
            filter: new DataFilterGroup(DataFilterGroup::MODE_AND, $this->boundary($config)),
            columns: $config->projectedFields(),
            languageId: $ctx->languageId,
            timezone: $ctx->timezone,
            optionalColumns: $ctx->optionalColumns,
        ))->forRecord($url->recordId);
        $row = $this->engine->first($query);
        if ($row === null) {
            // Another table, another owner, outside the boundary, deleted and
            // missing all look the same.
            throw new ServiceException('Record not found', Response::HTTP_NOT_FOUND);
        }

        $isOwn = $ctx->userId !== null && $this->asIntOrNull($row['id_users'] ?? null) === $ctx->userId;
        $can = $this->capabilitiesOf($ctx, $isOwn, $grants);
        if ($url->mode === FormListUrlState::MODE_EDIT && !$can['update']) {
            throw new ServiceException('You may not edit this entry.', Response::HTTP_FORBIDDEN, ['reason' => 'operation_disabled', 'operation' => 'update']);
        }

        $values = [];
        foreach ($config->columns as $column) {
            $values[$column->field] = $this->cell(
                $ctx,
                $row[$column->field] ?? null,
                $url->recordId,
                $column->field,
                $column->format,
                self::DETAIL_VALUE_BYTES,
            );
        }

        $actions = [];
        if ($can['update']) {
            $qualifying = $this->qualifyingActions($ctx, [$url->recordId]);
            foreach ($config->quickActions as $action) {
                if (isset($qualifying[$action->id][$url->recordId])) {
                    $actions[] = $action->id;
                }
            }
        }

        $formValues = null;
        if ($config->isEditable() && $can['update']) {
            $table = $this->dataService->getDataTableById($ctx->tableId);
            $raw = $table !== null
                ? $this->dataService->getFormRecordDataForRecord((string) $table->getName(), $url->recordId, $config->ownEntriesOnly, false)
                : [];
            $formValues = $this->withFilePrefill($ctx, $url->recordId, $raw);
        }

        return [
            'record_id' => $url->recordId,
            'mode' => $url->mode ?? FormListUrlState::MODE_VIEW,
            'entry_date' => $this->asString($row['entry_date'] ?? ''),
            'version' => $this->versionsOf([$url->recordId])[$url->recordId] ?? 1,
            'values' => (object) $values,
            'form_values' => $formValues, // the prefill the form section receives on a page load
            'is_own' => $isOwn,
            'can_update' => $can['update'],
            'can_delete' => $can['delete'],
            'can_view_history' => $can['history'],
            'quick_actions' => $actions,
            'detail_url' => $config->detailUrl !== null ? str_replace('{record_id}', (string) $url->recordId, $config->detailUrl) : null,
        ];
    }

    /**
     * Prefill of a record for the inline form: each file-input field carries
     * its current `files` list (contract 4), never a raw storage key.
     *
     * @param array<mixed> $rows
     * @return array<mixed>
     */
    private function withFilePrefill(FormListContext $ctx, int $recordId, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        /** @var array<string, true> $fileKeys */
        $fileKeys = [];
        foreach ($ctx->form->inputsByKey ?? [] as $key => $input) {
            if ($input->isFile) {
                $fileKeys[(string) $key] = true;
                $fileKeys[$input->name] = true;
            }
        }
        if ($fileKeys === []) {
            return $rows;
        }

        /**
         * @param array<string, mixed> $row
         * @return array<string, mixed>
         */
        $enrich = function (array $row) use ($ctx, $recordId, $fileKeys): array {
            foreach ($fileKeys as $key => $_) {
                if (!array_key_exists($key, $row)) {
                    continue;
                }
                $raw = $row[$key];
                $text = is_scalar($raw) ? (string) $raw : '';
                $row[$key] = ['files' => $this->currentFiles->filesPayload($ctx, $recordId, $key, $text)];
            }

            return $row;
        };

        if (array_is_list($rows)) {
            $out = [];
            foreach ($rows as $row) {
                $out[] = is_array($row) ? $enrich($row) : $row;
            }

            return $out;
        }

        return $enrich($rows);
    }
}
