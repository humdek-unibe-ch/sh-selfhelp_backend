<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Sync\AclStampService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Adds `X-Acl-Stamp` to every API response for a signed-in caller (live-sync C5).
 *
 * The stamp is the cheap "did my access change?" check: a client that sees a stamp
 * different from the one it last read from `/auth/user-data` refreshes its user
 * data, even if the push notice was lost. Guests get no header. It runs late so
 * the stamp reflects the access state AFTER the request's own writes.
 */
final class AclStampResponseListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AclStampService $stamps,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -20]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/cms-api/')) {
            return;
        }

        // The token storage, not UserContextService: that one memoizes its answer for the life of the
        // service, and asking it here (late, on every response) must never fix that answer for a later
        // request served by the same kernel.
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        $event->getResponse()->headers->set(AclStampService::HEADER, $this->stamps->stampFor($user));
    }
}
