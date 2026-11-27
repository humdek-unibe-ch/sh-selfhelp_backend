<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Resource;

/**
 * What the host does for the items of a plugin's resource type (contract C3),
 * so a plugin never writes grants, clears caches or picks notice topics
 * itself (rules 3.4, 4.6b, 4.6f).
 *
 * `$type` is the code of a `resourceTypes` lookup entry the plugin registers
 * in its manifest. Every method refuses a type no enabled plugin registers
 * with an `\InvalidArgumentException`: that is a programming error, not a
 * user error.
 *
 * Call `changed()` and `deleted()` **after** your own transaction has
 * committed; call `grantCreator()` **inside** the create transaction.
 */
interface PluginResourceServiceInterface
{
    /**
     * An item was created or changed. Clears the item's cache scope
     * ({@see \App\Service\Cache\Core\CacheService::withResourceScope()}) and
     * sends the `res:<type>:<id>` change notice (C5) to the item's editors
     * and to the editors of every page that holds the item; when `$published`
     * is true, also to the viewers of every page whose published version holds
     * it. Pass `true` for a change visitors can see.
     */
    public function changed(string $type, int $id, bool $published): void;

    /**
     * An item was removed. Removes every grant on it, so no role keeps a right
     * on something that is gone (F280); drops the cached rights of each role
     * that lost a grant (their `acl` notice follows, F250); clears the item's
     * cache scope; and sends the change notice as a published change, so open
     * pages and editors drop it.
     */
    public function deleted(string $type, int $id): void;

    /**
     * An item was created: give full access on it to the signed-in caller's
     * roles that hold `$createPermission` (the superuser role is never stored,
     * it passes every check). Call it in the create transaction (rule 4.6b).
     * Nothing happens when no one is signed in.
     */
    public function grantCreator(string $createPermission, string $type, int $id): void;

    /**
     * What a user may do with each of the items, from one query. The superuser
     * may do everything. A right is the grant bit of the user's roles on that
     * item: view is read, update is update, delete is delete.
     *
     * @param list<int> $ids item ids
     * @return array<int, array{can_view: bool, can_update: bool, can_delete: bool}> item id => rights (one entry per distinct positive id)
     */
    public function rightsFor(string $type, array $ids, int $userId): array;

    /**
     * Which version of an item a participant's unfinished record is pinned to (contract C19), so the plugin keeps
     * showing the version the participant began on while the item moves on.
     *
     * A record carries the content reference of its latest write that named one: a plugin names the item version it
     * wrote for with `RecordWriteContext::withContentRef('res:<type>:<id>@v<n>')`. This returns `<n>` of the newest
     * record of `$userId` in the table `$dataTableId` that carries `res:<type>:<id>@v<n>` and is unfinished (trigger
     * type `started` or `updated`, so not finished and not deleted). Without such a record it returns null; so does a
     * guest (user id 0): a guest has no record of their own to pin.
     *
     * @param int $userId the participant (a signed-in user)
     * @param int $dataTableId the data table the records are written to
     * @param int $id the item id
     * @return int|null the pinned item version, or null for "no pin: use the item's current version"
     */
    public function pinnedVersion(int $userId, int $dataTableId, string $type, int $id): ?int;
}
