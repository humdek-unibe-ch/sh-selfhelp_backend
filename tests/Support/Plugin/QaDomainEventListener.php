<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Support\Plugin;

use App\Plugin\Event\Domain\PluginDomainEventListenerInterface;

/**
 * C24: the listener of the QA items plugin ({@see QaItemPluginFixture}). It
 * keeps every event it is handed, so a test can prove who gets which event and
 * when.
 *
 * `autoconfigure` tags it `selfhelp.plugin.domain_event_listener` (declared on
 * the interface), which puts it into the real
 * {@see \App\Plugin\Event\Domain\PluginDomainEventDispatcher}.
 */
final class QaDomainEventListener implements PluginDomainEventListenerInterface
{
    /** @var list<object> the events handled so far, in order */
    public array $handled = [];

    public function pluginId(): string
    {
        return QaItemPluginFixture::PLUGIN_ID;
    }

    public function handle(object $event): void
    {
        $this->handled[] = $event;
    }
}
