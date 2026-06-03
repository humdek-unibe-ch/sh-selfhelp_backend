<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Security;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Security\CapabilityCatalog;
use App\Plugin\Security\PluginCapabilityValidator;
use App\Plugin\Security\PluginCapabilityViolationException;
use App\Tests\Support\NarrowsJson;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit coverage for the deny-by-default capability gate
 * {@see PluginCapabilityValidator} (plan Phase 9: capability validator).
 *
 * The validator is the install-time security boundary: it rejects unknown trust
 * levels/capabilities, capabilities above the declared trust level, manifest
 * features that imply an undeclared capability, and non-HTTPS production runtime
 * entrypoints. These are security regressions if they ever silently pass.
 */
final class PluginCapabilityValidatorTest extends TestCase
{
    use NarrowsJson;

    private function validator(string $appEnv = 'prod'): PluginCapabilityValidator
    {
        return new PluginCapabilityValidator($appEnv);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function manifest(array $overrides): PluginManifest
    {
        return new PluginManifest(self::asArray(array_replace_recursive([
            'id' => 'qa-capability-plugin',
            'name' => 'QA Capability Plugin',
            'security' => ['trustLevel' => 'untrusted', 'capabilities' => []],
        ], $overrides)));
    }

    public function testUnknownTrustLevelIsRejected(): void
    {
        $this->expectException(PluginCapabilityViolationException::class);

        $this->validator()->validate($this->manifest(['security' => ['trustLevel' => 'qa_bogus_trust']]));
    }

    public function testUnknownCapabilityIsRejected(): void
    {
        $this->expectException(PluginCapabilityViolationException::class);

        $this->validator()->validate($this->manifest([
            'security' => ['trustLevel' => 'official', 'capabilities' => ['qa_bogus_capability']],
        ]));
    }

    public function testUntrustedCannotDeclareBackendBundle(): void
    {
        // Deny-by-default: backendBundle is outside the untrusted trust set.
        $this->expectException(PluginCapabilityViolationException::class);

        $this->validator()->validate($this->manifest([
            'security' => ['trustLevel' => 'untrusted', 'capabilities' => [CapabilityCatalog::CAP_BACKEND_BUNDLE]],
        ]));
    }

    public function testUntrustedFrontendStylesAreAccepted(): void
    {
        $result = $this->validator()->validate($this->manifest([
            'security' => ['trustLevel' => 'untrusted', 'capabilities' => [CapabilityCatalog::CAP_FRONTEND_STYLES]],
            'styles' => [['name' => 'qaStyle']],
        ]));

        self::assertSame([CapabilityCatalog::CAP_FRONTEND_STYLES], $result);
    }

    public function testDeclaringStylesWithoutTheCapabilityIsRejected(): void
    {
        // Implied-capability rule: styles imply frontendStyles.
        $this->expectException(PluginCapabilityViolationException::class);

        $this->validator()->validate($this->manifest([
            'security' => ['trustLevel' => 'untrusted', 'capabilities' => []],
            'styles' => [['name' => 'qaStyle']],
        ]));
    }

    public function testScheduledJobsRequireBackendBundleCapability(): void
    {
        $this->expectException(PluginCapabilityViolationException::class);

        // scheduledJobs alone is insufficient — backend code is implied too.
        $this->validator()->validate($this->manifest([
            'security' => ['trustLevel' => 'reviewed', 'capabilities' => [CapabilityCatalog::CAP_SCHEDULED_JOBS]],
            'scheduledJobs' => [['type' => 'qa_job']],
        ]));
    }

    public function testScheduledJobsWithBackendBundleAreAccepted(): void
    {
        $result = $this->validator()->validate($this->manifest([
            'security' => ['trustLevel' => 'reviewed', 'capabilities' => [
                CapabilityCatalog::CAP_SCHEDULED_JOBS,
                CapabilityCatalog::CAP_BACKEND_BUNDLE,
            ]],
            'scheduledJobs' => [['type' => 'qa_job']],
        ]));

        self::assertContains(CapabilityCatalog::CAP_SCHEDULED_JOBS, $result);
        self::assertContains(CapabilityCatalog::CAP_BACKEND_BUNDLE, $result);
    }

    public function testProductionHttpRuntimeEntrypointIsRejectedForOfficial(): void
    {
        $this->expectException(PluginCapabilityViolationException::class);

        $this->validator('prod')->validate($this->manifest([
            'security' => ['trustLevel' => 'official', 'capabilities' => []],
            'frontend' => ['runtime' => ['entrypoint' => 'http://insecure.example/plugin.esm.js']],
        ]));
    }

    public function testHttpsRuntimeEntrypointIsAccepted(): void
    {
        $result = $this->validator('prod')->validate($this->manifest([
            'security' => ['trustLevel' => 'official', 'capabilities' => []],
            'frontend' => ['runtime' => ['entrypoint' => 'https://secure.example/plugin.esm.js']],
        ]));

        self::assertSame([], $result, 'A capability-less official plugin over HTTPS validates to an empty set.');
    }

    public function testDevEnvAllowsHttpRuntimeEntrypoint(): void
    {
        $result = $this->validator('dev')->validate($this->manifest([
            'security' => ['trustLevel' => 'official', 'capabilities' => []],
            'frontend' => ['runtime' => ['entrypoint' => 'http://localhost:3000/plugin.esm.js']],
        ]));

        self::assertSame([], $result);
    }
}
