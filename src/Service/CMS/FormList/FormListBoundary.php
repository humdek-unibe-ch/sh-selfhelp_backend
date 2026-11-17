<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\FormList;

use App\Entity\Page;
use App\Exception\ServiceException;
use App\Service\CMS\DataQuery\DataFilterGroup;
use App\Service\CMS\DataQuery\DataQuery;
use App\Service\CMS\DataQuery\DataQueryEngine;
use App\Service\CMS\DataRevision\LockedRecord;
use App\Service\CMS\Effective\EffectivePage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dataset of a list as a boundary around its records.
 *
 * What a list shows (its owner scope and the author's fixed filter) is also what
 * it may change: a record outside the dataset is "not found" for every door of
 * the list, whether the request comes through the list's state, a quick action,
 * the history or the ordinary form endpoints (update, delete, restore). A table
 * and an owner are not enough: a list of the open items does not reach the closed ones.
 *
 * The boundary is read, not cached, wherever it guards a write: the guard of an
 * interactive write ({@see self::guard()}) runs under the record lock, in the
 * transaction of the write.
 *
 * It is the boundary *before* the change. A change may move the record out of the
 * dataset (an action that sets the status of an item to "done" leaves a list of
 * open items); that is the intended transition, not a violation.
 */
class FormListBoundary
{
    public function __construct(
        private readonly FormListContextResolver $resolver,
        private readonly FormListStateService $state,
        private readonly DataQueryEngine $engine,
    ) {
    }

    /**
     * The context of a list whose page access and effective page have been checked already.
     */
    public function contextFor(Page $page, EffectivePage $effective, int $listSectionId, ?int $languageId = null): FormListContext
    {
        return $this->resolver->contextIn($page, $effective, $listSectionId, $languageId, false);
    }

    /**
     * The query for the records of the list's dataset: the owner scope and the fixed filter,
     * and optionally one more condition.
     */
    public function query(FormListContext $ctx, ?DataFilterGroup $when = null): DataQuery
    {
        $nodes = $this->state->boundary($ctx->config);
        if ($when !== null && !$when->isEmpty()) {
            $nodes[] = $when;
        }

        return new DataQuery(
            tableId: (int) $ctx->tableId,
            ownerScope: $ctx->ownerScope(),
            userId: $ctx->userId,
            filter: new DataFilterGroup(DataFilterGroup::MODE_AND, $nodes),
            columns: [],
            languageId: $ctx->languageId,
            timezone: $ctx->timezone,
            optionalColumns: $ctx->optionalColumns,
        );
    }

    /**
     * The one record of the dataset (optionally only when it also matches a condition), or null.
     * Read through the result cache; fine for a decision that a guard under the lock repeats.
     *
     * @return array<string, mixed>|null
     */
    public function find(FormListContext $ctx, int $recordId, ?DataFilterGroup $when = null): ?array
    {
        if ($ctx->tableId === null) {
            return null;
        }

        return $this->engine->first($this->query($ctx, $when)->forRecord($recordId));
    }

    /**
     * Whether the record is in the dataset right now, read from the database, never from a cache.
     */
    public function contains(FormListContext $ctx, int $recordId, ?DataFilterGroup $when = null): bool
    {
        if ($ctx->tableId === null) {
            return false;
        }

        return $this->engine->count($this->query($ctx, $when)->forRecord($recordId)) > 0;
    }

    /**
     * The guard an interactive write of a list's record runs under the record lock: the record must be in the
     * dataset of the list. Another table, another owner, outside the fixed filter, deleted and missing all
     * answer the same, "not found".
     *
     * @return \Closure(LockedRecord): void
     */
    public function guard(FormListContext $ctx): \Closure
    {
        return function (LockedRecord $locked) use ($ctx): void {
            if ($ctx->tableId === null || $locked->tableId !== $ctx->tableId || !$this->contains($ctx, $locked->recordId)) {
                throw new ServiceException('Record not found', Response::HTTP_NOT_FOUND);
            }
        };
    }
}
