<!--
SPDX-FileCopyrightText: 2026 Humdek, University of Bern
SPDX-License-Identifier: MPL-2.0
-->

# Multi-Repository AGENTS.md Rule

This is the canonical version of the rule. Every host repository's own `AGENTS.md` references this document and restates the headline rule so it is visible without leaving the repo.

## Critical execution rule

This project is multi-repository. The AI agent must always obey the `AGENTS.md` of the repository whose files it is editing, regardless of where the agent was started.

## Repositories in scope

The SelfHelp plugin ecosystem touches at least these repositories:

| Repository                  | Role                          | Location                                                        |
|-----------------------------|-------------------------------|-----------------------------------------------------------------|
| `sh-selfhelp_backend`       | Symfony backend host          | `<workspace>/sh-selfhelp_backend` ([AGENTS.md](../../AGENTS.md)) |
| `sh-selfhelp_frontend`      | Next.js frontend host         | `<workspace>/sh-selfhelp_frontend` (`AGENTS.md`)                |
| `sh-selfhelp_shared`        | Shared TypeScript SDK         | `<workspace>/sh-selfhelp_shared` (`AGENTS.md`)                  |
| `sh-selfhelp_mobile`        | Expo / React Native mobile    | `<workspace>/sh-selfhelp_mobile` (`AGENTS.md`)                  |
| `sh-selfhelp` (deprecated)  | Old PHP CMS, read-only        | optional sibling checkout                                       |
| `plugins/<plugin-id>/`      | Individual plugin repositories | sibling sub-folder under `<workspace>/plugins/` (first one: `sh2-shp-survey-js`) |

`<workspace>` is the directory the operator clones the repositories into. Do not encode an absolute path — the layout works the same on any developer machine, CI runner, or container that follows this sibling-folder convention. Operators may override the paths in the git-ignored `AGENTS.local.md` file inside each repo if they need to record machine-specific locations.

## Mandatory rules

Applies even if the agent was started from only one repo.

1. Locate and read the target repo's own `AGENTS.md` before changing any file in that repo.
2. Follow that repo's coding rules, architecture rules, naming rules, validation commands, testing commands, and migration rules.
3. Do not assume rules from one repo apply to another.
4. When switching from one repo to another, re-read the target repo's `AGENTS.md`.
5. Mention in the implementation summary which repositories will be changed and which `AGENTS.md` files were read.
6. If a repository has no `AGENTS.md`, explicitly state this and ask before making broad changes.
7. If two repositories have conflicting rules, follow the rule from the repository being modified.
8. If generated code is shared between repos, make sure it satisfies the rules of all affected repositories.
9. For future plugin repositories, create an `AGENTS.md` file as part of the plugin template (see `plugin-repo-agents-md-template.md`).

## Required-before-coding checklist

Run through this list every phase and every PR that touches more than one repo:

- [ ] Identify all repositories affected by the task.
- [ ] Read `AGENTS.md` in each affected repository.
- [ ] Summarize relevant rules per repository.
- [ ] Confirm planned file changes per repository.
- [ ] Apply changes repo-by-repo.
- [ ] Run validation commands from the matching repository.
- [ ] Do not mix backend, frontend, shared, mobile, and plugin rules.

## Worked example

A task to add a CMS style to the SurveyJS v2 plugin's frontend bundle requires editing:

- `plugins/sh2-shp-survey-js/frontend/src/styles/Surveyjs.tsx` — read the plugin repo's `AGENTS.md`.
- `sh-selfhelp_shared/src/registry/styles.registry.ts` — re-read the shared package `AGENTS.md` before editing.
- `sh-selfhelp_frontend/src/app/components/frontend/styles/BasicStyle.tsx` (only if the host dispatcher signature changes) — re-read the frontend `AGENTS.md`.

The mobile repo is not touched in this example, so its `AGENTS.md` does not need to be re-read.

All paths above are repository-relative (within `<workspace>`). Never hard-code absolute paths in committed documentation, scripts, or code.
