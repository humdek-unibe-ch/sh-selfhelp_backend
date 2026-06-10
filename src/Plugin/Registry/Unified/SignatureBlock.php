<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Plugin\Registry\Unified;

/**
 * Ed25519 detached-signature block shared by every signed unified-registry
 * release document. Mirrors the shared `SignatureBlock` TypeScript interface
 * (`@selfhelp/shared` `distribution.ts`) and the Manager Zod schema.
 *
 *   - `signature`          base64 detached Ed25519 signature;
 *   - `keyId`              trusted-key identifier (never "dev" in production);
 *   - `signedPayload`      the EXACT canonical bytes CI signed (optional —
 *                          when absent, the verifier re-canonicalises the
 *                          release document with its `security` block removed);
 *   - `signedPayloadSha256` optional integrity digest of `signedPayload`.
 */
final class SignatureBlock
{
    public function __construct(
        public readonly string $signature,
        public readonly string $keyId,
        public readonly ?string $signedPayload = null,
        public readonly ?string $signedPayloadSha256 = null,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data, string $context): self
    {
        $signature = $data['signature'] ?? null;
        $keyId = $data['keyId'] ?? null;
        if (!is_string($signature) || $signature === '') {
            throw new MalformedRegistryException(sprintf('%s: security.signature must be a non-empty string.', $context));
        }
        if (!is_string($keyId) || $keyId === '') {
            throw new MalformedRegistryException(sprintf('%s: security.keyId must be a non-empty string.', $context));
        }
        $signedPayload = $data['signedPayload'] ?? null;
        $signedPayloadSha256 = $data['signedPayloadSha256'] ?? null;

        return new self(
            signature: $signature,
            keyId: $keyId,
            signedPayload: is_string($signedPayload) && $signedPayload !== '' ? $signedPayload : null,
            signedPayloadSha256: is_string($signedPayloadSha256) && $signedPayloadSha256 !== '' ? $signedPayloadSha256 : null,
        );
    }
}
