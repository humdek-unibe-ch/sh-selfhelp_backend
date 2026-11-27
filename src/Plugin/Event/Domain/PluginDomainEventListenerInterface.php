<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event\Domain;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A plugin's listener for the read-only domain events of contract C24
 * (`record.saved`, `record.deleted`, `user.created`, `user.deleted`,
 * `page.published`). A service that implements it is tagged
 * `selfhelp.plugin.domain_event_listener` automatically.
 *
 * {@see PluginDomainEventDispatcher} calls {@see handle()} once per change,
 * after the commit, and only when the plugin is enabled and its manifest
 * `listens` names the event. `handle()` receives one of the five event
 * classes of this namespace and ignores the others (a listener of several
 * events checks the class).
 *
 * What a listener may do: queue jobs, call
 * {@see \App\Plugin\Resource\PluginResourceServiceInterface::changed()}, or
 * send notices. It may not write data directly; this is documented and
 * checked in review, nothing in the code enforces it. A listener that throws
 * is logged and changes neither the write that raised the event nor the other
 * listeners.
 */
#[AutoconfigureTag(PluginDomainEventListenerInterface::TAG)]
interface PluginDomainEventListenerInterface
{
    /** The container tag the dispatcher collects. */
    public const TAG = 'selfhelp.plugin.domain_event_listener';

    /** The manifest id of the plugin this listener belongs to. */
    public function pluginId(): string;

    /**
     * @param object $event a {@see RecordSavedEvent}, {@see RecordDeletedEvent}, {@see UserCreatedEvent},
     *        {@see UserDeletedEvent} or {@see PagePublishedEvent}
     */
    public function handle(object $event): void;
}
