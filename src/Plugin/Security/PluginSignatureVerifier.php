<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


declare(strict_types=1);

namespace App\Plugin\Security;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Verifies plugin checksums and Ed25519 signatures.
 *
 * The verifier supports two operating modes selected by config:
 *
 *   - `strict`: refuse install when any expected signature/checksum is
 *     missing or invalid. Used in production for `official` and
 *     `reviewed` plugins.
 *   - `lenient`: log signature failures but do not abort. Used in
 *     development and for `untrusted` plugins where only frontend
 *     code ships and signatures may not be available.
 *
 * The public key for verification is supplied at construction time
 * (base64-encoded). When the key is empty the verifier degrades to
 * checksum-only mode and logs a warning per call.
 *
 * The plugin manifest exposes the expected checksums and signature
 * under `security.checksums` and `security.signature`; the install
 * orchestrator passes them in via {@see verify()}.
 */
final class PluginSignatureVerifier
{
    public const MODE_STRICT = 'strict';
    public const MODE_LENIENT = 'lenient';

    public function __construct(
        private readonly string $mode = self::MODE_STRICT,
        private readonly ?string $publicKeyBase64 = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array{
     *   composer?: string|null,
     *   frontend?: string|null,
     *   mobile?: string|null,
     * } $expectedChecksums Expected `sha256:<hex>` checksums per artifact.
     * @param array{
     *   files: array<string,string>,
     * } $actualChecksums Computed checksums of the downloaded artifacts.
     * @param string|null $signatureBase64 Ed25519 signature of the
     *                                     canonical checksum manifest, if any.
     * @param string|null $signedPayload   The canonical bytes that
     *                                     were signed.
     *
     * @throws PluginSignatureException when verification fails in strict mode.
     */
    public function verify(
        array $expectedChecksums,
        array $actualChecksums,
        ?string $signatureBase64 = null,
        ?string $signedPayload = null,
    ): void {
        foreach ($expectedChecksums as $artifact => $expected) {
            if ($expected === null || $expected === '') {
                continue;
            }
            $actual = $actualChecksums['files'][$artifact] ?? null;
            if ($actual === null) {
                $this->fail(sprintf('Missing checksum for artifact "%s".', $artifact));
                continue;
            }
            if (!hash_equals($this->canonicalChecksum($expected), $this->canonicalChecksum($actual))) {
                $this->fail(sprintf(
                    'Checksum mismatch for artifact "%s" (expected %s, got %s).',
                    $artifact,
                    $expected,
                    $actual
                ));
            }
        }

        if ($signatureBase64 !== null && $signatureBase64 !== '') {
            $this->verifySignature($signatureBase64, $signedPayload ?? '');
        } elseif ($this->mode === self::MODE_STRICT && $this->publicKeyBase64 !== null && $this->publicKeyBase64 !== '') {
            $this->fail('Missing plugin signature; strict mode requires a signed release.');
        }
    }

    private function verifySignature(string $signatureBase64, string $signedPayload): void
    {
        if ($this->publicKeyBase64 === null || $this->publicKeyBase64 === '') {
            $this->logger->warning('Plugin signature verification skipped: no public key configured.');
            return;
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $this->logger->warning('Plugin signature verification skipped: libsodium not available.');
            return;
        }

        $signature = base64_decode($signatureBase64, true);
        $publicKey = base64_decode($this->publicKeyBase64, true);
        if ($signature === false || $publicKey === false) {
            $this->fail('Plugin signature or public key is not valid base64.');
            return;
        }

        $ok = false;
        try {
            $ok = sodium_crypto_sign_verify_detached($signature, $signedPayload, $publicKey);
        } catch (\SodiumException $e) {
            $this->fail('Plugin signature verification raised: ' . $e->getMessage());
            return;
        }

        if (!$ok) {
            $this->fail('Plugin signature does not match the SelfHelp release public key.');
        }
    }

    private function canonicalChecksum(string $value): string
    {
        $value = strtolower(trim($value));
        return str_starts_with($value, 'sha256:') ? $value : 'sha256:' . $value;
    }

    private function fail(string $message): void
    {
        if ($this->mode === self::MODE_STRICT) {
            throw new PluginSignatureException($message);
        }
        $this->logger->warning('Plugin signature verification (lenient): ' . $message);
    }
}
