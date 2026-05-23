<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Plugin distribution

A SelfHelp plugin reaches an admin host through exactly one of four
**install sources**. All four funnel through the same backend pipeline
(`ManifestResolver` → `PluginSignatureVerifier` →
`PluginInstaller::request()` → `InstallPluginMessage` → Messenger
worker → Composer → migrations → finalize). There is no parallel
installer; if you find yourself writing one, stop and use these.

## 1. Public / private registry (recommended)

Each `PluginSource` row holds a base URL pointing at a `registry.json`
file. Hosts merge every enabled source on each refresh of the
**Available** tab. The official public registry is seeded at
<https://humdek-unibe-ch.github.io/sh2-plugin-registry/>.

A registry entry is signed independently; the host re-derives the
canonical signed payload from the entry's manifest + runtime URLs +
checksums and verifies the bundled signature against
`SELFHELP_PLUGIN_TRUSTED_KEYS` before dispatching the install.

Use this source for almost every install: it is one click in the UI,
fully signed, fully checksummed, and the registry tells the host both
the Composer coordinates and the runtime ESM URL.

## 2. Direct manifest URL

Paste a URL pointing at a published `plugin.json` (or omit the
registry entirely). The host fetches the manifest, validates it
against the canonical schema, re-derives the signed payload, and
verifies the signature when present. Used mostly for private hosts
that do not run their own registry.

## 3. `.shplugin` archive upload

The **main manual install path**. A single signed ZIP containing the
manifest + runtime artifacts + checksums + signature. The admin
uploads it through the UI (drag-and-drop or file picker); the host
inspects it (`POST /admin/plugins/inspect-archive`) and shows a
preview card before the actual install. See
[`shplugin-archive.md`](./shplugin-archive.md) for the full layout
and pipeline.

Use this for:

- Air-gapped hosts with no outbound HTTPS to the registry.
- One-off installs of a custom plugin not published in any registry.
- Offline-first installs where the runtime ESM + CSS must live on the
  same origin as the CMS shell.

## 4. Paste JSON (Developer / debugging only)

A Monaco editor inside the install modal. Skips signature
verification. Explicitly labelled "developer / debugging only" in the
UI. Used for hand-editing manifests during plugin development; not a
recommended production install path.

## Publishing flow

Plugin authors publish through the canonical script trio shipped in
every plugin repo:

| Script                                  | Role                                                                          |
| --------------------------------------- | ----------------------------------------------------------------------------- |
| `scripts/build-shplugin.{ps1,sh,mjs}`   | Build + sign the `.shplugin`.                                                 |
| `scripts/publish-to-registry.{ps1,sh}`  | Reuse the signed payload to update `registry.json`, copy the manifest +       |
|                                         | runtime artifacts under `artifacts/<id>-<version>/`, and create a GitHub      |
|                                         | Release with the `.shplugin` attached.                                        |
| `scripts/install-local.{ps1,sh}`        | Local-dev convenience: upload the `.shplugin` to a localhost host, or the    |
|                                         | `--symlink` fast-path that wires a Composer path repo and a Vite dev server. |

The GitHub Actions workflow `.github/workflows/publish-to-registry.yml`
runs on `v*` tags: build → sign → upload artifact → register in the
registry repo → create the GH Release.

## Registry repo layout

```
sh2-plugin-registry/
├── registry.json                       (canonical pluginEntry list)
├── plugin-registry.schema.json         (mirror of the canonical schema in sh-selfhelp_backend/docs/plugins/)
├── plugin-manifest.schema.json         (mirror of the canonical schema in sh-selfhelp_backend/docs/plugins/)
├── manifests/
│   └── <plugin-id>-<version>.json      (canonical plugin.json snapshot)
├── artifacts/
│   └── <plugin-id>-<version>/          (runtime ESM + CSS, served by GH Pages)
│       ├── plugin.esm.js
│       └── plugin.css
└── scripts/
    ├── sign.mjs                        (canonical payload + Ed25519 signer)
    └── build-registry-entry.mjs        (assembles a signed pluginEntry)
```

The build-registry GitHub Pages workflow validates every push.
Entries without a `composer`, `runtime`, `checksums`, `signature`,
`signedPayload`, or `keyId` are rejected.

## Versioning

| Version diff                | Carry a Doctrine migration? | Host behaviour                                          |
| --------------------------- | --------------------------- | ------------------------------------------------------- |
| patch (`1.0.0` → `1.0.1`)   | No (rejected if present)    | One-click update.                                       |
| minor (`1.0.x` → `1.1.0`)   | Yes (required)              | One-click update. The handler runs `doctrine:migrate`. |
| major (`1.x` → `2.0`)       | Allowed                     | UI flags as "Force update", explicit confirm required. |

The Updates tab cross-references installed plugins against every
enabled source and surfaces upgradeable rows; the Update button
dispatches the same `UpdatePluginMessage` regardless of the diff
kind, with `forceMajor=true` set for breaking upgrades.
