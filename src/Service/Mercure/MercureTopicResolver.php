<?php

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
}
