<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\CMS\Admin;

use App\Entity\User;
use App\Exception\ServiceException;
use App\Service\Core\LookupService;
use App\Service\Security\CallerHoldingsService;
use App\Service\Security\DataAccessSecurityService;
use App\Service\Sync\ChangeNoticeService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;

/**
 * The edit lock of a page (live-sync contract C9): who is editing it now.
 *
 * A LEASE, not a table: one entry per page in the `cache.page_edit_lock` pool
 * (Redis in production), holding the holder's id and name and three times. It
 * ends 120 s after the last renewal ({@see LEASE_SECONDS}), or 15 minutes after
 * the holder's last draft write, or after acquisition when they wrote nothing
 * ({@see IDLE_SECONDS}). The ends are judged here from the stored times, never
 * from the cache entry's lifetime (which is only the clean-up), so they are
 * exact and testable with a mocked clock.
 *
 * One holder per page. Anyone who may edit the page (`admin.page.update` plus the
 * update grant) may {@see acquire()} a free lock, {@see takeOver()} a held one, or
 * write while nobody holds it (the write then takes it, {@see assertMayWrite()}).
 * A draft write by anyone else is refused with 409 `page_locked`; the status of
 * the lock is the data of that answer, so the editor can say who is editing.
 *
 * Read-modify-write steps run under a short mutex per page so two callers never
 * both believe they took a free lock. Every change of who holds the lock sends
 * `page_lock:<id>` to the page's editors ({@see ChangeNoticeService::pageLockChanged()});
 * a renewal by the holder changes nothing anyone sees and sends nothing.
 * The status is per caller (`mine`) and is never cached by anything else.
 */
final class PageEditLockService
{
    /** The lease ends this many seconds after the last renewal. */
    public const LEASE_SECONDS = 120;

    /** The lease ends this many seconds after the holder's last draft write (or the acquisition). */
    public const IDLE_SECONDS = 900;

    /** The error code (and message) of the 409 answer. */
    public const ERROR_LOCKED = 'page_locked';

    private const KEY_PREFIX = 'page_edit_lock_';
    private const MUTEX_SECONDS = 10.0;

    public function __construct(
        #[Autowire(service: 'cache.page_edit_lock')]
        private readonly CacheItemPoolInterface $leases,
        private readonly LockFactory $lockFactory,
        private readonly ChangeNoticeService $notices,
        private readonly CallerHoldingsService $holdings,
        private readonly LookupService $lookups,
    ) {
    }

    /**
     * May this user edit the page: the route right and the update grant on this page (the superuser passes).
     * Answers without writing an audit row, so a caller who is refused later by the service is not logged twice.
     */
    public function callerMayEdit(int $userId, int $pageId): bool
    {
        $typeId = $this->lookups->getLookupIdByCode(LookupService::RESOURCE_TYPES, LookupService::RESOURCE_TYPES_PAGES);
        if ($typeId === null || $userId <= 0) {
            return false;
        }

        return $this->holdings->holdsPermission($userId, 'admin.page.update')
            && $this->holdings->holdsGrantBits($userId, (int) $typeId, $pageId, DataAccessSecurityService::PERMISSION_UPDATE);
    }

    /**
     * The status of the lock for this caller. A lease that has ended is removed here and its end is announced.
     *
     * @return array<string, mixed>
     */
    public function status(int $pageId, int $callerId): array
    {
        return $this->locked($pageId, fn (): array => $this->statusOf($pageId, $this->liveLease($pageId), $callerId));
    }

    /**
     * Take the lock when it is free, renew it when it is the caller's.
     *
     * @return array<string, mixed> the status
     * @throws ServiceException 409 `page_locked` (status as data) when someone else holds it
     */
    public function acquire(int $pageId, User $user): array
    {
        $userId = (int) $user->getId();

        return $this->locked($pageId, function () use ($pageId, $user, $userId): array {
            $lease = $this->liveLease($pageId);
            if ($lease === null) {
                $lease = $this->take($pageId, $user);
            } elseif ($lease['user_id'] === $userId) {
                $lease['renewed_at'] = time();
                $this->save($pageId, $lease);
            } else {
                throw $this->conflict($pageId, $lease, $userId);
            }

            return $this->statusOf($pageId, $lease, $userId);
        });
    }

