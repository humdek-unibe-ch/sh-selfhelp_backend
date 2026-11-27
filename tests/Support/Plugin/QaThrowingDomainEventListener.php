<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Plugin\Event\Domain\PluginDomainEventListenerInterface;

/**
 * C24: the listener of a second QA plugin that always fails. It proves a
 * listener that throws is logged and changes neither the write that raised the
 * event nor the other listeners ({@see QaDomainEventListener}).
 *
 * It belongs to its own plugin id, so it is only reached in a test that installs
 * and enables `qa-throwing-plugin`.
 */
final class QaThrowingDomainEventListener implements PluginDomainEventListenerInterface
{
    public const PLUGIN_ID = 'qa-throwing-plugin';
    public const MESSAGE = 'qa listener failure';

    /** @var int how often the dispatcher called it */
    public int $calls = 0;

    public function pluginId(): string
    {
        return self::PLUGIN_ID;
    }

    public function handle(object $event): void
    {
        ++$this->calls;

        throw new \RuntimeException(self::MESSAGE);
    }
}
