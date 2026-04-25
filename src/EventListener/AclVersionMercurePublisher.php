<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Mercure\MercureTopicResolver;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes `acl-changed` Mercure updates whenever a User's `acl_version`
 * column is flushed with a new value.
 *
 * ## Why a Doctrine listener?
 *
 * `User::bumpAclVersion()` is called from many code paths
 * (`AdminUserService`, `AdminGroupService`, `AdminRoleService`, async job
 * executor, profile edits, etc.). Hooking the publish on every caller would
 * be brittle — one missing call and the frontend menu would silently fall
 * out of sync. Listening at the persistence boundary guarantees the wire
 * notification fires exactly when the database state changes, regardless of
 * which service triggered the bump.
 *
 * ## Two-phase publish
 *
 * 1. {@see onFlush()} runs *before* the SQL is sent. We collect every User
 *    update whose changeset includes `acl_version`. We **don't** publish
 *    yet, because the flush could still fail / roll back, and we'd push a
 *    stale event to subscribers.
 *
 * 2. {@see postFlush()} runs *after* the flush completes successfully. At
 *    that point the new `acl_version` is durable and we publish to the
 *    user's private topic. The hub fans out the update to any open
 *    subscriber connection (which the BFF holds on behalf of the browser).
 *
 * If the publish itself throws (hub down, network blip), we log and move on
 * — the frontend's existing `aclVersion` polling-on-focus / 30 s stale
 * window in `useUserData` provides a safety net so the user never gets
 * permanently stuck on stale permissions.
 *
 * ## Relationship to the event stream
 *
 * The matching subscriber JWT for this topic is minted by
 * {@see \App\Controller\Api\V1\Auth\AuthEventsController::events()}, which
 * the frontend BFF calls before opening its upstream connection to the
 * hub. The wire payload is `{ aclVersion: string }` and the SSE event name
 * is `acl-changed` — both consumed by the frontend hook
 * `useAclEventStream`.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AclVersionMercurePublisher
{
    /**
     * Pending publishes keyed by user id. Cleared in {@see postFlush()}.
     *
     * @var array<int, string>
     */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly MercureTopicResolver $topics,
        private readonly LoggerInterface $logger
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof User) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            if (!isset($changeSet['acl_version'])) {
                continue;
            }
            // Doctrine changesets are [oldValue, newValue]. We always
            // re-read the value from the entity itself instead of trusting
            // the changeset slot — the entity has already been updated by
            // bumpAclVersion() at this point and the type is statically
            // known to be ?string.
            $userId = $entity->getId();
            $newVersion = $entity->getAclVersion();
            if ($userId === null || $newVersion === null) {
                continue;
            }
            $this->pending[(int) $userId] = $newVersion;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        $batch = $this->pending;
        $this->pending = [];

        foreach ($batch as $userId => $newVersion) {
            try {
                $payload = json_encode(
                    ['aclVersion' => $newVersion],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                );

                $this->hub->publish(new Update(
                    $this->topics->userAclTopic($userId),
                    $payload,
                    true,
                    null,
                    'acl-changed'
                ));
            } catch (\Throwable $e) {
                $this->logger->error('Failed to publish ACL change to Mercure hub', [
                    'user_id' => $userId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
