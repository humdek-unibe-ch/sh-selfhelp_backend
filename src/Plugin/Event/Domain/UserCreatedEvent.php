<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event\Domain;

/**
 * A user account was created (domain event `user.created`, contract C24).
 * Delivered once per change, after the commit; read-only, see
 * {@see RecordSavedEvent}.
 */
final class UserCreatedEvent
{
    public function __construct(public readonly int $userId)
    {
    }
}
