<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched while an editor saves or imports a section, once for every
 * non-empty field value (H7). A plugin whose field points at one of its own
 * resources (a survey picked in a survey section) checks that the editor may
 * use that resource and refuses with {@see deny()}; the save or import is then
 * refused with 403 before anything is written.
 *
 * The same rule as binding a data table (F07, `SectionTableBindingGuard`):
 * checked when the section is authored, never when it is shown. Not dispatched
 * for system actors (no signed-in user).
 */
final class SectionFieldBindingEvent extends Event
{
    private ?string $denial = null;

    public function __construct(
        public readonly string $fieldName,
        public readonly ?string $fieldTypeName,
        public readonly string $value,
        public readonly int $userId,
    ) {
    }

    /** Refuse the binding; `$reason` is the message the editor sees. */
    public function deny(string $reason): void
    {
        $this->denial ??= $reason;
        $this->stopPropagation();
    }

    public function getDenial(): ?string
    {
        return $this->denial;
    }
}
