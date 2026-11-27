<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event\Domain;

/**
 * A user account was deleted (domain event `user.deleted`, contract C24).
 * Delivered once per change, after the clean-up commit; the user's rows are
 * gone by then, so a listener only has the id. Read-only, see
 * {@see RecordSavedEvent}.
 */
final class UserDeletedEvent
{
    public function __construct(public readonly int $userId)
    {
    }
}
