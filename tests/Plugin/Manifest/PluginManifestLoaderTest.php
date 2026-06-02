<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Manifest;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\PluginManifestLoader;
use App\Tests\Support\QaKernelTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unit coverage for {@see PluginManifestLoader}.
 *
 * Guards the install error contract introduced on the testing branch: a
 * client-supplied `plugin.json` that fails schema OR cross-field validation
 * must be rejected with HTTP 422 (Unprocessable Content) carried in the
 * exception code, so `AdminPluginController::respondWithError()` maps it to a
 * client error instead of a misleading 500. Runs against the real
 * `PluginManifestValidator` + canonical schema (no mocks, no DB writes).
 */
final class PluginManifestLoaderTest extends QaKernelTestCase
{
    private PluginManifestLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = $this->service(PluginManifestLoader::class);
    }

    /**
     * Minimal schema-valid manifest: the six required top-level fields, no
     * backend/mobile/styles blocks so no cross-field invariant applies.
     *
     * @return array<string, mixed>
     */
    private function validManifest(): array
    {
        return [
            'id' => 'qa-loader-probe',
            'name' => 'QA Loader Probe',
            'version' => '1.0.0',
            'pluginApiVersion' => '1.0',
            'compatibility' => ['selfhelp' => '>=8.0.0-dev <9.0.0', 'php' => '^8.4'],
            'security' => ['trustLevel' => 'untrusted', 'capabilities' => []],
        ];
    }

    public function testValidManifestLoadsToDto(): void
    {
        $manifest = $this->loader->loadFromArray($this->validManifest());

        self::assertInstanceOf(PluginManifest::class, $manifest);
        self::assertSame('qa-loader-probe', $manifest->getPluginId());
        self::assertSame('1.0.0', $manifest->getVersion());
    }

    public function testSchemaInvalidManifestThrowsUnprocessableEntity(): void
    {
        try {
            // Missing every required field except `id`.
            $this->loader->loadFromArray(['id' => 'qa-loader-probe']);
            self::fail('A schema-invalid manifest must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->getCode(),
                'An invalid client-supplied manifest must map to 422, not 500.'
            );
            self::assertStringContainsString('plugin.json is invalid', $e->getMessage());
        }
    }

    public function testCrossFieldInvariantViolationThrowsUnprocessableEntity(): void
    {
        // Schema-valid shape, but the security invariant fails: a `backend`
        // block without the `backendBundle` capability. This must also be 422.
        $manifest = $this->validManifest();
        $manifest['security'] = ['trustLevel' => 'reviewed', 'capabilities' => []];
        $manifest['backend'] = [
            'bundleClass' => 'Humdek\\QaProbeBundle\\HumdekQaProbeBundle',
            'composer' => ['package' => 'humdek/qa-probe', 'version' => '1.0.0'],
        ];

        try {
            $this->loader->loadFromArray($manifest);
            self::fail('A backend block without the backendBundle capability must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getCode());
            self::assertStringContainsString('backendBundle', $e->getMessage());
        }
    }
}
