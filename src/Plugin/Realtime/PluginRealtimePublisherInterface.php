<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Realtime;

/**
 * Public contract every plugin bundle uses to publish realtime updates.
 *
 * Plugins MUST NOT instantiate Mercure clients directly. The host
 * implementation (`PluginRealtimePublisher`) handles topic resolution,
 * audience scoping, payload validation, and rate limiting.
 *
 * Topics are scoped by plugin id: the runtime IRI looks like
 * `https://selfhelp.app/plugin/{pluginId}/{topicKey}` with optional
 * URL-shaped path parameters (e.g. `/surveys/{surveyId}` for the
 * SurveyJS plugin). The plugin manifest declares topics; the host
 * registers them through `PluginRealtimeTopicRegistryEvent`.
 */
interface PluginRealtimePublisherInterface
{
    /**
     * Publish a payload to a topic registered by the plugin.
     *
     * @param string $pluginId
     * @param string $topicKey  Topic key as declared in the manifest.
     * @param array<string,mixed> $payload Will be JSON-encoded; must
     *                                      pass the topic's validator
     *                                      when one is registered.
     * @param array{
     *   audience?: 'permission'|'broadcast'|'admins',
     *   topicParams?: array<string,string|int>,
     *   event?: string,
     *   private?: bool,
     * } $options
     */
    public function publish(string $pluginId, string $topicKey, array $payload, array $options = []): void;

    /**
     * Resolve the Mercure topic IRI for a given plugin topic + params.
     * Used by the auth-events controller when minting subscriber JWTs.
     *
     * @param array<string,string|int> $topicParams
     */
    public function resolveTopicIri(string $pluginId, string $topicKey, array $topicParams = []): string;
}
