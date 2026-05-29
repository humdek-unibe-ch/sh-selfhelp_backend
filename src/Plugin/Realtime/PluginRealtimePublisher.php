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
 * Failures publishing to the hub are logged but never thrown to the
 * caller — realtime updates are best-effort. The dedicated frontend
 * "Realtime unavailable" banner handles the user-facing fallback.
 */
final class PluginRealtimePublisher implements PluginRealtimePublisherInterface
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly string $topicPrefix,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(string $pluginId, string $topicKey, array $payload, array $options = []): void
    {
        $topicParams = $options['topicParams'] ?? [];
        $iri = $this->resolveTopicIri($pluginId, $topicKey, $topicParams);
        $event = isset($options['event']) ? (string) $options['event'] : $topicKey;
        $private = !array_key_exists('private', $options) || (bool) $options['private'];

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

    private function urlSafe(string $segment): string
    {
        return rawurlencode($segment);
    }
}
