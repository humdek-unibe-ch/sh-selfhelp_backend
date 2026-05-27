<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base class for plugin lifecycle events. Concrete subclasses signal
 * the specific lifecycle moment; listeners receive both the plugin and
 * the staging operation that triggered the transition.
 *
 * Lifecycle events are dispatched in two places:
 *   - synchronously, immediately after the orchestrator commits the
 *     corresponding state transition, and
 *   - over Mercure (via `PluginRealtimePublisher`) so admin UIs update
 *     instantly without polling.
 */
abstract class PluginLifecycleEvent extends Event
{
    public function __construct(
        public readonly Plugin $plugin,
        public readonly ?PluginOperation $operation,
    ) {
    }
}
