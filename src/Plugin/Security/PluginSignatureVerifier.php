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
 * Verifies CI-signed plugin payloads (Ed25519) against the host's
 * trusted key set.
 *
 * The publisher (CI) builds a canonical `signedPayload` via
 * `App\Plugin\Security\SignedPayloadBuilder` / the cross-impl
 * `sh2-plugin-registry/scripts/sign.mjs`, signs it with the release
 * private key, and writes `{keyId, signature, signedPayload}` into
 * `registry.json` entries and into `.shplugin#signature.json`. The
 * host loads the matching public key from the
 * `SELFHELP_PLUGIN_TRUSTED_KEYS` env (`keyId1=base64pubkey;keyId2=base64pubkey`)
 * and verifies the detached signature with
 * `sodium_crypto_sign_verify_detached`.
 *
 * Rules:
 *
 *   - `official` / `reviewed` plugins MUST be signed and verifiable.
 *     A missing signature, an unknown `keyId`, or a payload-canonical
 *     mismatch are all blocking errors.
 *   - `untrusted` plugins MAY skip signing, but only when
 *     `SELFHELP_PLUGIN_REQUIRE_SIGNATURE != true`. In production we
 *     default to `true`, so even untrusted plugins ship signed.
 *   - When the host has no trusted keys configured AND the env
 *     explicitly opts-in (`SELFHELP_PLUGIN_REQUIRE_SIGNATURE=false`),
 *     verification is skipped with a logged warning. This is only
 *     intended for first-boot dev environments.
 *
 * The actual canonical-payload re-computation (i.e. "does
 * `signedPayload` still match the manifest the host is about to
 * install?") lives in `PluginArchiveValidator` for archives and in
 * `ManifestResolver` for registry/URL sources, both of which call
 * `verify()` with the on-disk `signedPayload`. This class is the pure
 * cryptographic verifier — it does not parse the manifest.
 */
final class PluginSignatureVerifier
{
    /** @var array<string,string> keyId => base64-encoded 32-byte public key */
    private readonly array $trustedKeys;

    /**
     * @param array<string,string> $trustedKeys keyId => base64 public key
     * @param string $appEnv Symfony APP_ENV. `dev` opts in to the
     *                       `keyId="dev"` development bypass for any
     *                       trust level; `prod`/`test` keep the strict
     *                       refusal.
     */
    public function __construct(
        array $trustedKeys = [],
        private readonly bool $requireSignature = true,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $appEnv = 'prod',
    ) {
        $this->trustedKeys = $trustedKeys;
    }

    /**
     * Factory that parses `SELFHELP_PLUGIN_TRUSTED_KEYS` (semicolon-
     * separated `keyId=base64pubkey` pairs). Whitespace around tokens
     * is tolerated; empty entries are skipped.
     */
    public static function fromEnvString(
        string $trustedKeysEnv,
        bool $requireSignature = true,
        LoggerInterface $logger = new NullLogger(),
        string $appEnv = 'prod',
    ): self {
        $parsed = self::parseTrustedKeys($trustedKeysEnv);
        return new self($parsed, $requireSignature, $logger, $appEnv);
    }

    /**
     * @return array<string,string>
     */
    public static function parseTrustedKeys(string $env): array
    {
        $out = [];
        foreach (preg_split('/[;\n]/', $env) ?: [] as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $eq = strpos($token, '=');
            if ($eq === false) {
                continue;
            }
            $keyId = trim(substr($token, 0, $eq));
            $key = trim(substr($token, $eq + 1));
            if ($keyId === '' || $key === '') {
                continue;
            }
            $out[$keyId] = $key;
        }
        return $out;
    }

    /**
     * Returns a clone of this verifier whose trusted-key map has been
     * augmented with one extra `(keyId, base64PublicKey)` pair. Used by
     * the inspect-archive trust-helper flow so an operator can pass a
     * publisher's public key as a per-request override without
     * mutating the env-resolved baseline.
     *
     * Semantics:
     *
     *   - The original verifier instance is left untouched (the
     *     property is `readonly`); a fresh `PluginSignatureVerifier`
     *     is returned.
     *   - Env-resolved keys win on duplicate `keyId`. An operator
     *     should not be able to shadow an env-pinned production key
     *     via a single inspect-archive request — otherwise a
     *     compromised admin session could silently swap in a forged
     *     publisher key. When the override carries a `keyId` already
     *     present in the env-resolved set, the override is dropped
     *     and a `warning`-level log line records the attempt.
     *
     * The base64 / 32-byte sanity-checks live in the controller layer
     * (so we can return a precise 400 before even building this
     * verifier). We accept the base64 string as-is here — the actual
     * `sodium_crypto_sign_verify_detached` call later still rejects
     * malformed material.
     */
    public function withAdditionalTrustedKey(string $keyId, string $publicKeyBase64): self
    {
        $merged = $this->trustedKeys;
        if (array_key_exists($keyId, $merged)) {
            $this->logger->warning(
                'Per-request trust-helper override for keyId "{keyId}" was ignored because the env-resolved trusted-keys set already contains an entry for it. Env keys win on duplicate keyIds.',
                ['keyId' => $keyId],
            );
        } else {
            $merged[$keyId] = $publicKeyBase64;
        }
        return new self(
            trustedKeys: $merged,
            requireSignature: $this->requireSignature,
            logger: $this->logger,
            appEnv: $this->appEnv,
        );
    }

    /**
     * Verify a `{keyId, signature, signedPayload}` triple plus the
     * declared `trustLevel` from the manifest.
     *
     * Optional `manifestPolicy` lets a plugin author tighten host
     * defaults via `security.signing` in `plugin.json`:
     *
     *   - `requireSignature` — when true, missing signatures are
     *     rejected even for untrusted plugins / dev hosts.
     *   - `acceptedKeyIds`   — list of keyIds the plugin will accept;
     *     a signature from a key outside this list is rejected even
     *     when the host trusts the key.
     *
     * The string `"dev"` keyId is treated as a development-only marker
     * and is refused outright for `official`/`reviewed` plugins on
     * `prod` / `test` hosts so a scratch key cannot accidentally ship
     * to production. On `APP_ENV=dev` the bypass is allowed for any
     * trust level so plugin authors can self-install a development
     * build without setting up a real CI keypair.
     *
     * @param array{requireSignature?: bool, acceptedKeyIds?: list<string>} $manifestPolicy
     *
     * @throws PluginSignatureException on any verification failure.
     */
    public function verify(
        string $trustLevel,
        ?string $keyId,
        ?string $signatureBase64,
        ?string $signedPayload,
        array $manifestPolicy = [],
    ): void {
        $isUntrusted = $trustLevel === 'untrusted';
        $missing = $keyId === null || $keyId === ''
            || $signatureBase64 === null || $signatureBase64 === ''
            || $signedPayload === null || $signedPayload === '';
        $pluginRequiresSignature = !empty($manifestPolicy['requireSignature']);
        $acceptedKeyIds = array_values(array_filter(
            $manifestPolicy['acceptedKeyIds'] ?? [],
            'is_string',
        ));

        if ($missing) {
            if ($this->appEnv === 'dev' && !$pluginRequiresSignature) {
                $this->logger->warning(
                    'Plugin signature missing; allowed for local APP_ENV=dev plugin installation.',
                    ['trustLevel' => $trustLevel],
                );
                return;
            }
            if (!$this->requireSignature && !$pluginRequiresSignature && $isUntrusted) {
                $this->logger->warning(
                    'Plugin signature missing; allowed because SELFHELP_PLUGIN_REQUIRE_SIGNATURE=false and trustLevel=untrusted.',
                );
                return;
            }
            throw new PluginSignatureException(sprintf(
                'Plugin signature missing (keyId/signature/signedPayload required for trustLevel="%s"%s).',
                $trustLevel,
                $pluginRequiresSignature ? ' and the plugin manifest declares security.signing.required=true' : '',
            ));
        }

        // Reject the conventional dev/test key for non-untrusted
        // plugins on production / test hosts. APP_ENV=dev relaxes this
        // so plugin authors can self-install a development build of an
        // `official`/`reviewed` plugin without provisioning a real CI
        // keypair — the host still verifies the signature against the
        // `dev` public key in `SELFHELP_PLUGIN_TRUSTED_KEYS`.
        if (strtolower((string) $keyId) === 'dev' && !$isUntrusted && $this->appEnv !== 'dev') {
            throw new PluginSignatureException(sprintf(
                'Plugin signature keyId "dev" is reserved for local development and is not allowed for trustLevel="%s" on APP_ENV="%s". Publish with a real CI key, or develop the plugin on a host running APP_ENV=dev.',
                $trustLevel,
                $this->appEnv,
            ));
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new PluginSignatureException(
                'libsodium is not available on this host; cannot verify plugin signatures. Install ext-sodium.',
            );
        }

        if ($this->trustedKeys === []) {
            if (!$this->requireSignature && !$pluginRequiresSignature && $isUntrusted) {
                $this->logger->warning(
                    'No SELFHELP_PLUGIN_TRUSTED_KEYS configured; skipping signature verification (untrusted plugin, requireSignature=false).',
                );
                return;
            }
            throw new PluginSignatureException(
                'No SELFHELP_PLUGIN_TRUSTED_KEYS configured; refusing to install a signed plugin without a key to verify against.',
            );
        }

        $publicKeyB64 = $this->trustedKeys[$keyId] ?? null;
        if ($publicKeyB64 === null) {
            throw new PluginSignatureException(sprintf(
                'Plugin signature keyId "%s" is not in SELFHELP_PLUGIN_TRUSTED_KEYS. Add the publisher\'s public key (keyId=base64pubkey) to the env.',
                $keyId,
            ));
        }

        if ($acceptedKeyIds !== [] && !in_array($keyId, $acceptedKeyIds, true)) {
            throw new PluginSignatureException(sprintf(
                'Plugin signature keyId "%s" is not in the manifest\'s security.signing.acceptedKeyIds list (%s).',
                $keyId,
                implode(', ', $acceptedKeyIds),
            ));
        }

        $publicKey = base64_decode($publicKeyB64, true);
        $signature = base64_decode($signatureBase64, true);
        if ($publicKey === false || $signature === false) {
            throw new PluginSignatureException('Plugin signature or trusted-key is not valid base64.');
        }
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new PluginSignatureException(sprintf(
                'Trusted public key for "%s" is %d bytes (expected %d).',
                $keyId,
                strlen($publicKey),
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            ));
        }
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new PluginSignatureException(sprintf(
                'Plugin signature is %d bytes (expected %d).',
                strlen($signature),
                SODIUM_CRYPTO_SIGN_BYTES,
            ));
        }

        try {
            $ok = sodium_crypto_sign_verify_detached($signature, $signedPayload, $publicKey);
        } catch (\SodiumException $e) {
            throw new PluginSignatureException('Plugin signature verification raised: ' . $e->getMessage(), 0, $e);
        }
        if (!$ok) {
            throw new PluginSignatureException(sprintf(
                'Plugin signature does not match the trusted key for keyId "%s".',
                $keyId,
            ));
        }
    }
}
