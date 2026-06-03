# SelfHelp Plugin Documentation

Audience: Plugin authors and backend developers.
Status: active.
Applies to: SelfHelp2 plugin ecosystem (backend host side).
Last verified: 2026-06-03.
Source of truth: Plugin layer code in `src/Plugin`, the schemas in this folder, and `AGENTS.md`.

This is the canonical plugin documentation area. Filenames here are preserved because they are referenced from backend source comments and other repos.

## Start here

- [architecture.md](architecture.md) - Plugin ecosystem architecture and the host extension surfaces.
- [developer-guide.md](developer-guide.md) - Building a plugin against the host.
- [installation.md](installation.md) - Installing plugins into a host.

## Security and trust

- [security-model.md](security-model.md)
- [trust-levels.md](trust-levels.md)
- [capabilities.md](capabilities.md)
- [signing.md](signing.md)
- [trusted-keys.md](trusted-keys.md)
- [gdpr-and-data-ownership.md](gdpr-and-data-ownership.md)

## Install, operations, and lifecycle

- [install-modes.md](install-modes.md)
- [plugin-operations-and-rollback.md](plugin-operations-and-rollback.md)
- [lock-file.md](lock-file.md)
- [shplugin-archive.md](shplugin-archive.md)
- [feature-flags.md](feature-flags.md)
- [lookups.md](lookups.md)

## Distribution and registry

- [distribution.md](distribution.md)
- [registry-and-channels.md](registry-and-channels.md)
- [publishing-workflow.md](publishing-workflow.md)
- [versioning-and-compatibility.md](versioning-and-compatibility.md)
- [ci-workflows.md](ci-workflows.md)

## Runtime and integration

- [runtime-frontend-loading.md](runtime-frontend-loading.md)
- [realtime-and-no-polling.md](realtime-and-no-polling.md)
- [mobile-plugins.md](mobile-plugins.md)
- [testing-matrix.md](testing-matrix.md)

## Reference (schemas and templates)

- [plugin-manifest.schema.json](plugin-manifest.schema.json)
- [plugin-lock.schema.json](plugin-lock.schema.json)
- [plugin-registry.schema.json](plugin-registry.schema.json)
- [multi-repo-agents-md.md](multi-repo-agents-md.md)
- [plugin-repo-agents-md-template.md](plugin-repo-agents-md-template.md)

## Example plugin

- [surveyjs-plugin.md](surveyjs-plugin.md) - The reference SurveyJS plugin.
