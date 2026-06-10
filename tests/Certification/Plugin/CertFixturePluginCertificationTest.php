<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Certification\Plugin;

use App\Tests\Certification\InstallLifecycleCertificationTestCase;

/**
 * Concrete certification proving {@see InstallLifecycleCertificationTestCase}
 * end-to-end against a synthetic fixture plugin (Slice 8B).
 *
 * Uses a DEDICATED plugin id (`sh2-shp-cert-fixture`) so it never collides
 * with the doctor-test fixture exercised by ManagedModeInstallTest — both
 * suites can run together. The manifest is `trustLevel=untrusted` with only
 * the `frontendStyles` capability, so the test-env signature verifier accepts
 * the paste install without configured trusted keys.
 *
 * Real plugin repos provide their own subclass that returns their actual
 * `plugin.json`, inheriting the entire lifecycle + cleanup certification.
 */
final class CertFixturePluginCertificationTest extends InstallLifecycleCertificationTestCase
{
    private const PLUGIN_ID = 'sh2-shp-cert-fixture';
    private const PLUGIN_VERSION = '1.0.0';

    protected function pluginId(): string
    {
        return self::PLUGIN_ID;
    }

    protected function expectedVersion(): string
    {
        return self::PLUGIN_VERSION;
    }

    protected function pluginManifest(): array
    {
        // Track the CMS's own SDK version so the fixture never drifts out of
        // compatibility when the host bumps it. The selfhelp constraint is a
        // 0.1.x range that includes the current pre-release (`0.1.0`).
        $sdkVersion = $this->coerceString(self::getContainer()->getParameter('selfhelp.plugin_api_version'));

        return [
            'id' => self::PLUGIN_ID,
            'name' => 'Certification Fixture Plugin',
            'version' => self::PLUGIN_VERSION,
            'pluginApiVersion' => $sdkVersion,
            'description' => 'Synthetic plugin used only by the install-lifecycle certification base.',
            'author' => ['name' => 'SelfHelp Test Harness'],
            'license' => 'MPL-2.0',
            'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0'],
            'frontend' => [
                'runtime' => [
                    'entrypoint' => '/plugin-artifacts/' . self::PLUGIN_ID . '-' . self::PLUGIN_VERSION . '/plugin.esm.js',
                    'format' => 'esm',
                ],
            ],
            'security' => [
                'trustLevel' => 'untrusted',
                'capabilities' => ['frontendStyles'],
            ],
        ];
    }
}
