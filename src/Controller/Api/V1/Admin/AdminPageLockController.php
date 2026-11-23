<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Controller\Api\V1\Admin;

use App\Entity\User;
use App\Exception\ServiceException;
use App\Service\CMS\Admin\PageEditLockService;
use App\Service\Core\ApiResponseFormatter;
use App\Service\Core\UserContextAwareService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The edit lock of a page (live-sync contract C9): who is editing it now.
 *
 * Routes: status, acquire or renew, release, take over. Acquire, release and
 * take-over need `admin.page.update` plus the update grant on the page. Status
 * (`GET`) also opens for callers with `admin.page.delete`,
 * `admin.page_version.publish` or `admin.page_version.delete` who hold the
 * update or delete grant on the page (read-only). A caller who may not see the
 * lock gets the normal 403 or 404. The draft writes themselves are guarded by
 * {@see \App\EventListener\PageDraftWriteGuardListener}.
 */
class AdminPageLockController extends AbstractController
{
    public function __construct(
        private readonly PageEditLockService $locks,
        private readonly UserContextAwareService $userContext,
        private readonly ApiResponseFormatter $responseFormatter,
    ) {
    }

    /**
     * @route /admin/pages/{page_id}/lock
     * @method GET
     */
    public function getLock(int $page_id): JsonResponse
    {
        return $this->respond($page_id, fn (User $user): array => $this->locks->status($page_id, (int) $user->getId()), true);
    }

    /**
     * Acquire the lock, or renew it when it is the caller's; 409 `page_locked` when someone else holds it;
     * 409 `lock_idle_ended` when the caller's own lease ended by idle.
     *
     * @route /admin/pages/{page_id}/lock
     * @method POST
     */
    public function acquireLock(int $page_id): JsonResponse
    {
        return $this->respond($page_id, fn (User $user): array => $this->locks->acquire($page_id, $user));
    }

    /**
     * Release the lock when it is the caller's; nothing happens otherwise.
     *
     * @route /admin/pages/{page_id}/lock
     * @method DELETE
     */
    public function releaseLock(int $page_id): JsonResponse
    {
        return $this->respond($page_id, fn (User $user): array => $this->locks->release($page_id, (int) $user->getId()));
    }

    /**
     * Become the holder, whoever held the lock.
     *
     * @route /admin/pages/{page_id}/lock/take-over
     * @method POST
     */
    public function takeOverLock(int $page_id): JsonResponse
    {
        return $this->respond($page_id, fn (User $user): array => $this->locks->takeOver($page_id, $user));
    }

    /**
     * @param callable(User): array<string, mixed> $action
     */
    private function respond(int $pageId, callable $action, bool $readOnly = false): JsonResponse
    {
        try {
            // 404 for a page that does not exist. Update grant for writes; update or delete for GET.
            if ($readOnly) {
                $this->assertMayReadLock($pageId);
            } else {
                $this->userContext->checkAdminAccessById($pageId, 'update');
            }
            $user = $this->userContext->getCurrentUser();
            if (!$user instanceof User) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            return $this->responseFormatter->formatSuccess($action($user), 'responses/admin/pages/page_lock');
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        }
    }

    /**
     * Accept the update grant, or else the delete grant (for delete-only callers of GET).
     *
     * @throws ServiceException 404 when the page is missing, 403 when neither grant is held
     */
    private function assertMayReadLock(int $pageId): void
    {
        try {
            $this->userContext->checkAdminAccessById($pageId, 'update');
        } catch (ServiceException $e) {
            if ($e->getCode() !== Response::HTTP_FORBIDDEN) {
                throw $e;
            }
            $this->userContext->checkAdminAccessById($pageId, 'delete');
        }
    }
}
