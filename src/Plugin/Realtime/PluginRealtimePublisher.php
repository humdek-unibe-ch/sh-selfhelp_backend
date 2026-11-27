<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Realtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Host implementation of `PluginRealtimePublisherInterface`. Wraps the
 * existing Mercure infrastructure so plugins never touch
 * `HubInterface` directly.
 *
 * Topic IRIs follow the convention:
 *
 *   `<topicPrefix>/plugin/<pluginId>/<topicKey>[/<paramKey>/<paramValue>]*`
 *
 * The order of `topicParams` is preserved so admin UIs can subscribe
 * to wildcards. Example for SurveyJS:
 *
 *   key: `survey-response`
 *   topicParams: `['survey' => 123]`
 *   → `https://selfhelp.app/plugin/sh2-shp-survey-js/survey-response/survey/123`
 *
 * Audience `user` (contract C18): the update goes to one user. The caller passes
 * `topicParams: ['userId' => <positive id>]` and nothing else; the topic is
 * `…/<topicKey>/userId/<id>`, always private, and
 * {@see \App\EventListener\PluginUserTopicGrant} puts exactly that topic into the
 * subscriber token of the user with that id. A call without a positive `userId`
 * is a programming error and throws {@see \InvalidArgumentException}. The
 * parameter name `userId` is reserved for this audience, so no other audience
 * can publish on a topic that a user is granted by their id.
 *
 * Failures publishing to the hub are logged but never thrown to the
 * caller — realtime updates are best-effort. The dedicated frontend
 * "Realtime unavailable" banner handles the user-facing fallback.
 */
final class PluginRealtimePublisher implements PluginRealtimePublisherInterface
{
    /** Audience of one user; its topic parameter is {@see self::USER_PARAM}. */
    public const AUDIENCE_USER = 'user';

    /** The topic parameter that names the user of an audience-`user` topic. */
    public const USER_PARAM = 'userId';

    public function __construct(
        private readonly HubInterface $hub,
        private readonly string $topicPrefix,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(string $pluginId, string $topicKey, array $payload, array $options = []): void
    {
        $topicParams = $options['topicParams'] ?? [];
        $private = !array_key_exists('private', $options) || (bool) $options['private'];

        if (($options['audience'] ?? null) === self::AUDIENCE_USER) {
            $topicParams = [self::USER_PARAM => $this->userIdOf($topicParams)];
            // A public update would reach anybody who subscribes to the topic.
            $private = true;
        } elseif (array_key_exists(self::USER_PARAM, $topicParams)) {
            throw new \InvalidArgumentException(sprintf('The topic parameter "%s" is reserved for the audience "%s".', self::USER_PARAM, self::AUDIENCE_USER));
        }

        $iri = $this->resolveTopicIri($pluginId, $topicKey, $topicParams);
        $event = isset($options['event']) ? (string) $options['event'] : $topicKey;

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->hub->publish(new Update($iri, $json, $private, null, $event));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to publish plugin realtime update', [
                'plugin_id' => $pluginId,
                'topic_key' => $topicKey,
                'topic_iri' => $iri,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function resolveTopicIri(string $pluginId, string $topicKey, array $topicParams = []): string
    {
        $iri = rtrim($this->topicPrefix, '/') . '/plugin/' . $this->urlSafe($pluginId) . '/' . $this->urlSafe($topicKey);
        foreach ($topicParams as $name => $value) {
            $iri .= '/' . $this->urlSafe((string) $name) . '/' . $this->urlSafe((string) $value);
        }
        return $iri;
    }

    /**
     * The id of the user an audience-`user` update is for.
     *
     * @param array<string, string|int> $topicParams
     *
     * @throws \InvalidArgumentException without a positive `userId`, or with any other topic parameter
     */
    private function userIdOf(array $topicParams): int
    {
        $userId = $topicParams[self::USER_PARAM] ?? null;
        if (is_string($userId) && ctype_digit($userId)) {
            $userId = (int) $userId;
        }
        if (!is_int($userId) || $userId <= 0) {
            throw new \InvalidArgumentException(sprintf('The audience "%s" needs topicParams["%s"] as a positive user id.', self::AUDIENCE_USER, self::USER_PARAM));
        }
        if (count($topicParams) !== 1) {
            throw new \InvalidArgumentException(sprintf('The audience "%s" takes no topic parameter besides "%s".', self::AUDIENCE_USER, self::USER_PARAM));
        }

        return $userId;
    }

    private function urlSafe(string $segment): string
    {
        return rawurlencode($segment);
    }
}
