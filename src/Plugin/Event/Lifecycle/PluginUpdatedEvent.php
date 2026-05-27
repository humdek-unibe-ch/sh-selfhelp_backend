<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event\Lifecycle;

use App\Entity\Plugin\Plugin;
use App\Entity\Plugin\PluginOperation;

final class PluginUpdatedEvent extends PluginLifecycleEvent
{
    public function __construct(
        Plugin $plugin,
        ?PluginOperation $operation,
        public readonly ?string $fromVersion,
        public readonly string $toVersion,
    ) {
        parent::__construct($plugin, $operation);
    }
}
