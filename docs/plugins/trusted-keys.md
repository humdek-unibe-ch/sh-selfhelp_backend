<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# `SELFHELP_PLUGIN_TRUSTED_KEYS`

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
matching private key (`SELFHELP_PLUGIN_SIGNING_KEY` in
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

## Dev mode (`keyId=dev`)

`SELFHELP_PLUGIN_DEV_SIGNING_KEY` is the local-developer fallback for
`scripts/build-shplugin.mjs`. It always emits `keyId=dev`. To accept
those archives on a dev host:

```
SELFHELP_PLUGIN_TRUSTED_KEYS=dev;<base64-of-the-matching-public-key>
```

Production hosts **must not** include `dev` in their trusted set. The
host also refuses `keyId=dev` on registry sources with
`trust_level=official` regardless of the trusted-keys configuration.

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
