<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# SelfHelp Backend Documentation

Audience: developers, technical operators, plugin authors, and CMS administrators.
Status: active documentation index.
Applies to: SelfHelp2 Symfony backend docs in this repository.
Last verified: 2026-06-03.
Source of truth: runtime backend code, migrations, JSON schemas, `AGENTS.md`, and the linked docs below.

Use this page as the navigation entrypoint for backend documentation. The current docs are still partly organized by history; new or substantially rewritten docs should follow the audience-based placement rules in `AGENTS.md`.

## Start Here

| Need | Read |
| --- | --- |
| Backend architecture and development workflow | [developer/README.md](developer/README.md) |
| Public and admin API examples | [api-usage/README.md](api-usage/README.md) |
| Plugin ecosystem architecture and contracts | [plugins/architecture.md](plugins/architecture.md) |
| Cross-repo compatibility rules | [developer/cross-repo-compatibility-matrix.md](developer/cross-repo-compatibility-matrix.md) |
| Testing and quality gates | [developer/15-testing-guidelines.md](developer/15-testing-guidelines.md) |

## Current Documentation Map

| Current location | Purpose | Future placement rule |
| --- | --- | --- |
| `docs/developer/` | Technical backend architecture, workflow, testing, deployment, compatibility, and performance notes. | Keep as `docs/developer/`. |
| `docs/plugins/` | Canonical plugin architecture, schemas, trust/security, publishing, registry, install, and compatibility docs. | Keep as canonical plugin docs unless a move is explicitly coordinated. |
| `docs/api-usage/` | API usage examples and endpoint walkthroughs. | Move gradually toward `docs/reference/api/` only after links are updated. |
| `docs/*.md` | Older standalone feature, API, operations, and project notes. | Re-home gradually into `developer`, `user`, `reference`, `operations`, or `archive`. |
| `db/legacy/README.md` | Deprecated SQL dump/reference notes. | Keep beside the deprecated legacy files. |

## New Documentation Placement

| Folder | Use for |
| --- | --- |
| `docs/developer/` | Architecture, implementation patterns, testing, performance, and engineering workflow. |
| `docs/user/` | Non-technical CMS/admin/operator feature guides and task walkthroughs. |
| `docs/reference/` | Exact API contracts, schemas, tables, config keys, generated catalogs, and compatibility matrices. |
| `docs/cookbook/` | Step-by-step recipes for adding or changing common backend capabilities. |
| `docs/operations/` | Installation, deployment, runbooks, recovery, secrets setup, and environment operations. |
| `docs/archive/` | Historical implementation notes, completed project plans, and superseded summaries. |

When moving existing docs, update all repository-relative links in the same change and prefer small batches over broad rewrites.
