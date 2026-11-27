<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Event\Domain;

use App\Doctrine\AfterCommitConnection;
use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Registry\PluginRegistryService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tells plugin listeners about the five read-only domain events (contract C24).
 *
 * A host service that has just stored a change calls
 * {@see dispatchAfterCommit()} with one of the event classes of this
 * namespace, next to the change notice and the actions' trigger. The
 * dispatcher
 *
 *   - waits until the current transaction has committed (at once when none is
 *     open) and drops the event when it rolls back, so a listener never hears
 *     about a change that was not stored;
 *   - hands the event to the {@see PluginDomainEventListenerInterface}
 *     services of **enabled** plugins whose manifest `listens` names the
 *     event, and to no others;
 *   - catches and logs every exception a listener throws: it changes neither
 *     the write that raised the event nor the other listeners.
 *
 * A listener may queue jobs, call `PluginResourceServiceInterface::changed()`
 * or send notices; it may not write data directly (documented, checked in
 * review). Waiting for the commit is done by {@see AfterCommitConnection}; on
 * any other connection class the event is delivered at once.
 */
final class PluginDomainEventDispatcher
{
    /**
     * @param iterable<PluginDomainEventListenerInterface> $listeners every listener service of every plugin
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly PluginRegistryService $registry,
        #[AutowireIterator(PluginDomainEventListenerInterface::TAG)]
        private readonly iterable $listeners,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param object $event one of {@see RecordSavedEvent}, {@see RecordDeletedEvent}, {@see UserCreatedEvent},
     *        {@see UserDeletedEvent}, {@see PagePublishedEvent}
     * @throws \InvalidArgumentException when `$event` is none of the five (a programming error)
     */
    public function dispatchAfterCommit(object $event): void
    {
        $name = PluginDomainEvents::nameOf($event);
        if ($name === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a plugin domain event.', $event::class));
        }
        if (is_countable($this->listeners) && count($this->listeners) === 0) {
            return;
        }

        $deliver = function () use ($name, $event): void {
            $this->deliver($name, $event);
        };
        if ($this->connection instanceof AfterCommitConnection) {
            $this->connection->afterCommit($deliver);

            return;
        }
        $deliver();
    }

    private function deliver(string $name, object $event): void
    {
        $listening = [];
        try {
            foreach ($this->registry->getEnabled() as $plugin) {
                if (in_array($name, PluginManifest::listensOf($plugin->getManifestJson()), true)) {
                    $listening[$plugin->getPluginId()] = true;
                }
            }
        } catch (\Throwable $e) {
            // Runs inside AfterCommitConnection::commit(): a throw here would turn a committed change into an error
            // and skip the other queued after-commit callbacks.
            $this->logger->error('Reading the enabled plugins failed; a plugin domain event was not delivered. The change is not affected.', [
                'event' => $name,
                'exception' => $e,
            ]);

            return;
        }
        if ($listening === []) {
            return;
        }

        foreach ($this->listeners as $listener) {
            if (!isset($listening[$listener->pluginId()])) {
                continue;
            }
            try {
                $listener->handle($event);
            } catch (\Throwable $e) {
                $this->logger->error('A plugin domain event listener failed; the change and the other listeners are not affected.', [
                    'plugin_id' => $listener->pluginId(),
                    'event' => $name,
                    'listener' => $listener::class,
                    'exception' => $e,
                ]);
            }
        }
    }
}
