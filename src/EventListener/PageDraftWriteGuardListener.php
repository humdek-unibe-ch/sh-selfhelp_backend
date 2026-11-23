<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\PageDraftVersionService;
use App\Service\CMS\Admin\PageDraftWriteRoutes;
use App\Service\CMS\Admin\PageEditLockService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Sync\ChangeNoticeService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Guards every draft write of a page (live-sync contracts C8 and C9).
 *
 * BEFORE the controller, for the routes in {@see PageDraftWriteRoutes}:
 *
 *  1. The page lock (C9). Someone else holds it: `409 page_locked`, with the status
 *     of the lock as data. Nobody holds it: the write takes it.
 *  2. The version (C8). `X-Page-Version` missing or not a positive integer:
 *     `400 page_version_required`. Not the current version: `409 stale_page_version`
 *     with `{page_id, current_version}`. Otherwise the version is CLAIMED with one
 *     conditional UPDATE, so of two writes based on the same version exactly one runs.
 *
 * AFTER the controller: a 2xx answer carries `X-Page-Version` with the new version,
 * counts as the holder's activity for the idle end, and sends `page_draft:<id>` to
 * the page's editors. Any other answer gives the claim back (and a lock the request
 * took), so a failed write changes neither the version nor the lock.
 *
 * The lock check runs before the version check, as the contract says. A caller who
 * may NOT edit the page (no route right, no update grant, no such page) is not
 * guarded here: the controller's own checks answer 403 or 404, so neither the lock
 * nor the version of a page leaks to someone who may not touch it.
 */
final class PageDraftWriteGuardListener implements EventSubscriberInterface
{
    private const ATTRIBUTE = '_page_draft_guard';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly PageEditLockService $locks,
        private readonly PageDraftVersionService $versions,
        private readonly ChangeNoticeService $notices,
        private readonly ApiResponseFormatter $formatter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the route permission check (kernel.request), before the controller reads the body.
            KernelEvents::CONTROLLER => 'onController',
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // The router names a route `<api_routes.route_name>_<version>`; the list holds the stored name.
        $routeName = $request->attributes->get('_route');
        $route = is_string($routeName) ? preg_replace('/_v[0-9]+$/', '', $routeName) : null;
        if (!is_string($route) || !in_array($route, PageDraftWriteRoutes::NAMES, true)) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        $pageId = $request->attributes->get('page_id');
        if (!$user instanceof User || !is_numeric($pageId)) {
            return;
        }
        $pageId = (int) $pageId;
        $userId = (int) $user->getId();

        $current = $this->versions->current($pageId);
        if ($current === null || !$this->locks->callerMayEdit($userId, $pageId)) {
            return; // the controller answers 404 / 403 as it always did
        }

        try {
            $this->locks->assertNotHeldByOther($pageId, $userId);
        } catch (ServiceException $e) {
            $this->refuse($event, $this->formatter->formatException($e));

            return;
        }

        $expected = $this->sentVersion($request->headers->get(PageDraftVersionService::HEADER));
        if ($expected === null) {
            $this->refuse($event, $this->formatter->formatError(PageDraftVersionService::ERROR_REQUIRED, Response::HTTP_BAD_REQUEST));

            return;
        }

        try {
            $tookLock = $this->locks->assertMayWrite($pageId, $user);
        } catch (ServiceException $e) { // someone took it between the two steps
            $this->refuse($event, $this->formatter->formatException($e));

            return;
        }

        if (!$this->versions->claim($pageId, $expected)) {
            if ($tookLock) {
                $this->locks->release($pageId, $userId);
            }
            $this->refuse($event, $this->formatter->formatError(
                PageDraftVersionService::ERROR_STALE,
                Response::HTTP_CONFLICT,
                ['page_id' => $pageId, 'current_version' => $this->versions->current($pageId) ?? $current],
            ));

            return;
        }

        $request->attributes->set(self::ATTRIBUTE, [
            'route' => $route,
            'page_id' => $pageId,
            'user_id' => $userId,
            'expected' => $expected,
            'took_lock' => $tookLock,
        ]);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $guard = $request->attributes->get(self::ATTRIBUTE);
        if (!is_array($guard)) {
            return;
        }
        $request->attributes->remove(self::ATTRIBUTE);

        $pageId = $guard['page_id'];
        $userId = $guard['user_id'];
        $expected = $guard['expected'];
        if (!is_int($pageId) || !is_int($userId) || !is_int($expected)) {
            return;
        }

        $response = $event->getResponse();
        if ($response->isSuccessful()) {
            $response->headers->set(PageDraftVersionService::HEADER, (string) ($expected + 1));
            if ($guard['route'] === PageDraftWriteRoutes::DELETE_PAGE) {
                $this->locks->forget($pageId);
            } else {
                $this->locks->recordWrite($pageId, $userId);
                $this->notices->pageDraftChanged($pageId);
            }

            return;
        }

        $this->versions->unclaim($pageId, $expected);
        if ($guard['took_lock'] === true) {
            $this->locks->release($pageId, $userId);
        }
    }

    private function refuse(ControllerEvent $event, Response $response): void
    {
        $event->setController(static fn (): Response => $response);
    }

    /**
     * The version the client sent, or null when the header is missing or not a positive whole number.
     */
    private function sentVersion(?string $header): ?int
    {
        if ($header === null || preg_match('/^[1-9][0-9]{0,9}$/', trim($header)) !== 1) {
            return null;
        }

        return (int) trim($header);
    }
}
