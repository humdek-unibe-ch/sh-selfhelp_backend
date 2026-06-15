<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ServiceException;
use App\Service\CMS\UserPermissionService;
use App\Service\System\MaintenanceModeService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Returns a clean `503 Service Unavailable` for normal `/cms-api` traffic while
 * the current instance is in maintenance mode (typically during a
 * Manager-driven update). Runs on `kernel.controller` BEFORE
 * {@see ApiSecurityListener} (priority 20 > 10) so maintenance short-circuits
 * the permission check, and the {@see ApiExceptionListener} formats the thrown
 * {@see ServiceException} into the standard API envelope.
 *
 * A small, explicit allow-list stays reachable during maintenance so operators
 * and the Manager can drive + observe the update:
 *   - the public readiness probe (`health` route);
 *   - the Manager update loop (`manager_*` routes);
 *   - authentication (`/auth/...` — so an admin can still log in);
 *   - system management (`admin.system.*` routes — version/health/update/
 *     maintenance, so maintenance itself can be toggled off again);
 *   - the public `maintenance` page content + the `languages` list it needs,
 *     so visitors get the styled "we're down" page instead of a raw 503
 *     envelope (the whole point of having a maintenance page). Both are
 *     read-only, secret-free reads.
 */
final class MaintenanceModeListener implements EventSubscriberInterface
{
    /** Keyword of the CMS page rendered to visitors while maintenance is on. */
    private const MAINTENANCE_KEYWORD = 'maintenance';

    public function __construct(
        private readonly MaintenanceModeService $maintenanceMode,
        private readonly UserPermissionService $permissionService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 20],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/cms-api/')) {
            return;
        }
        if ($request->getMethod() === 'OPTIONS') {
            return;
        }
        if (!$this->maintenanceMode->isEnabled()) {
            return;
        }

        if ($this->isExempt($request->attributes->get('_route'), $path)) {
            return;
        }

        throw new ServiceException(
            'The instance is undergoing maintenance. Please try again shortly.',
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * The readiness probe, the Manager update loop, authentication, and system
     * management endpoints stay reachable during maintenance.
     */
    private function isExempt(mixed $routeName, string $path): bool
    {
        $route = is_string($routeName) ? $routeName : '';

        if ($route === 'health') {
            return true;
        }
        if (str_starts_with($route, 'manager_')) {
            return true;
        }
        // Authentication (login / refresh / register / 2FA) under any API version.
        if (preg_match('#^/cms-api/v\d+/auth/#', $path) === 1) {
            return true;
        }

        // The public maintenance page itself (and the languages list the SSR
        // render resolves first) must stay reachable, otherwise the visitor
        // sees a bare 503 instead of the styled maintenance page.
        $keyword = preg_quote(self::MAINTENANCE_KEYWORD, '#');
        if (preg_match('#^/cms-api/v\d+/pages/by-keyword/' . $keyword . '$#', $path) === 1) {
            return true;
        }
        if (preg_match('#^/cms-api/v\d+/languages$#', $path) === 1) {
            return true;
        }

        // System management routes (admin.system.read / .update / .maintenance)
        // so an operator can read status and toggle maintenance back off.
        if ($route !== '') {
            foreach ($this->permissionService->getRoutePermissions($route) as $permission) {
                if (str_starts_with($permission, 'admin.system.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
