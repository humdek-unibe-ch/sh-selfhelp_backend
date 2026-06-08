<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Service\System;

/**
 * Single source of truth for the CURRENT instance identity and version facts.
 *
 * The instance id is derived ONLY from server-side configuration
 * (`SELFHELP_INSTANCE_ID`, injected by the SelfHelp Manager when it generates
 * the per-instance Docker compose/env). It is never read from a request. This
 * is the linchpin of the "CMS update management is scoped to the current
 * instance" rule: every system endpoint resolves the instance through this
 * service, so the browser can never target a different instance.
 */
class SystemInstanceService
{
    public function __construct(
        private readonly string $instanceId,
        private readonly string $cmsVersion,
        private readonly string $pluginApiVersion,
        private readonly string $frontendVersion,
        private readonly bool $safeMode,
        private readonly bool $maintenanceMode,
    ) {
    }

    /** Trusted, server-derived instance identity. */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getCmsVersion(): string
    {
        return $this->cmsVersion;
    }

    public function getPluginApiVersion(): string
    {
        return $this->pluginApiVersion;
    }

    public function getFrontendVersion(): string
    {
        return $this->frontendVersion;
    }

    public function isSafeMode(): bool
    {
        return $this->safeMode;
    }

    public function isMaintenanceMode(): bool
    {
        return $this->maintenanceMode;
    }

    /**
     * Whether a client-supplied instance id is allowed. It never is: the CMS
     * must not trust a browser-provided instance id, and any value at all is a
     * cross-instance attempt that the caller must deny + log.
     */
    public function isUntrustedInstanceValue(mixed $clientValue): bool
    {
        return $clientValue !== null;
    }
}
