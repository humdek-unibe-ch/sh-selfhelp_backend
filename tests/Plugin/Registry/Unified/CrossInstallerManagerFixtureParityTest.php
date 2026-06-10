<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Registry\Unified;

use App\Plugin\Registry\Unified\CanonicalJson;
use App\Plugin\Registry\Unified\UnifiedRegistryClient;
use App\Plugin\Security\PluginSignatureVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Cross-installer SAME-FIXTURE parity — backend half.
 *
 * The unified-registry contract promises ONE signed release document is
 * verifiable by BOTH installers. {@see CrossInstallerArchitectureSmokeTest}
 * proves the backend half against the backend's own committed fixture; this
 * test closes the loop by verifying a release document the OTHER installer
 * authored + signed: the SelfHelp Manager's committed example
 * `sh-manager/packages/schemas/examples/core-release.json`.
 *
 * That manager document ships NO inline `security.signedPayload`, so the backend
 * MUST recompute the canonical JSON form to (a) match `signedPayloadSha256` and
 * (b) verify the Ed25519 signature. A passing verification therefore proves the
 * backend {@see CanonicalJson} is byte-identical to the manager `canonicalize`
 * that produced the signature — genuine cross-installer canonical parity, not
 * two copies of the same bytes.
 *
 * Skipped automatically when the sibling manager repo is not checked out (CI
 * isolation); runs in the dev workspace layout.
 */
#[Group('plugin')]
final class CrossInstallerManagerFixtureParityTest extends TestCase
{
    private const BASE_URL = 'https://registry.selfhelp.test';

    /** The Manager's own committed, signed core-release example. */
    private function managerCoreFixture(): string
    {
        return \dirname(__DIR__, 5) . '/sh-manager/packages/schemas/examples/core-release.json';
    }

    private function trustedVerifier(): PluginSignatureVerifier
    {
        // Re-derive the public half of the deterministic dev seed the manager
        // fixture signer used; nothing secret is checked in.
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

    public function testBackendVerifiesManagerSignedCoreReleaseProvingCanonicalParity(): void
    {
        $fixture = $this->managerCoreFixture();
        if (!is_file($fixture)) {
            self::markTestSkipped('SelfHelp Manager repo not checked out alongside the backend.');
        }
        $body = (string) file_get_contents($fixture);

        // (1) Direct canonical-byte parity: the backend's canonical encoder over
        //     the security-stripped manager document reproduces the EXACT hash the
        //     manager signer recorded in security.signedPayloadSha256.
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['security']);
        self::assertArrayNotHasKey(
            'signedPayload',
            $decoded['security'],
            'the manager core example ships no inline payload, forcing the backend to recompute canonical JSON',
        );
        $clone = $decoded;
        unset($clone['security']);
        $canonical = CanonicalJson::encode($clone);
        $declaredHash = $decoded['security']['signedPayloadSha256'] ?? null;
        self::assertIsString($declaredHash);
        $expectedHash = strtolower((string) preg_replace('/^sha256:/i', '', $declaredHash));
        self::assertSame(
            $expectedHash,
            hash('sha256', $canonical),
            'backend canonical JSON of the manager-authored core release is byte-identical to the manager signer',
        );

        // (2) Full client path: fetch + signature-verify the manager document via
        //     the same UnifiedRegistryClient the live admin flow uses.
        $http = new MockHttpClient(static function (string $method, string $url) use ($body): MockResponse {
            if (str_ends_with($url, '/releases/core/selfhelp-core-0.1.0.json')) {
                return new MockResponse($body);
            }
            return new MockResponse('not found', ['http_code' => 404]);
        });
        $client = new UnifiedRegistryClient($http, $this->trustedVerifier(), new NullLogger());
        $core = $client->fetchCoreRelease(self::BASE_URL . '/releases/core/selfhelp-core-0.1.0.json');

        self::assertSame('0.1.0', $core->version);
        self::assertSame('0.1.0', $core->pluginApiVersion);
        self::assertStringStartsWith('sha256:', $core->backend->digest, 'manager-authored advisory digest is readable by the backend');
    }
}
