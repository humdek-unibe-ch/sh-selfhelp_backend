<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\System;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP implementation of {@see SystemRegistryGatewayInterface}.
 *
 * Reads the one official unified registry (`registry.json` + signed
 * `releases/core/*.json`). Connected installs only; every failure mode degrades
 * to `null` so the CMS never blocks on registry availability.
 */
class HttpSystemRegistryGateway implements SystemRegistryGatewayInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $registryUrl,
        private readonly int $timeoutSeconds = 8,
    ) {
    }

    public function fetchIndex(): ?array
    {
        return $this->getJson($this->registryUrl);
    }

    public function fetchCoreRelease(string $version): ?array
    {
        $index = $this->fetchIndex();
        if ($index === null) {
            return null;
        }

        $core = $index['core'] ?? null;
        if (!is_array($core)) {
            return null;
        }

        $releaseUrl = null;
        foreach ($core as $entry) {
            if (is_array($entry) && ($entry['version'] ?? null) === $version && isset($entry['releaseUrl']) && is_string($entry['releaseUrl'])) {
                $releaseUrl = $entry['releaseUrl'];
                break;
            }
        }
        if ($releaseUrl === null) {
            return null;
        }

        return $this->getJson($this->resolveUrl($releaseUrl));
    }

    public function fetchAdvisories(): ?array
    {
        $index = $this->fetchIndex();
        if ($index === null) {
            return null;
        }

        $advisoriesUrl = $index['advisoriesUrl'] ?? null;
        if (!is_string($advisoriesUrl) || $advisoriesUrl === '') {
            return null;
        }

        return $this->getJson($this->resolveUrl($advisoriesUrl));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getJson(string $url): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => $this->timeoutSeconds,
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            /** @var array<string,mixed> $decoded */
            $decoded = $response->toArray(false);

            return $decoded;
        } catch (\Throwable $e) {
            $this->logger->info('System registry fetch failed; degrading preflight to offline mode.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resolve a possibly-relative release URL against the registry index URL.
     */
    private function resolveUrl(string $maybeRelative): string
    {
        if (str_starts_with($maybeRelative, 'http://') || str_starts_with($maybeRelative, 'https://')) {
            return $maybeRelative;
        }
        $base = $this->registryUrl;
        // Drop the trailing `registry.json` (or any final path segment) to get the dir.
        $slash = strrpos($base, '/');
        $dir = $slash === false ? $base : substr($base, 0, $slash + 1);

        return $dir . ltrim($maybeRelative, '/');
    }
}