    /**
     * Make the caller the holder, whoever held the lock. The previous holder's next draft write is refused.
     *
     * @return array<string, mixed> the status
     */
    public function takeOver(int $pageId, User $user): array
    {
        $userId = (int) $user->getId();

        return $this->locked($pageId, function () use ($pageId, $user, $userId): array {
            $lease = $this->liveLease($pageId);
            if ($lease !== null && $lease['user_id'] === $userId) {
                $lease['renewed_at'] = time();
                $this->save($pageId, $lease);
            } else {
                $lease = $this->take($pageId, $user);
            }

            return $this->statusOf($pageId, $lease, $userId);
        });
    }

    /**
     * Give the lock up when it is the caller's; nothing happens otherwise.
     *
     * @return array<string, mixed> the status after the call
     */
    public function release(int $pageId, int $userId): array
    {
        return $this->locked($pageId, function () use ($pageId, $userId): array {
            $lease = $this->liveLease($pageId);
            if ($lease !== null && $lease['user_id'] === $userId) {
                $this->leases->deleteItem($this->key($pageId));
                $this->notices->pageLockChanged($pageId);
                $lease = null;
            }

            return $this->statusOf($pageId, $lease, $userId);
        });
    }

    /**
     * Refuse (409) when someone else holds the lock. Changes nothing: used before the request is looked at further.
     *
     * @throws ServiceException 409 `page_locked`
     */
    public function assertNotHeldByOther(int $pageId, int $userId): void
    {
        $this->locked($pageId, function () use ($pageId, $userId): void {
            $lease = $this->liveLease($pageId);
            if ($lease !== null && $lease['user_id'] !== $userId) {
                throw $this->conflict($pageId, $lease, $userId);
            }
        });
    }

    /**
     * A draft write may go on when the caller holds the lock or nobody does (the write then takes it).
     *
     * @return bool true when this call took a free lock (the caller gives it back with {@see release()} if the write fails)
     * @throws ServiceException 409 `page_locked` when someone else holds it
     */
    public function assertMayWrite(int $pageId, User $user): bool
    {
        $userId = (int) $user->getId();

        return $this->locked($pageId, function () use ($pageId, $user, $userId): bool {
            $lease = $this->liveLease($pageId);
            if ($lease === null) {
                $this->take($pageId, $user);

                return true;
            }
            if ($lease['user_id'] !== $userId) {
                throw $this->conflict($pageId, $lease, $userId);
            }

            return false;
        });
    }

    /**
     * A draft write of the holder went through: it counts as activity for the idle end.
     */
    public function recordWrite(int $pageId, int $userId): void
    {
        $this->locked($pageId, function () use ($pageId, $userId): void {
            $lease = $this->liveLease($pageId);
            if ($lease !== null && $lease['user_id'] === $userId) {
                $lease['last_edit_at'] = time();
                $this->save($pageId, $lease);
            }
        });
    }

    /**
     * The page is gone: so is its lock.
     */
    public function forget(int $pageId): void
    {
        $this->locked($pageId, function () use ($pageId): void {
            if ($this->leases->hasItem($this->key($pageId))) {
                $this->leases->deleteItem($this->key($pageId));
                $this->notices->pageLockChanged($pageId);
            }
        });
    }

    // -- internals ---------------------------------------------------------------------------------------------------

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function locked(int $pageId, callable $work): mixed
    {
        $mutex = $this->lockFactory->createLock(self::KEY_PREFIX . $pageId, self::MUTEX_SECONDS);
        $mutex->acquire(true);
        try {
            return $work();
        } finally {
            $mutex->release();
        }
    }

    private function key(int $pageId): string
    {
        return self::KEY_PREFIX . $pageId;
    }

