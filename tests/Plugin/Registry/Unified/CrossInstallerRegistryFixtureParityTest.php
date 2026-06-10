<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Registry\Unified;

use App\Plugin\Registry\Unified\CanonicalJson;
use App\Plugin\Registry\Unified\RegistryIndex;
use App\Plugin\Registry\Unified\UnifiedRegistryClient;
use App\Plugin\Security\PluginSignatureVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Cross-installer SAME-FIXTURE parity — backend ⟷ official registry (#49 / #53).
 *
 * {@see CrossInstallerManagerFixtureParityTest} proves the backend verifies a
 * CORE release the Manager authored. This closes the other half of "one registry
 * feeds both installers": it consumes the ACTUAL multi-version plugin entries the
 * official `sh2-plugin-registry` publishes — its `registry.json` index plus the
 * signed `releases/plugins/sh2-shp-survey-js-*.json` documents — through the SAME
 * {@see UnifiedRegistryClient} the live admin `/available` + `/install` flow uses.
 *
 * The registry signs with `sign-release.mjs`, which ships NO inline
 * `security.signedPayload` (only `signedPayloadSha256`), so the backend MUST
 * recompute the canonical JSON to (a) match the digest and (b) verify the
 * Ed25519 signature. A passing verification proves the backend {@see CanonicalJson}
 * is byte-identical to the registry `canonicalStringify` that produced the
 * signature — genuine cross-installer canonical parity on the real published data.
 *
 * Skipped automatically when the sibling registry repo is not checked out (CI
 * isolation); runs in the dev workspace layout.
 */
#[Group('plugin')]
final class CrossInstallerRegistryFixtureParityTest extends TestCase
{
    private const BASE_URL = 'https://humdek-unibe-ch.github.io/sh2-plugin-registry';

    private function registryRoot(): string
    {
        return \dirname(__DIR__, 5) . '/plugins/sh2-plugin-registry';
    }

    private function trustedVerifier(): PluginSignatureVerifier
    {
        // Re-derive the public half of the deterministic dev seed the registry's
        // sign-release.mjs uses; nothing secret is checked in. This is the same
        // public key committed in the registry's keys/trusted-keys.json.
        $seed = hash('sha256', 'selfhelp-dev-registry-signing-key-v1', true);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return new PluginSignatureVerifier(
            ['selfhelp-official-2026' => base64_encode($publicKey)],
            true,
            new NullLogger(),
            'test',
        );
    }

    public function testBackendConsumesOfficialRegistryMultiVersionPluginEntries(): void
    {
        $root = $this->registryRoot();
        $indexPath = $root . '/registry.json';
        if (!is_file($indexPath)) {
            self::markTestSkipped('sh2-plugin-registry repo not checked out alongside the backend.');
        }

        // (1) The index is a multi-version unified index: survey-js appears as
        //     >=2 plugin release refs, newest-first after grouping.
        $indexBody = (string) file_get_contents($indexPath);
        $decodedIndex = json_decode($indexBody, true);
        self::assertIsArray($decodedIndex);
        $indexData = [];
        foreach ($decodedIndex as $key => $value) {
            $indexData[(string) $key] = $value;
        }
        $index = RegistryIndex::fromArray($indexData, 'official registry.json');
        $refsById = $index->pluginRefsById();
        self::assertArrayHasKey('sh2-shp-survey-js', $refsById);
        self::assertGreaterThanOrEqual(2, \count($refsById['sh2-shp-survey-js']), 'registry publishes multiple versions per plugin');

        // (2) Direct canonical-byte parity on the real signed release document:
        //     the backend canonical encoder over the security-stripped document
        //     reproduces the EXACT digest the registry signer recorded — no inline
        //     signedPayload exists, so this is genuine recomputation parity.
        $releasePath = $root . '/releases/plugins/sh2-shp-survey-js-0.1.0.json';
        self::assertFileExists($releasePath);
        $releaseBody = (string) file_get_contents($releasePath);
        $decoded = json_decode($releaseBody, true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['security']);
        self::assertArrayNotHasKey('signedPayload', $decoded['security'], 'registry sign-release.mjs ships no inline payload');
        $clone = $decoded;
        unset($clone['security']);
        $declaredHash = $decoded['security']['signedPayloadSha256'] ?? null;
        self::assertIsString($declaredHash);
        $expectedHash = strtolower((string) preg_replace('/^sha256:/i', '', $declaredHash));
        self::assertSame(
            $expectedHash,
            hash('sha256', CanonicalJson::encode($clone)),
            'backend canonical JSON of the registry-authored plugin release is byte-identical to the registry signer',
        );

        // (3) Full client path: follow each ref to its signed release document and
        //     Ed25519-verify it through the SAME client the admin flow uses.
        $http = new MockHttpClient(function (string $method, string $url) use ($root): MockResponse {
            $rel = ltrim(str_replace(self::BASE_URL, '', $url), '/');
            $file = $root . '/' . $rel;
            if (is_file($file)) {
                return new MockResponse((string) file_get_contents($file));
            }
            return new MockResponse('not found', ['http_code' => 404]);
        });
        $client = new UnifiedRegistryClient($http, $this->trustedVerifier(), new NullLogger());

        $byId = $client->fetchPluginReleases($index);
        self::assertArrayHasKey('sh2-shp-survey-js', $byId);
        $releases = $byId['sh2-shp-survey-js'];
        self::assertGreaterThanOrEqual(2, \count($releases), 'both signed plugin releases fetched + verified');

        $versions = array_map(static fn ($r): string => $r->version, $releases);
        self::assertContains('0.1.0', $versions);
        self::assertContains('0.2.0', $versions);

        // The 0.1.0 release is compatible with a default 0.1.0 core; 0.2.0 requires
        // a newer core — exactly the multi-version picker scenario.
        foreach ($releases as $release) {
            self::assertTrue($release->official);
            if ($release->version === '0.1.0') {
                self::assertSame('>=0.1.0 <0.2.0', $release->compatibilityCore);
            }
            if ($release->version === '0.2.0') {
                self::assertSame('>=0.2.0 <0.3.0', $release->compatibilityCore);
            }
        }
    }
}
