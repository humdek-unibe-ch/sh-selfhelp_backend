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
 * Routes (permission `admin.page.update` plus the update grant on the page; the
 * superuser passes): status, acquire or renew, release, take over. All answer the
 * status object of {@see PageEditLockService}; a caller who may not edit the page
 * gets the normal 403 or 404. The draft writes themselves are guarded by
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
        return $this->respond($page_id, fn (User $user): array => $this->locks->status($page_id, (int) $user->getId()));
    }

    /**
     * Acquire the lock, or renew it when it is the caller's; 409 `page_locked` when someone else holds it.
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
    private function respond(int $pageId, callable $action): JsonResponse
    {
        try {
            // 404 for a page that does not exist, 403 without the update grant: the same answer as every draft write.
            $this->userContext->checkAdminAccessById($pageId, 'update');
            $user = $this->userContext->getCurrentUser();
            if (!$user instanceof User) {
                return $this->responseFormatter->formatError('User not authenticated', Response::HTTP_UNAUTHORIZED);
            }

            return $this->responseFormatter->formatSuccess($action($user), 'responses/admin/pages/page_lock');
        } catch (ServiceException $e) {
            return $this->responseFormatter->formatException($e);
        }
    }
}
