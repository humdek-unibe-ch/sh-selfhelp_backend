<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\EventListener;

use App\Service\Auth\JWTService;
use App\Service\Auth\UserContextService;
use App\Service\CMS\UserPermissionService;
use App\Service\Core\LookupService;
use App\Service\Core\TransactionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Listener that runs on `kernel.controller`, AFTER `JWTTokenAuthenticator`
 * has decoded the JWT and stored the payload on the request attributes.
 *
 * It owns three concerns:
 *   1. Permission check  — does the current user have one of the
 *      permissions the matched API route requires?
 *   2. Impersonation guard — block a small allow-list of *high-risk*
 *      mutations (impersonating-the-impersonator, deleting users, role
 *      changes) when the caller is using an impersonation JWT.
 *   3. Impersonation audit — log EVERY mutation performed under an
 *      impersonation JWT so the audit trail attributes the action to
 *      the original admin (`act.sub`), not the target user.
 *
 * The audit-and-allow approach (vs blanket block) matches Salesforce
 * "Login as", Auth0 "View as", GitHub Enterprise impersonation, and
 * Symfony's own `switch_user` — every action by the impersonator is
 * permitted, but every action is also recorded.
 */
class ApiSecurityListener implements EventSubscriberInterface
{
    /**
     * Routes that are NEVER allowed under an impersonation token even
     * though they are normal mutations. These are operations whose blast
     * radius (privilege escalation, account destruction, restarting
     * impersonation chains) is so high we never want them attributed
     * after-the-fact through the audit log alone.
     */
    private const IMPERSONATION_FORBIDDEN_ROUTES = [
        'admin_users_impersonate_v1',
        'admin_users_delete_v1',
        'admin_users_roles_add_v1',
        'admin_users_roles_remove_v1',
        'admin_users_groups_add_v1',
        'admin_users_groups_remove_v1',
        'admin_users_block_v1',
        'admin_users_unblock_v1',
        'admin_users_clean_data_v1',
    ];

    /**
     * The single route that is *always* allowed under an impersonation
     * token, regardless of the standard guards: stop-impersonation.
     */
    private const IMPERSONATION_ALWAYS_ALLOWED_ROUTE = 'admin_users_stop_impersonate_v1';

    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private UserContextService $userContextService,
        private UserPermissionService $permissionService,
        private LoggerInterface $logger,
        private JWTService $jwtService,
        private TransactionService $transactionService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 10],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        // Only check API routes
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/cms-api/')) {
            return;
        }

        // Skip OPTIONS requests (CORS preflight)
        if ($request->getMethod() === 'OPTIONS') {
            return;
        }

        // Impersonation: block forbidden routes and audit every mutation.
        // Runs BEFORE the permission check so we deny consistently and
        // log even if the request would have failed permission anyway.
        $this->handleImpersonation($request);

        try {
            // Get the current route name
            $routeName = $request->attributes->get('_route');
            if (!is_string($routeName) || $routeName === '') {
                // No route matched, skip permission check
                return;
            }

            // Get the required permissions using optimized cache service
            $requiredPermissions = $this->permissionService->getRoutePermissions($routeName);

            if (empty($requiredPermissions)) {
                return;
            }

            // Get the current user using UserContextService
            // At this point authentication has been completed
            $user = $this->userContextService->getCurrentUser();
            if (!$user) {
                throw new AccessDeniedException('User not authenticated.');
            }

            // Get the user's permissions using optimized cache service
            $userPermissions = $this->permissionService->getUserPermissions($user);

            // Check if the user has at least one of the required permissions
            $hasPermission = false;
            foreach ($requiredPermissions as $permission) {
                if (in_array($permission, $userPermissions, true)) {
                    $hasPermission = true;
                    break;
                }
            }

            // If the user doesn't have any of the required permissions, deny access
            if (!$hasPermission) {
                $this->logger->warning('Access denied to API route', [
                    'route' => $routeName,
                    'path' => $path,
                    'requiredPermissions' => $requiredPermissions,
                    'userId' => $user->getId(),
                ]);

                throw new AccessDeniedException('You do not have permission to access this API endpoint.');
            }
        } catch (AccessDeniedException $e) {
            // Let the ApiExceptionListener handle this exception
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Error in API security check', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Let the ApiExceptionListener handle this exception
            throw new AccessDeniedException('An error occurred while checking permissions.', $e);
        }
    }

    /**
     * If the caller is using an impersonation JWT:
     *   - block requests to high-risk routes (`IMPERSONATION_FORBIDDEN_ROUTES`)
     *   - audit-log every mutation against the *original admin* so the
     *     `transactions` table answers "who really did this?"
     *
     * Reads the decoded payload that `JWTTokenAuthenticator` already put
     * on the request attributes — no second JWT decode here.
     */
    private function handleImpersonation(Request $request): void
    {
        $payloadRaw = $request->attributes->get('_jwt_payload');
        if (!is_array($payloadRaw)) {
            return;
        }
        $payload = [];
        foreach ($payloadRaw as $key => $value) {
            $payload[(string) $key] = $value;
        }
        if (!$this->jwtService->isImpersonationPayload($payload)) {
            return;
        }

        $routeRaw = $request->attributes->get('_route');
        $routeName = is_string($routeRaw) ? $routeRaw : '';

        // The stop-impersonation endpoint is always allowed: that's the
        // only sane way out of an impersonation session.
        if ($routeName === self::IMPERSONATION_ALWAYS_ALLOWED_ROUTE) {
            return;
        }

        // High-risk operations remain blocked. Listing them explicitly
        // is safer than substring matching and keeps the deny list
        // reviewable in one place.
        if (in_array($routeName, self::IMPERSONATION_FORBIDDEN_ROUTES, true)) {
            throw new AccessDeniedException(
                'This operation is not allowed while impersonating another user. ' .
                'Stop impersonation first.'
            );
        }

        // Read-only requests need no further treatment.
        if (!in_array($request->getMethod(), self::UNSAFE_METHODS, true)) {
            return;
        }

        $this->auditImpersonatedMutation($request, $payload);
    }

    /**
     * Write a row to `transactions` recording that an impersonator just
     * performed a mutation. Uses the standard TransactionService so the
     * entry shows up next to all other audit data.
     *
     * Failures are swallowed (logged at error level) — we never want to
     * block a legitimate mutation because the audit log itself misbehaved.
     *
     * @param array<string,mixed> $payload
     */
    private function auditImpersonatedMutation(Request $request, array $payload): void
    {
        try {
            $adminUserId  = $this->jwtService->getImpersonatorUserId($payload) ?? 0;
            $targetUserRaw = $payload['id_users'] ?? 0;
            $targetUserId = is_numeric($targetUserRaw) ? (int) $targetUserRaw : 0;
            $routeRaw     = $request->attributes->get('_route');
            $routeName    = is_string($routeRaw) ? $routeRaw : 'unknown';

            $verbal = sprintf(
                'Impersonated mutation: admin_id=%d -> target_id=%d, %s %s (route=%s)',
                $adminUserId,
                $targetUserId,
                $request->getMethod(),
                $request->getPathInfo(),
                $routeName
            );

            $this->transactionService->logTransaction(
                LookupService::TRANSACTION_TYPES_UPDATE,
                LookupService::TRANSACTION_BY_BY_USER,
                'users',
                $adminUserId,
                false,
                $verbal
            );
        } catch (\Throwable $e) {
            $this->logger->error('[ApiSecurityListener] Failed to audit impersonated mutation', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
