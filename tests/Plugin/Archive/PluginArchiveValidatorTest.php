<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Archive;

use App\Plugin\Archive\PluginArchiveValidator;
use App\Plugin\Manifest\PluginManifestLoader;
use App\Plugin\Manifest\PluginManifestValidator;
use App\Plugin\Manifest\ResolvedSource;
use App\Plugin\Security\PluginSignatureVerifier;
use App\Plugin\Security\SignedPayloadBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * End-to-end happy-path test for a standalone .shplugin staging
 * directory.
 *
 * Builds a tiny archive on disk:
 *
 *   plugin.json (archive.mode=standalone, capabilities include
 *               backendBundle so the cross-field manifest validator
 *               accepts the backend block)
 *   signature.json (real Ed25519 over the recomputed canonical bytes)
 *   artifacts/plugin.esm.js
 *   artifacts/SHA256SUMS (covers artifacts/* AND backend/package/*)
 *   backend/package/composer.json (name + version aligned with plugin.json)
 *
 * Then hands the staging dir to `PluginArchiveValidator::validate()`
 * and asserts:
 *
 *   - validation does NOT throw,
 *   - the returned ResolvedSource carries archiveMode=standalone +
 *     an archiveBackendDir pointing at the staged backend/package/,
 *   - the resolved Composer block matches the manifest.
 *
 * The signing key is generated per-test so the test stays
 * self-contained (no fixtures to maintain).
 */
final class PluginArchiveValidatorTest extends TestCase
{
    private string $stagingDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('ext-sodium is required.');
        }
        $this->fs = new Filesystem();
        $this->stagingDir = sys_get_temp_dir() . '/sh-archive-val-' . bin2hex(random_bytes(4));
        $this->fs->mkdir($this->stagingDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stagingDir)) {
            $this->fs->remove($this->stagingDir);
        }
    }

    public function testValidatesStandaloneArchive(): void
    {
        $pluginId = 'sh-standalone-test';
        $pluginVersion = '1.0.0';
        $composerPackage = 'humdek/sh-standalone-test';

        $kp = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($kp));
        $privateKey = sodium_crypto_sign_secretkey($kp);
        $keyId = 'humdek-2026-01';

        // Build the staging layout.
        $artifactsDir = $this->stagingDir . '/artifacts';
        $backendDir = $this->stagingDir . '/backend/package';
        $this->fs->mkdir($artifactsDir);
        $this->fs->mkdir($backendDir);

        $esm = "export const register = () => ({ id: '" . $pluginId . "' });\n";
        file_put_contents($artifactsDir . '/plugin.esm.js', $esm);

        // backend/package/composer.json — must match plugin.json.
        $composerJson = [
            'name' => $composerPackage,
            'version' => $pluginVersion,
            'type' => 'symfony-bundle',
            'require' => ['php' => '^8.4'],
            'autoload' => ['psr-4' => ['Humdek\\StandaloneTestBundle\\' => 'src/']],
        ];
        file_put_contents(
            $backendDir . '/composer.json',
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        // Build SHA256SUMS (covers both prefixes; sorted).
        $entries = [
            'artifacts/plugin.esm.js' => $artifactsDir . '/plugin.esm.js',
            'backend/package/composer.json' => $backendDir . '/composer.json',
        ];
        ksort($entries);
        $lines = [];
        foreach ($entries as $rel => $abs) {
            $lines[] = sprintf('%s  %s', hash_file('sha256', $abs), $rel);
        }
        file_put_contents($artifactsDir . '/SHA256SUMS', implode("\n", $lines) . "\n");

        // Compute the backend packageHash the validator will re-derive
        // from disk so we can pin it inside the canonical payload.
        $backendLines = array_values(array_filter(
            $lines,
            static fn(string $line): bool => str_ends_with($line, ' backend/package/composer.json'),
        ));
        sort($backendLines);
        $packageHash = 'sha256-' . hash('sha256', implode("\n", $backendLines));

        // plugin.json — archive.mode=standalone + backend block.
        $manifest = [
            'id' => $pluginId,
            'name' => 'Standalone Test',
            'version' => $pluginVersion,
            'pluginApiVersion' => '0.1.0',
            'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0', 'php' => '^8.4'],
            'backend' => [
                'bundleClass' => 'Humdek\\StandaloneTestBundle\\HumdekStandaloneTestBundle',
                'composer' => ['package' => $composerPackage, 'version' => $pluginVersion],
            ],
            'frontend' => [
                'runtime' => ['entrypoint' => 'dist/plugin.esm.js', 'format' => 'esm'],
            ],
            'security' => [
                'trustLevel' => 'reviewed',
                'capabilities' => ['backendBundle', 'frontendStyles'],
            ],
            'archive' => [
                'mode' => 'standalone',
                'backend' => [
                    'included' => true,
                    'path' => 'backend/package',
                    'installMode' => 'composer-path-repository',
                ],
            ],
        ];
        file_put_contents(
            $this->stagingDir . '/plugin.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        // Build the canonical payload via the same builder the host
        // will use to recompute (byte-equality is the whole point).
        $payloadBuilder = new SignedPayloadBuilder();
        $esmChecksum = 'sha256-' . hash_file('sha256', $artifactsDir . '/plugin.esm.js');
        $signedPayload = $payloadBuilder->build([
            'pluginId' => $pluginId,
            'version' => $pluginVersion,
            'composer' => ['package' => $composerPackage, 'version' => $pluginVersion],
            'runtime' => ['entrypointUrl' => 'artifacts/plugin.esm.js', 'format' => 'esm'],
            'checksums' => ['frontendEsm' => $esmChecksum],
            'compatibility' => ['selfhelp' => '>=0.1.0 <0.2.0', 'php' => '^8.4'],
            'archive' => [
                'mode' => 'standalone',
                'backend' => [
                    'included' => true,
                    'path' => 'backend/package',
                    'installMode' => 'composer-path-repository',
                    'packageHash' => $packageHash,
                ],
            ],
        ]);

        $signature = base64_encode(sodium_crypto_sign_detached($signedPayload, $privateKey));
        file_put_contents(
            $this->stagingDir . '/signature.json',
            json_encode([
                'keyId' => $keyId,
                'signature' => $signature,
                'signedPayload' => $signedPayload,
            ], JSON_PRETTY_PRINT) . "\n",
        );

        // Construct the validator under test (with real schema +
        // signature verifier — this is the integration happy path).
        $schemaPath = $this->locateSchemaPath();
        $manifestLoader = new PluginManifestLoader(new PluginManifestValidator($schemaPath));
        $signatureVerifier = new PluginSignatureVerifier(
            trustedKeys: [$keyId => $publicKey],
            requireSignature: true,
            appEnv: 'test',
        );
        $validator = new PluginArchiveValidator(
            $manifestLoader,
            $payloadBuilder,
            $signatureVerifier,
        );

        $result = $validator->validate($this->stagingDir);

        self::assertSame($pluginId, $result['manifest']->getPluginId());
        self::assertSame($pluginVersion, $result['manifest']->getVersion());

        /** @var ResolvedSource $resolved */
        $resolved = $result['resolved'];
        self::assertSame(ResolvedSource::KIND_ARCHIVE, $resolved->kind);
        self::assertSame(ResolvedSource::ARCHIVE_MODE_STANDALONE, $resolved->archiveMode);
        self::assertNotNull($resolved->archiveBackendDir);
        // Normalise both sides because Symfony Filesystem rewrites
        // Windows backslashes inside the staging dir to forward slashes
        // before the validator stores them.
        $expected = str_replace('\\', '/', $this->stagingDir . '/backend/package');
        $actual = str_replace('\\', '/', (string) $resolved->archiveBackendDir);
        self::assertSame($expected, $actual);
        self::assertSame($composerPackage, $resolved->composer['package']);
        self::assertSame($pluginVersion, $resolved->composer['version']);
    }

    private function locateSchemaPath(): string
    {
        $candidates = [
            __DIR__ . '/../../../docs/plugins/plugin-manifest.schema.json',
            __DIR__ . '/../../../../docs/plugins/plugin-manifest.schema.json',
        ];
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real)) {
                return $real;
            }
        }
        self::fail('Could not locate docs/plugins/plugin-manifest.schema.json for PluginManifestValidator.');
    }
}
