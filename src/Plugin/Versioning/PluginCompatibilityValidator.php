<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Versioning;

use App\Plugin\Manifest\PluginManifest;

/**
 * Compatibility validator: checks a plugin manifest's `compatibility`
 * block against the host CMS version + SDK API contract.
 *
 * The validator never throws on mismatch; it returns a structured
 * report so the installer can show a green/yellow/orange/red badge.
 * Hard failures (incompatible `pluginApiVersion`) are surfaced as
 * `severity = "blocking"` so the installer can refuse the operation.
 */
final class PluginCompatibilityValidator
{
    public function __construct(
        private readonly string $cmsVersion,
        private readonly string $sdkApiVersion,
    ) {
    }

    /**
     * @return array{
     *   compatible: bool,
     *   severity: 'ok'|'warning'|'blocking',
     *   reasons: list<string>,
     *   checks: array<string, array{
     *     name: string,
     *     expected: string,
     *     actual: string,
     *     status: 'ok'|'warning'|'blocking'
     *   }>,
     * }
     */
    public function check(PluginManifest $manifest): array
    {
        $reasons = [];
        $checks = [];

        $cmsRange = $manifest->getCmsCompatibilityRange();
        $cmsOk = PluginCompatibility::coreSatisfied($this->cmsVersion, $cmsRange);
        $checks['cms'] = [
            'name' => 'SelfHelp backend',
            'expected' => $cmsRange,
            'actual' => $this->cmsVersion,
            'status' => $cmsOk ? 'ok' : 'blocking',
        ];
        if (!$cmsOk) {
            $reasons[] = sprintf(
                'Plugin requires SelfHelp backend %s but this CMS is %s.',
                $cmsRange,
                $this->cmsVersion
            );
        }

        $sdkRange = $manifest->getPluginApiVersion();
        $sdkOk = PluginCompatibility::pluginApiSatisfied($this->sdkApiVersion, $sdkRange);
        $checks['sdk'] = [
            'name' => 'Plugin SDK',
            'expected' => $sdkRange,
            'actual' => $this->sdkApiVersion,
            'status' => $sdkOk ? 'ok' : 'blocking',
        ];
        if (!$sdkOk) {
            $reasons[] = sprintf(
                'Plugin requires SDK %s but this CMS ships SDK %s.',
                $sdkRange,
                $this->sdkApiVersion
            );
        }

        $severity = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'blocking') {
                $severity = 'blocking';
                break;
            }
        }

        return [
            'compatible' => $severity !== 'blocking',
            'severity' => $severity,
            'reasons' => $reasons,
            'checks' => $checks,
        ];
    }
}
