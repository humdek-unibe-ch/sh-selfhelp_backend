<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\Mercure;

/**
 * Resolves Mercure topic IRIs for application events.
 *
 * Mercure treats topics as opaque IRIs — they do not need to resolve to a
 * real resource, but the spec strongly recommends URL-shaped identifiers so
 * that wildcard subscriptions (e.g. `https://selfhelp.app/users/{id}/acl`)
 * remain unambiguous.
 *
 * Centralising the topic format here keeps publishers (Doctrine listeners,
 * services) and subscribers (`AuthEventsController` issuing subscriber JWTs)
 * in lock-step. If you ever need to rename the topic scheme, do it here and
 * every emitter / listener follows automatically.
 *
 * The base IRI prefix comes from `%mercure.topic.prefix%`, which is wired in
 * `config/services.yaml` from `MERCURE_TOPIC_PREFIX` (default
 * `https://selfhelp.app`). It is purely a namespace — no DNS lookup ever
 * happens against it.
 */
final class MercureTopicResolver
{
    public function __construct(private readonly string $topicPrefix)
    {
    }

    /**
     * Per-user ACL change topic.
     *
     * Subscribers JWT-scoped to this topic receive `acl-changed` updates the
     * moment {@see \App\Entity\User::bumpAclVersion()} is flushed for that
     * user — see {@see \App\EventListener\AclVersionMercurePublisher}.
     */
    public function userAclTopic(int $userId): string
    {
        return rtrim($this->topicPrefix, '/') . '/users/' . $userId . '/acl';
    }

    /**
     * Per-user impersonation lifecycle topic.
     *
     * Subscribers JWT-scoped to this topic receive `impersonation-status`
     * updates whenever an admin starts or stops impersonating the *target*
     * user. The {@see \App\Service\CMS\Admin\AdminUserService} publishes
     * to it, the BFF subscribes on behalf of the browser, and the React
     * banner reacts in real time without polling.
     *
     * Why the *target* user's id and not the admin's? Because while
     * impersonation is active, the issued JWT authenticates as the target;
     * this is the topic that JWT is allowed to subscribe to. The target's
     * own normal sessions also see the event, which is desirable —
     * "someone is impersonating you" is exactly the kind of transparency
     * we want to surface.
     */
    public function userImpersonationTopic(int $userId): string
    {
        return rtrim($this->topicPrefix, '/') . '/users/' . $userId . '/impersonation';
    }

    /**
     * Per-user system-update progress topic.
     *
     * A CMS admin requests an update for THIS instance; the SelfHelp Manager
     * then drains it and writes back lifecycle states + `steps` + a progress
     * percent (see {@see \App\Entity\System\SystemUpdateOperation}). Each such
     * persistence is published as a `system-update` event to the REQUESTER's
     * topic by {@see \App\EventListener\SystemUpdateMercurePublisher}, so the
     * System Maintenance page tracks the operation live over the existing
     * auth-events SSE connection instead of polling. Scoped to the requester's
     * own user id (like the ACL topic) so the subscriber JWT minted by
     * {@see \App\Controller\Api\V1\Auth\AuthEventsController::events()} only
     * ever exposes that user's own operations.
     */
    public function userSystemUpdateTopic(int $userId): string
    {
        return rtrim($this->topicPrefix, '/') . '/users/' . $userId . '/system-update';
    }

    /**
     * Admin plugin-manager state topic.
     *
     * Single topic published from {@see \App\EventListener\PluginStateMercurePublisher}
     * whenever a plugin lifecycle event (`installed`, `enabled`,
     * `disabled`, `updated`, `uninstalled`, `purged`) is dispatched, or
     * a plugin operation transitions to a terminal status.
     *
     * Subscribers (admin UI) JWT-scoped to this topic invalidate their
     * React Query cache for `['admin-plugins', ...]` keys. The host
     * grants the subscription only to users with the
     * `admin.plugins.manage` permission via
     * {@see \App\EventListener\AdminPluginStateTopicGrant}.
     */
    public function pluginAdminStateTopic(): string
    {
        return rtrim($this->topicPrefix, '/') . '/plugins/state';
    }
}
