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

- Symfony backend: `D:\TPF\SelfHelp\sh-selfhelp_backend` → [AGENTS.md](../../AGENTS.md)
- Next.js frontend: `D:\TPF\SelfHelp\sh-selfhelp_frontend` → `AGENTS.md`
- Shared package: `D:\TPF\SelfHelp\sh-selfhelp_shared` → `AGENTS.md`
- Expo mobile app: `D:\TPF\SelfHelp\sh-selfhelp_mobile` → `AGENTS.md`
- Old CMS reference repo (read-only research): `D:\TPF\SelfHelp\sh-selfhelp`
- Future plugin repos under `D:\TPF\SelfHelp\plugins\` (first one: `sh2-shp-survey-js`).

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

- `D:\TPF\SelfHelp\plugins\sh2-shp-survey-js\frontend\src\styles\Surveyjs.tsx` — read the plugin repo's `AGENTS.md`.
- `D:\TPF\SelfHelp\sh-selfhelp_shared\src\registry\styles.registry.ts` — re-read the shared package `AGENTS.md` before editing.
- `D:\TPF\SelfHelp\sh-selfhelp_frontend\src\app\components\frontend\styles\BasicStyle.tsx` (only if the host dispatcher signature changes) — re-read the frontend `AGENTS.md`.

The mobile repo is not touched in this example, so its `AGENTS.md` does not need to be re-read.
