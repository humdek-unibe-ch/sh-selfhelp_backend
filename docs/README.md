<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# SelfHelp Backend Documentation

Audience: Developers, technical operators, plugin authors, and CMS administrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-29.
Source of truth: Runtime backend code, migrations, JSON schemas, `AGENTS.md`, and the linked docs below.

Navigation entrypoint for the backend documentation. Docs are organized by audience and purpose per the Documentation Rules in `AGENTS.md`.

## Start here

| Need | Read |
| --- | --- |
| Install, update, and maintain a deployment (the big map) | [operations/platform-and-plugin-ecosystem.md](operations/platform-and-plugin-ecosystem.md) |
| Publish a release end-to-end (tags, registry, versions, manager) | [operations/ecosystem-release-and-update-runbook.md](operations/ecosystem-release-and-update-runbook.md) |
| Backend architecture and workflow | [developer/index.md](developer/index.md) |
| Edit content as a non-technical admin (variables, data naming, emails) | [user/README.md](user/README.md) |
| Public and admin API usage | [reference/api/index.md](reference/api/index.md) |
| Plugin ecosystem architecture and contracts | [plugins/architecture.md](plugins/architecture.md) |
| Cross-repo compatibility rules | [developer/cross-repo-compatibility-matrix.md](developer/cross-repo-compatibility-matrix.md) |
| Testing and quality gates | [developer/15-testing-guidelines.md](developer/15-testing-guidelines.md) |

## Documentation map

| Folder | Use for |
| --- | --- |
| [developer/](developer/index.md) | Backend architecture, CMS internals, services, testing, performance, and engineering workflow. |
| [reference/](reference/index.md) | Exact API contracts, the `api_routes` table, style/email-style contracts, and endpoint usage guides. |
| [user/](user/README.md) | Non-technical guides for CMS administrators and content editors (variables, data naming, email templates). |
| [operations/](operations/index.md) | Runtime configuration and operational runbooks. |
| [plugins/](plugins/architecture.md) | Canonical plugin architecture, schemas, trust/security, publishing, registry, and compatibility. |
| [archive/](archive/index.md) | Historical plans and superseded notes, kept for reference only. |
| `ai/` | AI section-generation prompt source (`ai/prompt_template_base.md`), consumed by `PromptTemplateService`. |

## Conventions

- Every active doc starts with the metadata block (`Audience`, `Status`, `Applies to`, `Last verified`, `Source of truth`).
- Filenames use lowercase kebab-case; this file (`README.md`) is the only uppercase docs entrypoint. Subfolder indexes are `index.md`.
- Runtime code, migrations, and JSON schemas are the source of truth. When a doc conflicts with the code, the code wins and the doc is corrected or archived.
- `docs/plugins/` is the canonical plugin documentation area and keeps its existing filenames.
