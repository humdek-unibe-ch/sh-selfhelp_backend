<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event\Lifecycle;

use App\Entity\Plugin\PluginOperation;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched whenever the plugin manager wants to surface progress for
 * a running plugin operation. The `PluginRealtimePublisher` listens and
 * pushes the update over Mercure, replacing all polling on the admin
 * UI.
 */
final class PluginOperationProgressEvent extends Event
{
    public function __construct(
        public readonly PluginOperation $operation,
        /** Optional human progress label, e.g. "Running migrations". */
        public readonly ?string $stage = null,
        /** Optional integer percentage (0..100). */
        public readonly ?int $percent = null,
    ) {
    }
}