    /**
     * @return array{user_id: int, name: string, acquired_at: int, renewed_at: int, last_edit_at: int|null}|null
     */
    private function readLease(int $pageId): ?array
    {
        $item = $this->leases->getItem($this->key($pageId));
        if (!$item->isHit()) {
            return null;
        }
        $raw = $item->get();
        if (!is_array($raw)) {
            return null;
        }
        $userId = $raw['user_id'] ?? null;
        $acquired = $raw['acquired_at'] ?? null;
        $renewed = $raw['renewed_at'] ?? null;
        if (!is_int($userId) || !is_int($acquired) || !is_int($renewed)) {
            return null;
        }
        $name = $raw['name'] ?? '';
        $lastEdit = $raw['last_edit_at'] ?? null;

        return [
            'user_id' => $userId,
            'name' => is_string($name) ? $name : '',
            'acquired_at' => $acquired,
            'renewed_at' => $renewed,
            'last_edit_at' => is_int($lastEdit) ? $lastEdit : null,
        ];
    }

    /**
     * The lease as it is now, or null when there is none or it has ended. An ended lease is removed and announced,
     * so the editors learn that the page is free again (contract C3, "expired on access").
     *
     * @return array{user_id: int, name: string, acquired_at: int, renewed_at: int, last_edit_at: int|null}|null
     */
    private function liveLease(int $pageId): ?array
    {
        $lease = $this->readLease($pageId);
        if ($lease === null) {
            return null;
        }
        if (time() < min($this->leaseEnd($lease), $this->idleEnd($lease))) {
            return $lease;
        }

        $this->leases->deleteItem($this->key($pageId));
        $this->notices->pageLockChanged($pageId);

        return null;
    }

    /**
     * @return array{user_id: int, name: string, acquired_at: int, renewed_at: int, last_edit_at: int|null}
     */
    private function take(int $pageId, User $user): array
    {
        $now = time();
        $lease = [
            'user_id' => (int) $user->getId(),
            'name' => $this->displayName($user),
            'acquired_at' => $now,
            'renewed_at' => $now,
            'last_edit_at' => null,
        ];
        $this->save($pageId, $lease);
        $this->notices->pageLockChanged($pageId);

        return $lease;
    }

    /**
     * @param array{user_id: int, name: string, acquired_at: int, renewed_at: int, last_edit_at: int|null} $lease
     */
    private function save(int $pageId, array $lease): void
    {
        $item = $this->leases->getItem($this->key($pageId));
        $item->set($lease);
        // Clean-up only: the logical ends are checked from the stored times. Longer than the longest possible lease.
        $item->expiresAfter(self::IDLE_SECONDS + 60);
        $this->leases->save($item);
    }

    /**
     * @param array{user_id: int, name: string, acquired_at: int, renewed_at: int, last_edit_at: int|null} $lease
     */
    private function leaseEnd(array $lease): int
    {
        return $lease['renewed_at'] + self::LEASE_SECONDS;
    }

    /**
     * @param array{user_id: int, name: string, acquired_at: int, renewed_at: int, last_edit_at: int|null} $lease
     */
    private function idleEnd(array $lease): int
    {
        return ($lease['last_edit_at'] ?? $lease['acquired_at']) + self::IDLE_SECONDS;
    }

    /**
     * @param array{user_id: int, name: string, acquired_at: int, renewed_at: int, last_edit_at: int|null}|null $lease
     * @return array<string, mixed>
     */
    private function statusOf(int $pageId, ?array $lease, int $callerId): array
    {
        if ($lease === null) {
            return [
                'page_id' => $pageId,
                'held' => false,
                'mine' => false,
                'holder' => null,
                'expires_at' => null,
                'idle_expires_at' => null,
            ];
        }

        return [
            'page_id' => $pageId,
            'held' => true,
            'mine' => $lease['user_id'] === $callerId,
            'holder' => ['id' => $lease['user_id'], 'name' => $lease['name']],
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $this->leaseEnd($lease)),
            'idle_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $this->idleEnd($lease)),
        ];
    }

    /**
     * @param array{user_id: int, name: string, acquired_at: int, renewed_at: int, last_edit_at: int|null} $lease
     */
    private function conflict(int $pageId, array $lease, int $callerId): ServiceException
    {
        return new ServiceException(self::ERROR_LOCKED, Response::HTTP_CONFLICT, $this->statusOf($pageId, $lease, $callerId));
    }

    private function displayName(User $user): string
    {
        foreach ([$user->getName(), $user->getUserName(), $user->getEmail()] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'User ' . (int) $user->getId();
    }
}
