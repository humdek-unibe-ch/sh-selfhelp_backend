<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Registry;

use App\Entity\Plugin\PluginSource;
use App\Repository\Plugin\PluginSourceRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves plugin metadata against configured registries.
 *
 * The client walks every enabled `PluginSource` in order. For each
 * source it fetches the registry index, decodes the JSON, and merges
 * the per-plugin metadata into a single result list. Private registries
 * receive their auth secret from the env via
 * `PluginSource::getAuthSecretEnvVar()`.
 *
 * The client never persists registry contents — the caller (typically
 * the installer or `PluginAdminService`) chooses what to cache.
 */
final class RegistryClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PluginSourceRepository $sources,
        private readonly PluginSourceUrlResolver $sourceUrlResolver,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** @var array<string,string> */
        private readonly array $envOverrides = [],
    ) {
    }

    /**
     * Fetch the registry index from every enabled source and combine
     * the results into `pluginId -> sourceName -> entry`.
     *
     * @return array<string, array<string, array<string,mixed>>>
     */
    public function fetchAllIndexes(): array
    {
        $combined = [];
        foreach ($this->sources->findEnabled() as $source) {
            try {
                $index = $this->fetchIndex($source);
            } catch (\Throwable $e) {
                $this->logger->warning('Plugin registry fetch failed', [
                    'source' => $source->getName(),
                    'url' => $this->sourceUrlResolver->resolve($source),
                    'exception' => $e->getMessage(),
                ]);
                continue;
            }
            foreach ($index['plugins'] ?? [] as $entry) {
                if (!is_array($entry) || !isset($entry['id'])) {
                    continue;
                }
                $combined[(string) $entry['id']][$source->getName()] = $entry;
            }
        }
        return $combined;
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchIndex(PluginSource $source): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'SelfHelp-Plugin-Manager/1.0',
        ];

        $authHeader = $source->getAuthHeaderName();
        $envVar = $source->getAuthSecretEnvVar();
        if ($authHeader !== null && $envVar !== null) {
            $secret = $this->envOverrides[$envVar] ?? ($_ENV[$envVar] ?? ($_SERVER[$envVar] ?? null));
            if ($secret === null || $secret === '') {
                throw new \RuntimeException(sprintf(
                    'Plugin source "%s" requires env var "%s" but it is not set.',
                    $source->getName(),
                    $envVar
                ));
            }
            $headers[$authHeader] = $secret;
        }

        $url = $this->resolveIndexUrl($source);
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'timeout' => 15,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(sprintf('Transport error fetching %s: %s', $url, $e->getMessage()), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Registry %s returned HTTP %d.', $url, $status));
        }

        $body = $response->getContent(false);
        // Some static hosts serve UTF-8 JSON files with a BOM. Strip it
        // before decoding so registry fetches remain robust.
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Registry %s returned invalid JSON.', $url));
        }
        return $decoded;
    }

    private function resolveIndexUrl(PluginSource $source): string
    {
        $kind = $source->getKind();
        $url = rtrim($this->sourceUrlResolver->resolve($source), '/');
        return match ($kind) {
            PluginSource::KIND_PUBLIC_REGISTRY,
            PluginSource::KIND_PRIVATE_REGISTRY => $url . '/registry.json',
            // For git/local sources the URL is expected to point directly at the manifest file.
            PluginSource::KIND_GIT,
            PluginSource::KIND_LOCAL => $url,
            default => throw new \RuntimeException(sprintf('Unsupported plugin source kind "%s".', $kind)),
        };
    }
}
