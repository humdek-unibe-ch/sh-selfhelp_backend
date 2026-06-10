<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\System;

use App\Plugin\Registry\Unified\CoreRelease;
use App\Plugin\Registry\Unified\UnifiedRegistryClient;
use Psr\Log\LoggerInterface;

/**
 * The ONE registry reader the instance-scoped system layer uses for its
 * advisory update preflight, registry-reachability health, and advisory feed.
 *
 * It is a thin, fail-soft adapter over the SAME signed {@see UnifiedRegistryClient}
 * the plugin install/Available flow uses, so there is a single registry HTTP +
 * Ed25519-verification path on the backend (the previous unsigned
 * `HttpSystemRegistryGateway` is gone). Core release metadata used by the
 * preflight is therefore signature-verified before the operator ever sees it; a
 * tampered or unsigned core release degrades to `null` (the SelfHelp Manager
 * remains the final verifier that re-checks signatures + image digests before
 * pulling anything).
 *
 * Every method returns null / false on ANY failure so the CMS never blocks on
 * registry availability (an existing instance must keep running through a
 * registry outage).
 */
class SystemRegistryReader
{
    public function __construct(
        private readonly UnifiedRegistryClient $client,
        private readonly LoggerInterface $logger,
        private readonly string $registryUrl,
    ) {
    }

    /**
     * Fetch the signed, signature-verified core release document for a version,
     * or null when the registry is unreachable, the version is not published, or
     * the signature does not verify.
     */
    public function getCoreRelease(string $version): ?CoreRelease
    {
        try {
            $index = $this->client->fetchIndex($this->registryUrl);
            foreach ($index->coreRefsSorted() as $ref) {
                if ($ref->version === $version) {
                    return $this->client->fetchCoreRelease($index->resolveUrl($ref->releaseUrl), [], $ref);
                }
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->info('System registry core-release fetch failed; preflight degrades to offline mode.', [
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The core release refs published in the registry index (newest first), or
     * null when the registry is unreachable. Feeds the admin "Request an
     * update" version picker; only refs are read here — the SIGNED release
     * document is still fetched + verified per version by the preflight.
     *
     * @return list<array{version: string, channel: string, blocked: bool}>|null
     */
    public function listCoreReleases(): ?array
    {
        try {
            $index = $this->client->fetchIndex($this->registryUrl);

            $releases = [];
            foreach ($index->coreRefsSorted() as $ref) {
                $releases[] = ['version' => $ref->version, 'channel' => $ref->channel, 'blocked' => $ref->blocked];
            }

            return $releases;
        } catch (\Throwable $e) {
            $this->logger->info('System registry core-release listing failed; releases degrade to offline mode.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether the official registry index is currently reachable + parseable.
     * Used by the health endpoint to report registry availability without
     * blocking the instance.
     */
    public function isReachable(): bool
    {
        try {
            $this->client->fetchIndex($this->registryUrl);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fetch the security-advisory feed, or null when the registry is unreachable
     * or no feed is published. The feed is informational (not signature-gated).
     *
     * @return array<string,mixed>|null
     */
    public function getAdvisoryFeed(): ?array
    {
        try {
            $index = $this->client->fetchIndex($this->registryUrl);

            return $this->client->fetchAdvisoryFeed($index);
        } catch (\Throwable $e) {
            $this->logger->info('System registry advisory fetch failed; advisories degrade to "could not check".', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
