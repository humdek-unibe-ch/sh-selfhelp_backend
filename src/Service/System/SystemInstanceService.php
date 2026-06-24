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
        private readonly string $mobilePreviewVersion,
        private readonly string $deployment,
        private readonly bool $safeMode,
        private readonly MaintenanceModeService $maintenance,
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

    /**
     * The provisioned `selfhelp-mobile-preview` web image version. The SelfHelp
     * Manager sets `SELFHELP_MOBILE_PREVIEW_VERSION` to the preview image version
     * it provisioned for this instance; 'unknown' until it does (e.g. a dev
     * source checkout, or an instance that predates default provisioning — the
     * page-editor preview panel then probes the running image / falls back to a
     * local Expo dev server). Mirrors {@see getFrontendVersion()}.
     */
    public function getMobilePreviewVersion(): string
    {
        return $this->mobilePreviewVersion;
    }

    /**
     * How this backend is deployed: 'docker' (baked into the production image
     * via SELFHELP_DEPLOYMENT) or 'source' (composer dev / bare checkout). Lets
     * the admin UI distinguish a managed Docker install from a dev setup.
     */
    public function getDeployment(): string
    {
        return $this->deployment;
    }

    public function isSafeMode(): bool
    {
        return $this->safeMode;
    }

    /**
     * Live maintenance state: the env hard switch OR the admin-toggled persistent
     * file (see {@see MaintenanceModeService}). Resolved on each call so a toggle
     * takes effect without a restart.
     */
    public function isMaintenanceMode(): bool
    {
        return $this->maintenance->isEnabled();
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
