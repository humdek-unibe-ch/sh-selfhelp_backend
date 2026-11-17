<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Exception\ServiceException;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataRevision\DataRecordHistoryService;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataRevision\RestorePlan;
use App\Service\CMS\DataService;
use App\Service\CMS\Effective\FormInputValidator;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The history of a record, as a list offers it: the versions, a comparison,
 * the state at a version and the restore of an earlier version.
 *
 * History is a read of the same record the list shows, so the list's dataset
 * (owner scope and fixed filter) applies first. It reveals more than the
 * current values do, so it is limited twice more: the list must switch it on,
 * and it names only the fields the list or its form shows. Files of an old
 * version are described, never exposed by path, and are delivered only through
 * {@see file()}.
 */
class FormListHistoryService extends BaseService
{
    public function __construct(
        private readonly DataQueryEngine $engine,
        private readonly DataService $dataService,
        private readonly DataRecordHistoryService $history,
        private readonly DataAccessSecurityService $security,
        private readonly FormListBoundary $boundary,
        private readonly FormInputValidator $validator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function versions(FormListContext $ctx, int $recordId, int $page, int $pageSize): array
    {
        $access = $this->access($ctx, $recordId);

        return $this->history->history($recordId, $page, min(max($pageSize, 1), 100), $access['fields']);
    }

    /**
     * @return array<string, mixed>
     */
    public function version(FormListContext $ctx, int $recordId, int $version): array
    {
        $access = $this->access($ctx, $recordId);

        return $this->withHistoricalFileUrls($ctx, $recordId, [
            'revision' => $this->history->revision($recordId, $version, $access['fields']),
            'state' => $this->history->state($recordId, $version, $access['fields']),
        ], $version);
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(FormListContext $ctx, int $recordId, int $from, int $to): array
    {
        $access = $this->access($ctx, $recordId);

        return $this->withHistoricalFileUrls(
            $ctx,
            $recordId,
            $this->history->compare($recordId, $from, $to, $access['fields']),
            $to,
        );
    }

    /**
     * What restoring a version would do (`$dryRun`), or the restore itself.
     *
     * @param list<string>|null $fieldKeys restore only these fields, each a field the caller may see in the history; null: every such field that differs
     * @return array<string, mixed>
     * @throws ServiceException 403 not allowed, 404 record or version unknown, 409 stale, 422 blocked
     */
    public function restore(FormListContext $ctx, int $recordId, int $version, ?array $fieldKeys, ?int $expectedVersion, bool $dryRun): array
    {
        $access = $this->access($ctx, $recordId);
        if (!$access['can_update']) {
            throw new ServiceException('You may not restore this entry.', Response::HTTP_FORBIDDEN, ['reason' => 'operation_disabled', 'operation' => 'update']);
        }
        if ($fieldKeys !== null) {
            foreach ($fieldKeys as $fieldKey) {
                if (!in_array($fieldKey, $access['fields'], true)) {
                    throw new ServiceException(sprintf('The field "%s" cannot be restored here.', $fieldKey), Response::HTTP_BAD_REQUEST, ['reason' => 'field_not_allowed']);
                }
            }
        }
        if (!$dryRun && ($expectedVersion === null || $expectedVersion < 1)) {
            throw new ServiceException(
                'The version of the entry you are changing is required (expected_version).',
                Response::HTTP_BAD_REQUEST,
                ['reason' => 'expected_version_required', 'record_id' => $recordId],
            );
        }
        // A restore changes what the caller can read about in the history and nothing else: a field the list and
        // its form do not show keeps its current value. Without an explicit choice it also leaves alone the inputs
        // the form does not let the person change today (read-only, or hidden by their condition); naming one
        // of them is refused below, with the rest of the rules of the form.
        $fieldKeys ??= $this->changeableFields($ctx, $access['fields']);
        $tableId = (int) $ctx->tableId;
        $effectiveOwnOnly = $access['is_own'] ? $ctx->config->ownEntriesOnly : false;

        if ($dryRun) {
            $plan = $this->dataService->previewRestore($tableId, $recordId, $version, $fieldKeys, $effectiveOwnOnly);
            if ($ctx->form !== null) {
                $plan = $plan->withBlockers($this->validator->restoreBlockers($ctx->form, $plan));
            }

            return ['dry_run' => true, 'restorable' => $plan->isRestorable()] + $plan->toClient($this->history);
        }

        $form = $ctx->form;
        $newVersion = $this->dataService->restoreRecord(
            $tableId,
            $recordId,
            $version,
            $fieldKeys,
            $effectiveOwnOnly,
            RecordWriteContext::interactive(
                LookupService::DATA_REVISION_SOURCES_FORM,
                $expectedVersion,
                $this->boundary->guard($ctx),
                $form === null ? null : fn (RestorePlan $plan) => $this->validator->assertRestorable($form, $plan),
            ),
        );

        return ['dry_run' => false, 'record_id' => $recordId, 'restored_from_version' => $version, 'version' => $newVersion];
    }

    /**
     * The fields of the history a restore may change without being asked: the fields of the list that are not an
     * input of the form, and the inputs the form lets the person change (visible and not read-only).
     *
     * @param list<string> $fields every field the caller may see in the history
     * @return list<string>
     */
    private function changeableFields(FormListContext $ctx, array $fields): array
    {
        if ($ctx->form === null) {
            return $fields;
        }

        $changeable = [];
        foreach ($fields as $field) {
            $input = $ctx->form->input($field);
            if ($input === null || ($input->visible && $input->writable)) {
                $changeable[] = $field;
            }
        }

        return $changeable;
    }

    /**
     * Attach historical-file download URLs to file descriptions (F163).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withHistoricalFileUrls(FormListContext $ctx, int $recordId, array $payload, int $versionHint): array
    {
        $state = $payload['state'] ?? null;
        if (is_array($state)) {
            $version = $this->asInt($state['version'] ?? $versionHint);
            $fields = $state['fields'] ?? null;
            if (is_array($fields)) {
                $payload['state'] = $state + [
                    'fields' => $this->filesWithUrls($ctx, $recordId, $fields, $version),
                ];
            }
        }

        $changes = $payload['changes'] ?? null;
        if (is_array($changes)) {
            $from = $this->asInt($payload['from_version'] ?? $versionHint);
            $to = $this->asInt($payload['to_version'] ?? $versionHint);
            $payload['changes'] = $this->changeFilesWithUrls($ctx, $recordId, $changes, $from, $to);
        }

        return $payload;
    }

    /**
     * @param list<mixed>|array<mixed> $fields
     * @return list<array<string, mixed>>
     */
    private function filesWithUrls(FormListContext $ctx, int $recordId, array $fields, int $version): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            $entry = $field;
            if (($entry['kind'] ?? '') === 'file' && is_array($entry['files'] ?? null)) {
                $entry['files'] = $this->attachUrls(
                    $ctx,
                    $recordId,
                    $this->asString($entry['field_key'] ?? ''),
                    $this->asInt($entry['language_id'] ?? 1),
                    $entry['files'],
                    $version,
                );
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param list<mixed>|array<mixed> $changes
     * @return list<array<string, mixed>>
     */
    private function changeFilesWithUrls(FormListContext $ctx, int $recordId, array $changes, int $from, int $to): array
    {
        $out = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            $entry = $change;
            if (($entry['kind'] ?? '') === 'file') {
                $fieldKey = $this->asString($entry['field_key'] ?? '');
                $languageId = $this->asInt($entry['language_id'] ?? 1);
                if (is_array($entry['before_files'] ?? null)) {
                    $entry['before_files'] = $this->attachUrls($ctx, $recordId, $fieldKey, $languageId, $entry['before_files'], $from);
                }
                if (is_array($entry['after_files'] ?? null)) {
                    $entry['after_files'] = $this->attachUrls($ctx, $recordId, $fieldKey, $languageId, $entry['after_files'], $to);
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param list<mixed>|array<mixed> $files
     * @return list<array<string, mixed>>
     */
    private function attachUrls(
        FormListContext $ctx,
        int $recordId,
        string $fieldKey,
        int $languageId,
        array $files,
        int $version,
    ): array {
        $out = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            $entry = $file;
            if (($entry['available'] ?? false) === true) {
                $entry['url'] = sprintf(
                    '/lists/%d/records/%d/versions/%d/files/%s?page_id=%d&file_language=%d&index=%d',
                    $ctx->config->sectionId,
                    $recordId,
                    $version,
                    rawurlencode($fieldKey),
                    $ctx->pageId,
                    $languageId,
                    $this->asInt($entry['index'] ?? 0),
                );
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * An uploaded file of an old version, found by record, version, field,
     * language and position. Never by a path or key.
     *
     * @return array{absolutePath: string, name: string}
     * @throws ServiceException 404 when the version, field or file does not exist (any more)
     */
    public function file(FormListContext $ctx, int $recordId, int $version, string $fieldKey, int $languageId, int $index): array
    {
        $access = $this->access($ctx, $recordId);
        $file = $this->history->historicalFile($recordId, $version, $fieldKey, $languageId, $index, $access['fields']);
        if ($file === null) {
            throw new ServiceException('File not found', Response::HTTP_NOT_FOUND);
        }

        return $file;
    }

    /**
     * The checks every history request passes: the list offers history, the
     * caller is signed in, the record is in the list's dataset, and the caller
     * may see its history.
     *
     * @return array{fields: list<string>, is_own: bool, can_update: bool}
     */
    private function access(FormListContext $ctx, int $recordId): array
    {
        $config = $ctx->config;
        if (!$config->isEditable() || !$config->showHistory) {
            throw new ServiceException('This list does not show the history of entries.', Response::HTTP_FORBIDDEN, ['reason' => 'history_disabled']);
        }
        if ($ctx->userId === null) {
            throw new ServiceException('Sign in to see the history of entries.', Response::HTTP_UNAUTHORIZED);
        }
        $table = $ctx->tableId === null ? null : $this->dataService->getDataTableById($ctx->tableId);
        if ($ctx->tableId === null || $table === null) {
            throw new ServiceException('Record not found', Response::HTTP_NOT_FOUND);
        }

        $row = $this->boundary->find($ctx, $recordId);
        if ($row === null) {
            throw new ServiceException('Record not found', Response::HTTP_NOT_FOUND);
        }

        $isOwn = $this->asIntOrNull($row['id_users'] ?? null) === $ctx->userId;
        // Foreign history still needs Allow edit; quick actions do not (see FormListStateService).
        $mayUpdateForeign = !$config->ownEntriesOnly && !$isOwn && $config->allowUpdate
            && $this->security->hasPermission($ctx->userId, 'data_table', $ctx->tableId, DataAccessSecurityService::PERMISSION_UPDATE);
        if (!($config->ownEntriesOnly || $isOwn || $mayUpdateForeign)) {
            throw new ServiceException('You do not have permission to see the history of this entry.', Response::HTTP_FORBIDDEN);
        }

        $keys = [];
        foreach ($config->columns as $column) {
            if (!DataQueryEngine::isRowField($column->field)) {
                $keys[$column->field] = true;
            }
        }
        if ($config->columns === []) {
            // A list that names no columns shows every column of its table.
            foreach (array_keys($this->engine->columnsOf($ctx->tableId)) as $key) {
                $keys[(string) $key] = true;
            }
        }
        // The inputs of the form as the effective page defines them, minus those the person cannot see (a
        // condition that does not hold for them): the history never shows what the form hides.
        foreach ($ctx->form->inputsByKey ?? [] as $key => $input) {
            if ($input->visible) {
                $keys[$key] = true;
            }
        }

        return [
            'fields' => array_map('strval', array_keys($keys)),
            'is_own' => $isOwn,
            'can_update' => $config->allowUpdate
                && $this->security->canUpdateOwnedRecord($config->ownEntriesOnly, $isOwn, $mayUpdateForeign),
        ];
    }
}
