<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# `SELFHELP_PLUGIN_TRUSTED_KEYS`

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-03.
Source of truth: Plugin layer code and the schemas under this folder.

The host validates every plugin installation against a set of
trusted Ed25519 public keys configured in the environment. The set is
read at boot, cached, and re-read when the host process restarts.

## Format

`SELFHELP_PLUGIN_TRUSTED_KEYS` is a comma-separated list of
`<keyId>;<base64-public-key>` pairs:

```
SELFHELP_PLUGIN_TRUSTED_KEYS=humdek-prod-2026;MCowBQYDK2VwAyEAYg…==,humdek-staging;MCowBQYDK2VwAyEAGv…==
```

- `<keyId>` matches the `keyId` field on every registry entry,
  `signature.json`, and the plugin's `security.signing.acceptedKeyIds`
  list.
- `<base64-public-key>` is the libsodium Ed25519 public key
  (`crypto_sign_PUBLICKEYBYTES = 32`) base64-encoded.

## Seeded official key

Every host ships with the official Humdek public key under the keyId
`humdek-prod-2026`. The publisher's CI signs releases with the
matching private key (`SELFHELP_SIGNING_KEY` in
[`sh2-plugin-registry`](https://github.com/humdek-unibe-ch/sh2-plugin-registry)
+ every plugin's `.github/workflows/publish-to-registry.yml`).

Operators may rotate this key by:

1. Generating a new keypair in the registry repo
   (`npm run keygen`).
2. Adding the new public key to every host's
   `SELFHELP_PLUGIN_TRUSTED_KEYS` (keep the previous key during the
   grace period).
3. Updating the registry's signing CI secret to the new private key.
4. Removing the previous public key from every host once all
   in-flight signed entries have been re-signed with the new key.

## Per-host private trust

Hosts that publish their own private plugins seed an additional key:

```
SELFHELP_PLUGIN_TRUSTED_KEYS=humdek-prod-2026;<base64>,my-org-prod;<base64>
```

The corresponding plugins set
`security.signing.acceptedKeyIds = ["my-org-prod"]` so the host
refuses to accept those plugins from any other source (defence in
depth against a registry compromise that swaps a manifest URL).

## Per-request trust helper (admin UI)

The admin UI exposes a **one-off** trust helper inside
**Plugins → Install plugin → Upload .shplugin**. When an uploaded
archive is signed by a `keyId` that is not in
`SELFHELP_PLUGIN_TRUSTED_KEYS`, the inspect-archive response carries
a `signature.unknownKey.keyId` field and the preview shows a yellow
**Unknown publisher key** panel. The operator can:

1. Paste the publisher's base64 Ed25519 public key into the panel's
   textarea (the publisher must share this out of band — email,
   SFTP, an internal portal — never inside the `.shplugin` itself).
2. Click **Re-test with this key**. The host re-runs
   `inspect-archive` with the override. Verification uses an
   in-memory `PluginSignatureVerifier` that merges the pasted key
   on top of the env-resolved trusted set. If the signature
   validates, the preview flips to `signature.status=verified` and
   the **Install** button enables.
3. (Optional) Click **Copy env line** to put the canonical
   `SELFHELP_PLUGIN_TRUSTED_KEYS=<keyId>=<base64>` snippet on the
   clipboard.

> **Not persistent across requests.** The override applies only to
> the single inspect-archive call that carried it. The host neither
> writes to `.env*` files nor mutates the cached trusted-keys map.
> Restarting the host throws the override away. To make the trust
> persist, paste the env line into `.env.local` (merge with existing
> entries using the comma + semicolon format from the *Format*
> section above) and restart the host process.

### Precedence rules

The override is intentionally limited:

- `keyId` collisions with the env-resolved set are **silently
  ignored** — env keys win. An operator cannot shadow an
  env-pinned production key by submitting a different public key
  for the same `keyId` via a single request. The host logs the
  ignored override at warning level.
- The override is only useful for `signature.status=invalid` failures
  whose root cause is "keyId not in the trusted set". Tampering,
  payload mismatch, malformed signatures, missing `signature.json`,
  etc. still fail with `signature.unknownKey: null` and the helper
  panel does **not** appear — those are not safely recoverable by
  adding a key.
- Malformed override input (non-base64 string, base64 that does not
  decode to 32 bytes, only one of `trustedKeyId` / `trustedKeyBase64`
  supplied) is rejected with HTTP 400 before the verifier sees it.

### When NOT to use the helper

- For routinely accepted publishers — pin them in
  `SELFHELP_PLUGIN_TRUSTED_KEYS` and skip the per-request pasting.
- For untrusted (`security.trustLevel=untrusted`) plugins — the
  host already accepts those without signature verification.

## Dev mode (`keyId=dev`)

`SELFHELP_PLUGIN_DEV_SIGNING_KEY` is the local-developer fallback for
`scripts/build-shplugin.mjs`. It always emits `keyId=dev`. To accept
those archives on a dev host:

```
SELFHELP_PLUGIN_TRUSTED_KEYS=dev=<base64-of-the-matching-public-key>
```

> Format note: `keyId=base64Public` separated by `=`; multiple
> publishers separated by `;`. The pre-`=` token is the `keyId`, the
> post-`=` token is the 32-byte Ed25519 public key in base64.

The `keyId="dev"` bypass for `official`/`reviewed` plugins is gated
on `APP_ENV`:

- `APP_ENV=dev` — the host accepts `keyId="dev"` for any trust level
  as long as `SELFHELP_PLUGIN_TRUSTED_KEYS` contains a matching
  `dev=<base64>` entry. This is the "I'm developing my own
  `official` plugin locally" workflow.
- `APP_ENV=prod` / `APP_ENV=test` — the host refuses `keyId="dev"` on
  `official`/`reviewed` plugins regardless of trusted-keys
  configuration. Untrusted plugins remain installable with `dev`
  signing.

Production hosts **must not** include `dev` in their trusted set.

## Failure modes

| Scenario                                  | Behaviour                                                                  |
| ----------------------------------------- | -------------------------------------------------------------------------- |
| `SELFHELP_PLUGIN_TRUSTED_KEYS` unset      | The host loads with `require_signature=false`. Untrusted-only installs.   |
| keyId in entry not in env                 | 400 `signature key not trusted (keyId=<id>)`. Install blocked.            |
| Bad base64 / wrong length                 | Boot warning + the offending entry is skipped. Other keys still trusted.  |
| Duplicate keyId with different keys       | Boot warning + the last entry wins. Audit the env immediately.            |

## Listing the trusted keys at runtime

```bash
php bin/console selfhelp:plugin:doctor --json | jq '.trustedKeys'
```

Returns the active keyId set so an operator can compare against the
expected registry signers.
