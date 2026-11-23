<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Service\Core\LookupService;
use App\Service\Mercure\MercureTopicResolver;

/**
 * The one place that decides who gets which change notice (live-sync C3).
 *
 * A service that writes calls ONE method here after its commit, next to the
 * cache clear it already does (rule 3: write, clear and notice belong together).
 * The method names the change; this class picks the tags and the topics and
 * hands them to the {@see ChangeNotifier}, which merges and sends them once.
 *
 * A notice says what changed, never its content (C1). Groups see, roles edit:
 * viewers are reached through groups (and `public`), editors through roles, own
 * records through the owner's user topic.
 */
final class ChangeNoticeService
{
    public function __construct(
        private readonly ChangeNotifier $notifier,
        private readonly SyncAudienceResolver $audience,
        private readonly MercureTopicResolver $topics,
        private readonly PageTableReadIndex $tableReads,
    ) {
    }

    /** One user's groups or roles changed: their own ACL. */
    public function userAclChanged(int $userId): void
    {
        $this->notifier->notify([$this->topics->syncUserTopic($userId)], [SyncTag::ACL]);
    }

    /**
     * A group's page ACL changed: its members' access, their menu and the pages
     * whose visibility changed.
     *
     * @param list<int> $changedPageIds pages added to or removed from the group's visibility
     */
    public function groupAclChanged(int $groupId, array $changedPageIds = []): void
    {
        $tags = [SyncTag::ACL, SyncTag::NAVIGATION];
        foreach ($changedPageIds as $pageId) {
            $tags[] = SyncTag::page($pageId);
        }
        $this->notifier->notify([$this->topics->syncGroupTopic($groupId)], $tags);
    }

    /** A role's permissions or data-access grants changed: what its holders may do. */
    public function roleAclChanged(int $roleId): void
    {
        $this->notifier->notify([$this->topics->syncRoleTopic($roleId)], [SyncTag::ACL]);
    }

    /** A page was published, unpublished or a version was published. */
    public function pagePublishedChanged(int $pageId): void
    {
        $this->tableReads->invalidate();
        $this->notifier->notify(
            array_merge(
                $this->audience->pageViewerTopics($pageId),
                $this->audience->editorTopics(LookupService::RESOURCE_TYPES_PAGES, $pageId),
            ),
            [SyncTag::page($pageId), SyncTag::NAVIGATION],
        );
    }

    /** The draft of a page was written. Only editors see a draft. */
    public function pageDraftChanged(int $pageId): void
    {
        $this->tableReads->invalidate();
        $this->notifier->notify(
            $this->audience->editorTopics(LookupService::RESOURCE_TYPES_PAGES, $pageId),
            [SyncTag::pageDraft($pageId)],
        );
    }

    /**
     * A section was saved in the admin (F56): its details and the draft of every
     * page that holds it.
     *
     * @param list<int> $pageIds the pages that contain the section
     */
    public function sectionSaved(int $sectionId, array $pageIds): void
    {
        $this->tableReads->invalidate();
        // Per page, so an editor of one page never learns that another page's draft changed.
        foreach ($pageIds as $pageId) {
            $this->notifier->notify(
                $this->audience->editorTopics(LookupService::RESOURCE_TYPES_PAGES, $pageId),
                [SyncTag::section($sectionId), SyncTag::pageDraft($pageId)],
            );
        }
    }

    /** The edit lock of a page changed hands, was renewed after expiry or released. */
    public function pageLockChanged(int $pageId): void
    {
        $this->notifier->notify(
            $this->audience->editorTopics(LookupService::RESOURCE_TYPES_PAGES, $pageId),
            [SyncTag::pageLock($pageId)],
        );
    }

    /** A record of a data table was saved, deleted, restored or changed by a quick action. */
    public function tableChanged(int $tableId, ?int $ownerUserId): void
    {
        $this->notifier->notify($this->audience->tableTopics($tableId, $ownerUserId), [SyncTag::table($tableId)]);
    }

    /** The navigation (menu) changed for everybody. */
    public function navigationChanged(): void
    {
        $this->notifier->notify([$this->topics->syncPublicTopic()], [SyncTag::NAVIGATION]);
    }

    /** The global values (`{{globals.*}}`) changed for everybody. */
    public function globalValuesChanged(): void
    {
        $this->notifier->notify([$this->topics->syncPublicTopic()], [SyncTag::GLOBAL_VALUES]);
    }

    /** An asset was changed or removed (F121): the people who manage it. */
    public function assetChanged(int $assetId): void
    {
        $this->notifier->notify(
            $this->audience->editorTopics(LookupService::RESOURCE_TYPES_ASSET, $assetId),
            [SyncTag::asset($assetId)],
        );
    }
}
