<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Users;

use App\Exception\ServiceException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The actor does not manage the user a plugin tried to write for (contract C20):
 * 403 `user_not_managed`, and nothing was written.
 *
 * It extends {@see ServiceException}, so a controller that catches service
 * exceptions and one that lets it bubble to the API exception listener both
 * answer 403 with the error `user_not_managed`.
 */
final class UserNotManagedException extends ServiceException
{
    /** The error text (and so the machine code) of the 403 answer. */
    public const ERROR = 'user_not_managed';

    public function __construct(
        private readonly int $actorUserId,
        private readonly int $ownerUserId,
    ) {
        parent::__construct(self::ERROR, Response::HTTP_FORBIDDEN);
    }

    public function getActorUserId(): int
    {
        return $this->actorUserId;
    }

    public function getOwnerUserId(): int
    {
        return $this->ownerUserId;
    }
}
