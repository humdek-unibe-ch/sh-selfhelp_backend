<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Resource;

use App\Exception\ServiceException;
use App\Service\Security\DataAccessSecurityService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Author-time check for binding a plugin resource into a section or an action
 * (contract C8, H7, F07 for plugin items).
 *
 * A style field or job config property listed in a manifest's
 * `resourceFields` ({@see ResourceFieldMap}) holds the id of one resource of a
 * registered type. An editor may bind it only when their roles hold the
 * **view** grant on that item; the superuser passes. The check runs when the
 * section or action is saved or imported, never when the page is shown: once
 * bound, visitors see the item through the page without a grant of their own.
 *
 * - An empty value clears the binding and passes.
 * - A value that is not a positive whole number, or an item the editor may
 *   not view, is refused with 403 `resource_not_bindable`; nothing is written.
 * - A system actor (no signed-in user, id 0: console, fixtures, jobs) passes
 *   (F07).
 *
 * The section save and import go through
 * {@see \App\Service\CMS\Admin\SectionTableBindingGuard}; any other writer of
 * a `resourceFields` value (an action config) calls {@see assertBindable()}
 * directly.
 */
class ResourceBindingGuard
{
    /** Error code of the 403 answer. */
    public const ERROR_NOT_BINDABLE = 'resource_not_bindable';

    public function __construct(
        private readonly RegisteredResourceTypes $registeredTypes,
        private readonly DataAccessSecurityService $dataAccess,
    ) {
    }

    /**
     * @param string $type a registered resource type code
     * @param mixed $value the value of the resource field, as the editor sent it
     * @param int $userId the editor; 0 or less is a system actor
     * @throws ServiceException 403 `resource_not_bindable`
     * @throws \InvalidArgumentException when no enabled plugin registers `$type` (a programming error)
     */
    public function assertBindable(string $type, mixed $value, int $userId): void
    {
        if ($value === null || (is_string($value) && trim($value) === '') || $userId <= 0) {
            return;
        }
        $this->registeredTypes->assertRegistered($type);

        $text = is_scalar($value) ? trim((string) $value) : '';
        $id = ctype_digit($text) ? (int) $text : 0;
        if ($id <= 0 || !$this->dataAccess->hasPermission($userId, $type, $id, DataAccessSecurityService::PERMISSION_READ)) {
            throw new ServiceException(self::ERROR_NOT_BINDABLE, Response::HTTP_FORBIDDEN, ['type' => $type]);
        }
    }
}
