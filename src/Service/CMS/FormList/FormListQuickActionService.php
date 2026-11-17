<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Exception\ServiceException;
use App\Service\CMS\DataQuery\DataColValueType;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataRevision\LockedRecord;
use App\Service\CMS\DataRevision\RecordWriteContext;
use App\Service\CMS\DataService;
use App\Service\CMS\Effective\FormInputValidator;
use App\Service\Core\BaseService;
use App\Service\Core\LookupService;
use App\Service\Security\DataAccessSecurityService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs a quick action of a list: a one-click change that the author defined
 * on the server ("mark as done"). The client names the action, the record and
 * the version it saw; the server decides everything else:
 *
 *  - the record must be inside the list's dataset (owner scope and fixed filter);
 *  - the caller must be allowed to update it, exactly as through the form;
 *  - the action's condition must still hold on the stored record;
 *  - the values the action assigns must be values the form of the list accepts today;
 *  - the change goes through the ordinary write path, so the version check, the
 *    history and the form's actions behave as for any edit. A rejected request
 *    changes nothing and schedules nothing.
 *
 * The dataset boundary and the action's condition are checked twice: here, to
 * answer early, and again under the record lock, where the record can no longer change.
 */
class FormListQuickActionService extends BaseService
{
    public function __construct(
        private readonly DataQueryEngine $engine,
        private readonly DataService $dataService,
        private readonly DataAccessSecurityService $security,
        private readonly FormListBoundary $boundary,
        private readonly FormInputValidator $validator,
    ) {
    }

    /**
     * @return array{record_id: int, version: int, action: string}
     * @throws ServiceException 401 guest, 403 not allowed, 404 record or action unknown, 409 stale version or condition no longer holds, 422 a value the form refuses
     */
    public function run(FormListContext $ctx, string $actionId, int $recordId, int $expectedVersion): array
    {
        $config = $ctx->config;
        // Quick actions are independent of "Allow edit" (the form modal): a
        // foreign-table list turns edit off and still runs approve / mark-done.
        if (!$config->isEditable()) {
            throw new ServiceException('This list does not allow to update entries.', Response::HTTP_FORBIDDEN, ['reason' => 'operation_disabled', 'operation' => 'update']);
        }
        if ($ctx->userId === null) {
            throw new ServiceException('Sign in to work with entries.', Response::HTTP_UNAUTHORIZED);
        }
        if ($expectedVersion < 1) {
            throw new ServiceException('The version of the entry you are changing is required (expected_version).', Response::HTTP_BAD_REQUEST, ['reason' => 'expected_version_required']);
        }
        $action = $config->quickAction($actionId);
        if ($action === null) {
            throw new ServiceException('Unknown quick action.', Response::HTTP_NOT_FOUND, ['reason' => 'unknown_action']);
        }
        $table = $ctx->tableId === null ? null : $this->dataService->getDataTableById($ctx->tableId);
        if ($ctx->tableId === null || $table === null) {
            throw new ServiceException('Record not found', Response::HTTP_NOT_FOUND);
        }

        // Inside the dataset: the same answer for another table, another owner, outside the filter, deleted and missing.
        $row = $this->boundary->find($ctx, $recordId);
        if ($row === null) {
            throw new ServiceException('Record not found', Response::HTTP_NOT_FOUND);
        }

        $isOwn = $this->asIntOrNull($row['id_users'] ?? null) === $ctx->userId;
        $mayUpdateForeign = !$config->ownEntriesOnly && !$isOwn
            && $this->security->hasPermission($ctx->userId, 'data_table', $ctx->tableId, DataAccessSecurityService::PERMISSION_UPDATE);
        if (!$this->security->canUpdateOwnedRecord($config->ownEntriesOnly, $isOwn, $mayUpdateForeign)) {
            throw new ServiceException('You do not have permission to update this entry.', Response::HTTP_FORBIDDEN);
        }

        // The condition is checked on the stored record, not on what the client saw.
        if (!$action->when->isEmpty() && !$this->boundary->contains($ctx, $recordId, $action->when)) {
            throw $this->notAvailable($recordId);
        }

        $assignments = $this->assignments($ctx, $ctx->tableId, $action);
        if ($ctx->form !== null) {
            $this->validator->assertAssignable($ctx->form, $assignments);
        }

        $inDataset = $this->boundary->guard($ctx);
        $guard = function (LockedRecord $locked) use ($inDataset, $ctx, $action, $recordId): void {
            $inDataset($locked);
            if (!$action->when->isEmpty() && !$this->boundary->contains($ctx, $recordId, $action->when)) {
                throw $this->notAvailable($recordId);
            }
        };

        $savedId = $this->dataService->saveData(
            (string) $table->getName(),
            $assignments,
            LookupService::TRANSACTION_BY_BY_USER,
            ['record_id' => $recordId],
            $isOwn ? $config->ownEntriesOnly : false,
            null,
            RecordWriteContext::interactive(LookupService::DATA_REVISION_SOURCES_QUICK_ACTION, $expectedVersion, $guard),
        );
        if ($savedId === false) {
            throw new ServiceException('The action could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return [
            'record_id' => $savedId,
            'version' => $this->dataService->getRecordVersion($savedId),
            'action' => $action->id,
        ];
    }

    private function notAvailable(int $recordId): ServiceException
    {
        return new ServiceException(
            'This action is not available for the entry any more.',
            Response::HTTP_CONFLICT,
            ['reason' => 'action_not_available', 'record_id' => $recordId],
        );
    }

    /**
     * The values the action assigns, as stored text. The author-time check
     * already proved that the columns exist and the values fit; a configuration
     * that drifted since is a fault of the list, not of the visitor.
     *
     * @return array<string, string>
     */
    private function assignments(FormListContext $ctx, int $tableId, FormListQuickAction $action): array
    {
        $columns = $this->engine->columnsOf($tableId);
        // A column of the form that no record holds yet is created by this very write.
        foreach ($ctx->optionalColumns as $key => $type) {
            $columns[$key] ??= ['id' => 0, 'type' => $type];
        }
        $data = [];
        foreach ($action->set as $field => $value) {
            $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if (!isset($columns[$field]) || !DataColValueType::reads($columns[$field]['type'], $text)) {
                throw new FormListConfigException(
                    [['field' => 'quick_actions', 'message' => sprintf('The action "%s" cannot assign "%s".', $action->id, $field)]],
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                );
            }
            $data[$field] = $text;
        }

        return $data;
    }
}
