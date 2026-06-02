<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Certification\Plugin;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\PluginManifestValidator;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Versioning\PluginCompatibilityValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Host-side SurveyJS backend certification (Slice 8C). SurveyJS is the
 * reference plugin for the certification kit, so its REAL `plugin.json`
 * is run through the host's actual validation pipeline:
 *
 *   - {@see PluginManifestValidator} — JSON-schema structural validity.
 *   - {@see PluginCompatibilityValidator} — host + SDK version ranges.
 *   - {@see PluginCapabilityValidator} — deny-by-default capability vs.
 *     trust-level contract (throws on any violation).
 *
 * Why validation, not a paste-install: SurveyJS is `trustLevel=official`
 * and ships a backend bundle + migrations. An unsigned paste install of
 * an `official` manifest is refused by the signature verifier (the
 * dev/test opt-out only covers `untrusted` plugins), and the backend
 * bundle is not autoloadable inside the host test harness. The generic
 * install-request lifecycle (202 + operation record + concurrency guard
 * + cancel) is certified by
 * {@see \App\Tests\Certification\InstallLifecycleCertificationTestCase}
 * via the synthetic fixture; the full composer/migration finalize for a
 * privileged plugin like SurveyJS runs in the deploy-time CLI smoke
 * (Slice 10). This test certifies that SurveyJS's privileged official
 * manifest CLEARS the host's static validation gauntlet.
 *
 * Skips cleanly when the SurveyJS plugin is not checked out as a sibling
 * of the host repo (canonical Testing Rule 26).
 */
final class SurveyJsPluginCertificationTest extends KernelTestCase
{
    private const PLUGIN_ID = 'sh2-shp-survey-js';

    /**
     * @return array<string, mixed>
     */
    private function loadManifest(): array
    {
        self::bootKernel();
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        // Sibling layout: <root>/sh-selfhelp_backend + <root>/plugins/sh2-shp-survey-js.
        $candidates = [
            $projectDir . '/../plugins/' . self::PLUGIN_ID . '/plugin.json',
            $projectDir . '/../../plugins/' . self::PLUGIN_ID . '/plugin.json',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                /** @var array<string, mixed> $data */
                $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                return $data;
            }
        }
        self::markTestSkipped(sprintf(
            'SurveyJS plugin.json not found as a sibling checkout (looked in %s). Check out plugins/%s next to the host to run this certification.',
            implode(', ', $candidates),
            self::PLUGIN_ID,
        ));
    }

    public function testRealManifestIsStructurallyValidAgainstTheHostSchema(): void
    {
        $data = $this->loadManifest();
        $validator = self::getContainer()->get(PluginManifestValidator::class);
        self::assertInstanceOf(PluginManifestValidator::class, $validator);

        $errors = $validator->validate($data);
        self::assertSame([], $errors, "SurveyJS plugin.json must pass the host manifest schema. Errors:\n" . implode("\n", $errors));
    }

    public function testRealManifestIsCompatibleWithThisHost(): void
    {
        $data = $this->loadManifest();
        $manifest = new PluginManifest($data);
        $validator = self::getContainer()->get(PluginCompatibilityValidator::class);
        self::assertInstanceOf(PluginCompatibilityValidator::class, $validator);

        $report = $validator->check($manifest);
        self::assertTrue(
            $report['compatible'] ?? false,
            "SurveyJS must be compatible with this host. Reasons:\n" . implode("\n", $report['reasons'] ?? []),
        );
        self::assertSame([], $report['reasons'] ?? null);
    }

    public function testRealManifestPassesTheCapabilityVsTrustLevelContract(): void
    {
        $data = $this->loadManifest();
        $manifest = new PluginManifest($data);
        $validator = self::getContainer()->get(PluginCapabilityValidator::class);
        self::assertInstanceOf(PluginCapabilityValidator::class, $validator);

        self::assertSame(self::PLUGIN_ID, $manifest->getPluginId());
        self::assertSame('official', $manifest->getTrustLevel(), 'SurveyJS ships as an official plugin');

        // validate() throws PluginCapabilityViolationException on any
        // deny-by-default violation; returning the granted set is the pass.
        $granted = $validator->validate($manifest);
        self::assertContains('backendBundle', $granted, 'official SurveyJS may ship a backend bundle');
        self::assertContains('databaseMigrations', $granted);
        self::assertContains('frontendStyles', $granted);
    }
}
