<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Event;

use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when the `AuthEventsController` is minting a Mercure
 * subscriber JWT for a user. Plugin listeners decide which plugin
 * realtime topics the JWT may subscribe to.
 *
 * Listener contract:
 *   - inspect `$this->user`,
 *   - call `allowTopic($iri)` to grant subscription for a topic IRI,
 *   - do NOT call `denyTopic` unless explicitly revoking another
 *     listener's grant (rare).
 *
 * The host enforces "deny by default": no listener grant = no
 * subscription.
 */
final class PluginRealtimePermissionEvent extends Event
{
    /** @var array<int,string> */
    private array $allowedTopicIris = [];

    /** @var array<int,string> */
    private array $deniedTopicIris = [];

    public function __construct(public readonly User $user)
    {
    }

    public function allowTopic(string $topicIri): void
    {
        if (!in_array($topicIri, $this->allowedTopicIris, true)) {
            $this->allowedTopicIris[] = $topicIri;
        }
    }

    public function denyTopic(string $topicIri): void
    {
        if (!in_array($topicIri, $this->deniedTopicIris, true)) {
            $this->deniedTopicIris[] = $topicIri;
        }
    }

    /** @return array<int,string> */
    public function getAllowedTopicIris(): array
    {
        return array_values(array_diff($this->allowedTopicIris, $this->deniedTopicIris));
    }
}
