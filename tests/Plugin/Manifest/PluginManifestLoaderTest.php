<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Manifest;

use App\Plugin\Manifest\PluginManifest;
use App\Plugin\Manifest\PluginManifestLoader;
use App\Plugin\Manifest\PluginManifestValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unit coverage for {@see PluginManifestLoader}.
 *
 * Guards the install error contract introduced on the testing branch: a
 * client-supplied `plugin.json` that fails schema OR cross-field validation
 * must be rejected with HTTP 422 (Unprocessable Content) carried in the
 * exception code, so `AdminPluginController::respondWithError()` maps it to a
 * client error instead of a misleading 500. Runs against the real
 * `PluginManifestValidator` + canonical schema (no mocks, no kernel, no DB) so
 * it belongs to the DB-free `unit` suite like the other tests/Plugin/* tests.
 */
final class PluginManifestLoaderTest extends TestCase
{
    private PluginManifestLoader $loader;

    protected function setUp(): void
    {
        // Build the loader on the real validator + canonical schema directly —
        // no container/DB, mirroring the sibling pure-unit plugin-host tests.
        $schemaPath = \dirname(__DIR__, 3) . '/docs/plugins/plugin-manifest.schema.json';
        $this->loader = new PluginManifestLoader(new PluginManifestValidator($schemaPath));
    }

    /**
     * Minimal schema-valid manifest: the six required top-level fields, no
     * backend/mobile/styles blocks so no cross-field invariant applies.
     * `pluginApiVersion` is the 3-part `0.1.0` the ecosystem reconciled on, which
     * also guards the manifest schema accepting a full SemVer SDK version (the
     * pattern was tightened to 2-part `MAJOR.MINOR` before the 0.1.0 reset).
     *
     * @return array<string, mixed>
     */
    private function validManifest(): array
    {
        return [
            'id' => 'qa-loader-probe',
            'name' => 'QA Loader Probe',
            'version' => '1.0.0',
            'pluginApiVersion' => '0.1.0',
            'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0', 'php' => '^8.4'],
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
