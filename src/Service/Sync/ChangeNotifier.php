<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Service\Sync;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sends change notices (live-sync contract C1/C2/C3).
 *
 * Callers say which topics learn about which tags, after their commit and next
 * to the cache clear. Nothing is sent at once: the notices of a request or job
 * are merged per topic and sent one time by {@see flush()}, which the
 * {@see \App\EventListener\ChangeNoticeFlushListener} calls when the response,
 * the console command or the queue message is done. A request that fails before
 * its commit never reaches a call site, so no notice goes out for a write that
 * did not happen.
 *
 * The notice is the event `changed`, private, with the body
 * `{"v":1,"tags":[...]}`: names and ids, never content. A hub that is down is
 * logged and swallowed; the write already happened and the next API reply of a
 * client corrects its screen.
 *
 * Long-running processes hold one instance for many requests, so {@see reset()}
 * (kernel.reset) empties whatever a failed unit left behind (F136/F211).
 */
final class ChangeNotifier implements ResetInterface
{
    public const EVENT_NAME = 'changed';
    public const VERSION = 1;

    /** @var array<string, array<string, true>> topic => set of tags */
    private array $pending = [];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $topics
     * @param list<string> $tags tags of {@see SyncTag}
     */
    public function notify(array $topics, array $tags): void
    {
        $tags = array_values(array_filter(
            array_unique($tags),
            static fn (string $tag): bool => SyncTag::isValid($tag),
        ));
        if ($tags === []) {
            return;
        }

        foreach (array_unique($topics) as $topic) {
            if ($topic === '') {
                continue;
            }
            foreach ($tags as $tag) {
                $this->pending[$topic][$tag] = true;
            }
        }
    }

    /**
     * What waits to be sent: topic => sorted tags. For tests and diagnostics.
     *
     * @return array<string, list<string>>
     */
    public function pending(): array
    {
        $out = [];
        foreach ($this->pending as $topic => $tags) {
            $names = array_keys($tags);
            sort($names);
            $out[$topic] = $names;
        }

        return $out;
    }

    /**
     * Send every merged notice once and forget it.
     */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $batch = $this->pending();
        $this->pending = [];

        foreach ($batch as $topic => $tags) {
            try {
                $this->hub->publish(new Update(
                    $topic,
                    json_encode(['v' => self::VERSION, 'tags' => $tags], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    true,
                    null,
                    self::EVENT_NAME,
                ));
            } catch (\Throwable $e) {
                $this->logger->error('Failed to publish a change notice to the Mercure hub', [
                    'topic' => $topic,
                    'tags' => $tags,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
