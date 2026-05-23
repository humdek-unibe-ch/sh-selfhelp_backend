<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Plugin signing (Ed25519)

Every plugin install — whether it arrives via a public registry, a
direct manifest URL, a `.shplugin` archive upload, or a paste-JSON
developer flow — passes through the same signature verification
pipeline. The signature attests that a specific canonical payload
(plugin id + version + composer coordinates + runtime URLs +
checksums + compatibility) was approved by the publisher's CI.

## Trust model

- **Algorithm**: Ed25519 (libsodium `crypto_sign_detached`).
- **Key storage**: every host environment lists trusted publisher
  public keys in `SELFHELP_PLUGIN_TRUSTED_KEYS` as a comma-separated
  list of `<keyId>;<base64-public-key>` entries.
- **Key rotation**: the registry-entry schema includes `keyId`. Hosts
  may trust multiple key ids simultaneously, so rotation is a matter
  of adding the new key to `SELFHELP_PLUGIN_TRUSTED_KEYS`, switching
  CI to sign with the new key, and (after a grace period) removing
  the old key.
- **CI-only signing**: plugin authors never run signing locally for
  production releases. The publish workflow pulls
  `SELFHELP_PLUGIN_SIGNING_KEY` and `SELFHELP_PLUGIN_SIGNING_KEY_ID`
  from GitHub Actions secrets and runs `scripts/build-shplugin.mjs`
  + `scripts/publish-to-registry.sh`. Local dev uses
  `SELFHELP_PLUGIN_DEV_SIGNING_KEY` which always emits `keyId=dev`;
  the host rejects `keyId=dev` on the `official` channel.

## Canonical signed payload

The signed bytes are a deterministically-sorted JSON document
containing:

```json
{
  "checksums": {
    "frontendEsm": "<sha256-hex>",
    "frontendCss": "<sha256-hex>?"      // optional
  },
  "compatibility": { "selfhelp": "...", "php": "...", … },
  "composer": {
    "package": "humdek/<id>",
    "version": "1.0.0",
    "repository": { "type": "vcs|path|composer|git", "url": "...", "reference": "<sha>" }  // optional
  },
  "manifestUrl": "manifests/<id>-<version>.json",   // optional
  "pluginId": "<id>",
  "runtime": {
    "entrypointUrl": "artifacts/<id>-<version>/plugin.esm.js",
    "format": "esm",
    "stylesheetUrl": "artifacts/<id>-<version>/plugin.css",  // optional
    "integrity": "sha384-...",                               // optional
    "stylesheetIntegrity": "sha384-..."                      // optional
  },
  "version": "1.0.0"
}
```

Rules:

- All keys are emitted in **lexical** order at every depth.
- Strings are JSON-encoded with the same escaping rules as
  `JSON.stringify`.
- Optional fields are omitted entirely when not set (never `null`,
  never `""`).
- The output has **no surrounding whitespace** and uses minimal
  JSON syntax (no pretty printing).

The PHP implementation (`src/Plugin/Security/SignedPayloadBuilder.php`)
and the Node implementation (`sh2-plugin-registry/scripts/sign.mjs
build-payload`) produce **byte-identical** output. The cross-impl
fixture test in `tests/Plugin/Security/SignedPayloadBuilderTest.php`
enforces parity for every published plugin shape.

## Verification flow

When the host receives an install/update request:

1. `ManifestResolver` resolves the source (registry, URL, archive,
   paste) into a `ResolvedSource` DTO.
2. It re-builds the canonical signed payload from the manifest +
   resolved checksums and asserts byte-equality with the
   `signedPayload` shipped on the entry.
3. `PluginSignatureVerifier::verify($signedPayload, $signature,
   $keyId)` looks up `$keyId` in
   `SELFHELP_PLUGIN_TRUSTED_KEYS`, base64-decodes the matching public
   key, and calls
   `sodium_crypto_sign_verify_detached($signature,$signedPayload,$key)`.
4. On failure: 400 with the specific reason (unknown keyId,
   payload-mismatch, bad-signature). The install is blocked and no
   `plugin_operations` row is dispatched.

## Signing policy on the manifest

A plugin may pin its expected signing posture in
`security.signing`:

```json
"security": {
  "signing": {
    "required": true,
    "acceptedKeyIds": ["humdek-prod-2026"]
  }
}
```

- `required` — when `true`, the host refuses to install without a
  verified signature, even if the env-wide
  `SELFHELP_PLUGIN_REQUIRE_SIGNATURE=0`.
- `acceptedKeyIds` — when non-empty, only signatures issued by one
  of these `keyId`s are accepted. Useful for self-managed private
  plugins that should not be accepted from the public Humdek key.

## Operator playbook

| Scenario                                         | Action                                                                                     |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------ |
| First-time host setup                            | Seed `SELFHELP_PLUGIN_TRUSTED_KEYS=humdek-prod;<base64-public-key>` from the registry repo.|
| Rotate signing key                               | Add new `keyId;<base64>` entry. Switch CI secret. After 30 days, remove old entry.        |
| Allow developer-mode unsigned installs           | Set `SELFHELP_PLUGIN_REQUIRE_SIGNATURE=0` + use `paste` source. Never do this in prod.    |
| Build a `.shplugin` locally (dev key)            | `export SELFHELP_PLUGIN_DEV_SIGNING_KEY=<base64>` then `node scripts/build-shplugin.mjs`. |
| Generate a new keypair                           | `cd sh2-plugin-registry && npm run keygen`. Store the private key as a GH secret.         |
