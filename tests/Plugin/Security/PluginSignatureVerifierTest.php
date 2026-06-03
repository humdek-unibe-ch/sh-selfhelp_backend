<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Plugin\Security;

use App\Plugin\Security\PluginSignatureException;
use App\Plugin\Security\PluginSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class PluginSignatureVerifierTest extends TestCase
{
    /** @var array{publicKey:string,privateKey:non-empty-string} */
    private array $keyPair;

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('ext-sodium is required.');
        }
        $kp = sodium_crypto_sign_keypair();
        $this->keyPair = [
            'publicKey' => base64_encode(sodium_crypto_sign_publickey($kp)),
            'privateKey' => sodium_crypto_sign_secretkey($kp),
        ];
    }

    public function testVerifiesValidSignature(): void
    {
        $payload = '{"hello":"world"}';
        $signature = base64_encode(sodium_crypto_sign_detached($payload, $this->keyPair['privateKey']));
        $verifier = new PluginSignatureVerifier(['humdek-2026-01' => $this->keyPair['publicKey']]);

        $this->expectNotToPerformAssertions();
        $verifier->verify('official', 'humdek-2026-01', $signature, $payload);
    }

    public function testRejectsTamperedPayload(): void
    {
        $payload = '{"hello":"world"}';
        $signature = base64_encode(sodium_crypto_sign_detached($payload, $this->keyPair['privateKey']));
        $verifier = new PluginSignatureVerifier(['humdek-2026-01' => $this->keyPair['publicKey']]);

        $this->expectException(PluginSignatureException::class);
        $verifier->verify('official', 'humdek-2026-01', $signature, '{"hello":"WORLD"}');
    }

    public function testRejectsUnknownKeyId(): void
    {
        $payload = 'irrelevant';
        $signature = base64_encode(sodium_crypto_sign_detached($payload, $this->keyPair['privateKey']));
        $verifier = new PluginSignatureVerifier(['humdek-2026-01' => $this->keyPair['publicKey']]);

        $this->expectException(PluginSignatureException::class);
        $this->expectExceptionMessageMatches('/not in SELFHELP_PLUGIN_TRUSTED_KEYS/');
        $verifier->verify('official', 'rogue-key', $signature, $payload);
    }

    public function testRejectsMissingSignatureOnReviewed(): void
    {
        $verifier = new PluginSignatureVerifier(['humdek-2026-01' => $this->keyPair['publicKey']]);

        $this->expectException(PluginSignatureException::class);
        $verifier->verify('reviewed', null, null, null);
    }

    public function testAllowsMissingSignatureOnUntrustedWhenNotRequired(): void
    {
        $verifier = new PluginSignatureVerifier(
            trustedKeys: ['humdek-2026-01' => $this->keyPair['publicKey']],
            requireSignature: false,
        );

        $this->expectNotToPerformAssertions();
        $verifier->verify('untrusted', null, null, null);
    }

    public function testParsesTrustedKeysEnvString(): void
    {
        $env = "humdek-2026-01=AAAA;rogue=BBBB; ;humdek-2027-01=CCCC";
        $parsed = PluginSignatureVerifier::parseTrustedKeys($env);
        self::assertSame([
            'humdek-2026-01' => 'AAAA',
            'rogue' => 'BBBB',
            'humdek-2027-01' => 'CCCC',
        ], $parsed);
    }

    public function testFromEnvStringFactory(): void
    {
        $env = 'k1=' . $this->keyPair['publicKey'];
        $verifier = PluginSignatureVerifier::fromEnvString($env);
        $payload = 'payload';
        $signature = base64_encode(sodium_crypto_sign_detached($payload, $this->keyPair['privateKey']));
        $this->expectNotToPerformAssertions();
        $verifier->verify('official', 'k1', $signature, $payload);
    }

    public function testRejectsDevKeyOnOfficialInProd(): void
    {
        $payload = 'payload';
        $signature = base64_encode(sodium_crypto_sign_detached($payload, $this->keyPair['privateKey']));
        $verifier = new PluginSignatureVerifier(
            trustedKeys: ['dev' => $this->keyPair['publicKey']],
            appEnv: 'prod',
        );

        $this->expectException(PluginSignatureException::class);
        $this->expectExceptionMessageMatches('/reserved for local development/');
        $verifier->verify('official', 'dev', $signature, $payload);
    }

    public function testAllowsDevKeyOnOfficialInDev(): void
    {
        $payload = 'payload';
        $signature = base64_encode(sodium_crypto_sign_detached($payload, $this->keyPair['privateKey']));
        $verifier = new PluginSignatureVerifier(
            trustedKeys: ['dev' => $this->keyPair['publicKey']],
            appEnv: 'dev',
        );

        $this->expectNotToPerformAssertions();
        $verifier->verify('official', 'dev', $signature, $payload);
    }
}
