<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\System;

/**
 * Read-only view over the unified SelfHelp registry, limited to the metadata
 * the CMS needs to compute a *compatibility* preflight (target version facts,
 * destructive-migration flags, frontend compatibility range).
 *
 * The CMS only ever READS registry metadata over HTTP — it does not pull
 * images, run Docker, or perform resource checks. Those belong to the SelfHelp
 * Manager. Implementations MUST fail soft (return null) when the registry is
 * unreachable so the maintenance UI degrades gracefully offline.
 */
interface SystemRegistryGatewayInterface
{
    /**
     * Fetch the signed core release document for a given version, or null when
     * the registry is unreachable or the version is not published.
     *
     * @return array<string,mixed>|null
     */
    public function fetchCoreRelease(string $version): ?array;

    /**
     * Fetch the registry index document, or null when the registry is
     * unreachable. Used by the health endpoint to report registry availability
     * (and to derive "last successful check") without blocking the instance.
     *
     * @return array<string,mixed>|null
     */
    public function fetchIndex(): ?array;

    /**
     * Fetch the security advisory feed (resolved from the registry index's
     * `advisoriesUrl`), or null when the registry is unreachable or no feed is
     * published. Implementations MUST fail soft so the advisories UI degrades to
     * "could not check" rather than blocking.
     *
     * @return array<string,mixed>|null
     */
    public function fetchAdvisories(): ?array;
}
