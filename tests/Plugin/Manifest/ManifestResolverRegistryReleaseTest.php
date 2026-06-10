<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Manifest;

use App\Plugin\Archive\PluginArchiveException;
use App\Plugin\Archive\PluginArchiveExtractor;
use App\Plugin\Archive\PluginArchiveValidator;
use App\Plugin\Manifest\ManifestResolver;
use App\Plugin\Manifest\PluginManifestLoader;
use App\Plugin\Manifest\PluginManifestValidator;
use App\Plugin\Registry\Unified\MalformedRegistryException;
use App\Plugin\Registry\Unified\UnifiedRegistryClient;
use App\Plugin\Security\PluginSignatureVerifier;
use App\Plugin\Security\SignedPayloadBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * #50 — the live admin `/install` path for a UNIFIED registry source routes
 * through {@see ManifestResolver::resolveRegistryRelease()}: follow the signed
 * `PluginRelease`, download the `.shplugin`, checksum-verify it against
 * `artifacts.sha256`, then hand the archive to the SAME trust path a manual
 * upload uses. These tests prove that composition against the one shared signed
 * fixture (re-derived dev key, no checked-in secret):
 *
 *  - a tampered archive is rejected at the sha256 gate (security), and
 *  - checksum-valid bytes pass the gate and reach the archive extractor.
 */
#[Group('plugin')]
final class ManifestResolverRegistryReleaseTest extends TestCase
{
    private const BASE_URL = 'https://registry.selfhelp.test';

    private function fixtureDir(): string
    {
        return \dirname(__DIR__, 2) . '/fixtures/registry/unified';
    }

    private function trustedVerifier(): PluginSignatureVerifier
    {
        $seed = hash('sha256', 'selfhelp-dev-registry-signing-key-v1', true);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return new PluginSignatureVerifier(
            ['selfhelp-dev-fixture' => base64_encode($publicKey)],
            true,
            new NullLogger(),
            'test',
        );
    }

    /**
     * @param array<string,string> $overrides path => replacement body
     */
    private function fixtureHttpClient(array $overrides = []): HttpClientInterface
    {
        $dir = $this->fixtureDir();
        return new MockHttpClient(static function (string $method, string $url) use ($dir, $overrides): MockResponse {
            $path = ltrim(substr($url, \strlen(self::BASE_URL)), '/');
            if (isset($overrides[$path])) {
                return new MockResponse($overrides[$path]);
            }
            $file = $dir . '/' . $path;
            if (!is_file($file)) {
                return new MockResponse('not found', ['http_code' => 404]);
            }
            $body = file_get_contents($file);
            return new MockResponse($body === false ? '' : $body);
        });
    }

    private function manifestResolver(HttpClientInterface $http): ManifestResolver
    {
        $manifestLoader = new PluginManifestLoader(new PluginManifestValidator(
            \dirname(__DIR__, 3) . '/docs/plugins/plugin-manifest.schema.json',
        ));
        $extractor = new PluginArchiveExtractor(sys_get_temp_dir());
        $validator = new PluginArchiveValidator($manifestLoader, new SignedPayloadBuilder(), $this->trustedVerifier());

        return new ManifestResolver(
            $manifestLoader,
            $extractor,
            $validator,
            new SignedPayloadBuilder(),
            $this->trustedVerifier(),
            $http,
            new UnifiedRegistryClient($http, $this->trustedVerifier(), new NullLogger()),
        );
    }

    public function testUnifiedInstallRejectsArchiveChecksumMismatch(): void
    {
        // Tamper the .shplugin bytes so they no longer hash to artifacts.sha256.
        $resolver = $this->manifestResolver($this->fixtureHttpClient([
            'artifacts/sh2-shp-survey-js-0.1.0.shplugin' => "TAMPERED BYTES\n",
        ]));

        $this->expectException(MalformedRegistryException::class);
        $this->expectExceptionMessageMatches('/does not match the registry-declared/');
        $resolver->resolveRegistryRelease(
            self::BASE_URL . '/releases/plugins/sh2-shp-survey-js-0.1.0.json',
            'humdek-public',
        );
    }

    public function testUnifiedInstallPassesChecksumGateThenReachesArchiveTrustPath(): void
    {
        // The shared fixture .shplugin is checksum-valid but intentionally a
        // non-ZIP dummy (its archive internals are exercised by the archive
        // suite). Passing the sha256 gate and then failing inside the extractor
        // proves resolveRegistryRelease composes the unified consumer with the
        // archive trust path: release verify -> sha256 gate -> extract/validate.
        $resolver = $this->manifestResolver($this->fixtureHttpClient());

        $this->expectException(PluginArchiveException::class);
        $this->expectExceptionMessageMatches('/ZIP magic bytes/');
        $resolver->resolveRegistryRelease(
            self::BASE_URL . '/releases/plugins/sh2-shp-survey-js-0.1.0.json',
            'humdek-public',
        );
    }
}
