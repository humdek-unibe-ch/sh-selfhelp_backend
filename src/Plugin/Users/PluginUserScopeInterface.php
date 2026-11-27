<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Users;

use App\Service\Security\DataAccessSecurityService;

/**
 * The users a caller manages (contract C20): the question a therapist, coach or
 * teacher plugin asks before it shows or changes anything of another person.
 *
 * It is the existing data-access rule for groups and nothing new: groups say
 * who is whose, roles edit. A caller manages a user when the caller is the
 * superuser, is the user themself, or holds the permission bit (by default
 * *update*) on a group the user belongs to, through any of their roles. Read
 * access to a group is not management: with the update bit, a role that only
 * has *read* manages nobody.
 *
 * Both methods answer for an id the host already knows (the token's user, a
 * job's recorded user). `0` is the guest or the system: it manages nobody.
 */
interface PluginUserScopeInterface
{
    /**
     * Whether `$callerId` manages `$userId`.
     *
     * The answer is decided like every other access decision of the admin
     * screens (`AdminUserService::canAccessUser`), refusals included, so a
     * denied check leaves the same audit rows. An unknown `$userId` is managed
     * only by the superuser.
     *
     * @param int $bit the permission bit the caller needs on a group of the user:
     *        {@see DataAccessSecurityService::PERMISSION_CREATE} 1, `PERMISSION_READ` 2,
     *        `PERMISSION_UPDATE` 4 (the default), `PERMISSION_DELETE` 8
     *
     * @throws \InvalidArgumentException when `$bit` is not one of the four permission bits
     */
    public function mayManage(int $callerId, int $userId, int $bit = DataAccessSecurityService::PERMISSION_UPDATE): bool;

    /**
     * The ids of the users `$callerId` manages through a group (update on the group), ascending, in one query.
     * The superuser manages every user. The caller is listed only when they are a member of such a group.
     *
     * @param list<int>|null $groupIds null: every group the caller holds update on; a list: only those groups
     *        (an empty list: none, and so no user)
     * @param int $limit 1 to 10000 ids; more users than that are cut off, lowest ids first
     * @return list<int>
     *
     * @throws \InvalidArgumentException when `$limit` is outside 1 to 10000
     */
    public function managedUserIds(int $callerId, ?array $groupIds = null, int $limit = 1000): array;
}
