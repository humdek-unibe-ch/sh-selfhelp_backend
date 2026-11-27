<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Users;

use App\Exception\ServiceException;

/**
 * Writing a record for another user (contract C20): the door a therapist,
 * coach or teacher plugin uses to put a record into the generic data store
 * that belongs to a participant, not to the person who is signed in.
 *
 * The record is an ordinary row of the data store: the participant owns it,
 * history keeps every change (the revision names the actor and the door
 * `plugin`), and the `table:` change notice reaches the participant and the
 * roles with a grant on the table. Nothing is added beside the store.
 *
 * **Who the history names.** The data store writes the history row with the user who is signed in for the
 * request, and the write context has no field for anybody else. So a non-zero actor must be that user (the
 * effective one: with an impersonation it is the impersonated user, and the history keeps the impersonator
 * beside it); a plugin cannot put another name into the history. With {@see self::SYSTEM_ACTOR} the history
 * names nobody in a job, and the signed-in person when a listener runs inside that person's request.
 *
 * **Guests.** An anonymous visitor is user id `0` in the whole product, the same number as
 * {@see self::SYSTEM_ACTOR}, and `saveFor` cannot tell the two apart. A plugin that serves a visitor passes
 * `SYSTEM_ACTOR` only after it has proved the visitor's right itself (for example with a one-time token it
 * issued), never as a default. A visitor can never be the owner of a record written here: the owner is a user
 * that exists; records of visitors keep using the anonymous path of the data store (owner `NULL`).
 */
interface PluginRecordWriterInterface
{
    /**
     * The actor of a write that no person makes: a job, or a domain-event listener. It is allowed without a
     * management check, so use it only where the plugin has decided that the write is its own to make.
     */
    public const SYSTEM_ACTOR = 0;

    /**
     * Create a record of `$ownerUserId`, or change one, in a table the plugin owns.
     *
     * Who may write: the actor is the owner, manages the owner
     * ({@see PluginUserScopeInterface::mayManage()}), or is {@see self::SYSTEM_ACTOR} (a job
     * or a listener that runs without a person). Everybody else is refused with
     * {@see UserNotManagedException} before anything is written, and the refusal is audited.
     * A non-zero actor must also be the signed-in user (see above), else the call is a programming error.
     *
     * The write is not a person's form request: it is trusted code, so it sets
     * the owner and the optional trigger type of the row itself, and it compares
     * a version only when the caller names one.
     *
     * @param string $pluginId the writing plugin's manifest id; the plugin must be enabled and declare `dataAccess.ownedDataTablePrefix`
     * @param int $ownerUserId the user the record belongs to (an existing user)
     * @param int $actorUserId who writes: the signed-in user's id, or {@see self::SYSTEM_ACTOR}
     * @param string $tableName must start with the plugin's `ownedDataTablePrefix`, and continue after it
     * @param array<string, mixed> $data the values of the record by field key. `id_users` is the owner's, set by this method;
     *        a different value is refused. `trigger_type` may be given
     * @param string|null $contentRef the content the write was made for (`res:<type>:<id>@v<n>`, contract C19)
     * @param int|null $recordId null creates a record; an id changes that record, which must exist in the table and belong to the owner
     * @param int|null $expectedVersion with `$recordId`: the version the change is based on; a newer one is refused with 409
     * @return int the id of the record
     *
     * @throws UserNotManagedException 403 `user_not_managed`: the actor neither is nor manages the owner
     * @throws \InvalidArgumentException a programming error: the plugin is not enabled or owns no table prefix, the table is outside
     *         it, an id or version is unusable (the owner must be a user, 1 or more), a non-zero actor is not the signed-in user,
     *         `id_users` names another user, or the content reference is malformed or names a `res:` type no enabled plugin registered
     * @throws ServiceException 404 `user_not_found` (the owner does not exist), 404 `record_not_found` (no such record
     *         of this owner in this table), 409 when `$expectedVersion` is stale, or the data store's own refusal
     */
    public function saveFor(
        string $pluginId,
        int $ownerUserId,
        int $actorUserId,
        string $tableName,
        array $data,
        ?string $contentRef = null,
        ?int $recordId = null,
        ?int $expectedVersion = null,
    ): int;
}
