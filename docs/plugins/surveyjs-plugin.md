# SurveyJS Plugin (`sh2-shp-survey-js`)

The SurveyJS v2 plugin is the reference plugin for the SelfHelp plugin ecosystem. It demonstrates the full surface of the platform: a Symfony backend bundle, a Mantine-themed Next.js frontend, a read-only mobile renderer, manifest-driven permissions, lookup contributions, realtime topics, feature flags, and a health check.

This document focuses on plugin-specific behaviour. For ecosystem-wide concepts, read `architecture.md`, `installation.md`, and `developer-guide.md` first.

## 1. Capabilities

| Capability                         | Implementation                                                                 |
| ---------------------------------- | ------------------------------------------------------------------------------ |
| Author + edit surveys              | Admin Designer page using `survey-creator-react`.                              |
| Publish versions                   | `SurveyService::publishNewVersion()` writes immutable `SurveyVersion` rows.   |
| Submit surveys                     | Public `surveyjs` style + `SurveyResponseService::submit()`.                  |
| Save answers to existing storage   | `SurveyAnswerNormalizer` + `DataTableWriterInterface` (host-provided).         |
| HTML sanitization on save          | `SurveyJsHtmlSanitizer` (whitelist DOM walker).                                |
| Dashboard + recent responses       | `SurveyDashboardService` + Mercure topic `surveys/{id}/responses`.            |
| Collaborative editing notifications| Mercure topic `surveys/{id}/editing`.                                         |
| Standalone GPX field rendering     | `gpxMap` style with Leaflet preview.                                          |
| Optional custom question types     | Behind feature flags: `rich-text`, `gpx`, `video`.                            |
| Read-only mobile experience        | `@humdek/sh2-shp-survey-js-mobile` + Open-on-Web fallback for editing.        |
| License-key management             | Admin-only `/license-key` endpoint reading `SURVEYJS_LICENSE_KEY`.            |
| Health check                       | `humdek.surveyjs.health_check` service.                                       |

## 2. Plugin metadata

| Property              | Value                                                |
| --------------------- | ---------------------------------------------------- |
| Plugin id             | `sh2-shp-survey-js`                                 |
| Trust level           | `official`                                           |
| Plugin API version    | `1.0`                                                |
| SelfHelp compatibility| `>=1.0.0 <2.0.0`                                     |
| Backend package       | `humdek/sh2-shp-survey-js` (`symfony-bundle`)        |
| Frontend package      | `@humdek/sh2-shp-survey-js` (npm)                   |
| Mobile package        | `@humdek/sh2-shp-survey-js-mobile` (npm)            |
| Permissions added     | `surveyjs.surveys.manage`, `surveyjs.surveys.view-responses`, `surveyjs.surveys.export-pdf` |
| Lookups owned         | `surveyJsTheme` (`plugin_owned`)                     |

## 3. Database schema

Owned by the plugin (see `Migrations/Version20260601100000.php`):

```text
survey                  id, id_plugins, name, key_slug (unique), theme_code, archived,
                        created_at, updated_at, id_current_version → survey_version

survey_version          id, id_survey → survey, revision (unique per survey),
                        definition (JSON), definition_sha256, created_at, created_by_user_id

survey_run              id, id_survey → survey, id_survey_version → survey_version,
                        id_user (FK to core users.id, plain int), id_data_row (FK to core data_rows.id),
                        status, started_at, completed_at, progress (JSON)

survey_answer_link      id, id_survey_run → survey_run,
                        question_name (unique per run), question_type,
                        id_data_cell (FK to core data_cells.id), sanitized_html
```

`survey_run.id_data_row` and `survey_answer_link.id_data_cell` reference core tables but are intentionally plain ints (not Doctrine associations) because the plugin must stay decoupled from the core form storage entity classes.

## 4. Public API

Base path: `/cms-api/v1/plugins/surveyjs`.

| Method | Path                          | Description                                                |
| ------ | ----------------------------- | ---------------------------------------------------------- |
| GET    | `/published/{key}`            | Fetch the published definition for the survey identified by `key_slug`. |
| POST   | `/published/{key}/submit`     | Submit an answer payload `{ answers: {...} }`.             |

Both endpoints are anonymous-friendly. The host's auth listener attaches the JWT payload to the request when present so `survey_run.id_user` is populated for logged-in respondents.

## 5. Admin API

Base path: `/cms-api/v1/admin/plugins/surveyjs`. All endpoints require the `surveyjs.surveys.manage` permission unless noted.

| Method | Path                                | Description                                  |
| ------ | ----------------------------------- | -------------------------------------------- |
| GET    | `/surveys`                          | List active surveys.                         |
| POST   | `/surveys`                          | Create a survey (and its first revision).    |
| GET    | `/surveys/{id}`                     | Fetch survey + current definition.           |
| PATCH  | `/surveys/{id}`                     | Update name / theme / archive flag.          |
| DELETE | `/surveys/{id}`                     | Delete a survey (cascade to versions / runs).|
| POST   | `/surveys/{id}/versions`            | Publish a new version of the definition.     |
| GET    | `/surveys/{id}/dashboard`           | Dashboard summary (counts + recent runs).    |
| GET    | `/surveys/{id}/responses`           | Lightweight list of recent runs.             |
| GET    | `/license-key`                      | Admin-only SurveyJS license-key lookup.      |
| GET    | `/health`                           | Plugin health report.                        |

## 6. Permission semantics

| Permission                            | Effect                                              |
| ------------------------------------- | --------------------------------------------------- |
| `surveyjs.surveys.manage`             | Create, edit, publish, archive, delete surveys.     |
| `surveyjs.surveys.view-responses`     | Read responses, dashboard, recent runs.             |
| `surveyjs.surveys.export-pdf`         | Generate PDF exports (feature-flagged).             |

The host's `ApiSecurityListener` enforces these via the routes' `permissions` field in `plugin.json`. Controllers never re-check.

## 7. Feature flags

Declared in the manifest and contributable through `featureFlags`:

| Key             | Default | Effect                                                      |
| --------------- | ------- | ----------------------------------------------------------- |
| `gpx`           | off     | Show the GPX question type in the Creator + runtime.        |
| `video`         | off     | Show the Video question type.                               |
| `rich-text`     | on      | Show the Tiptap rich-text question type + property editors. |
| `pdf-export`    | off     | Show the PDF export button on the Responses page.           |
| `dashboard`     | on      | Show the Dashboard page.                                    |
| `collab-editing`| on      | Subscribe to the editing topic + show presence.             |

## 8. Realtime topics

Both scoped under `selfhelp/plugin/sh2-shp-survey-js/`:

| Key                                  | Required permission                 | Payload events                          |
| ------------------------------------ | ----------------------------------- | --------------------------------------- |
| `surveys/{surveyId}/editing`         | `surveyjs.surveys.manage`           | `version_published`, `presence`        |
| `surveys/{surveyId}/responses`       | `surveyjs.surveys.view-responses`   | `response_submitted`                    |

No polling endpoints exist.

## 9. CSP and external hosts

The manifest declares only `img-src` entries for OpenStreetMap / Carto tile hosts (Leaflet preview in the GPX question type). No `script-src 'unsafe-eval'` exception is needed because the React SurveyJS packages do not call `Function()` strings at runtime.

```json
{
  "security": {
    "cspRules": {
      "img-src": [
        "https://*.tile.openstreetmap.org",
        "https://*.basemaps.cartocdn.com"
      ]
    },
    "externalHosts": [
      { "host": "tile.openstreetmap.org", "purpose": "Map tiles for GPX preview" },
      { "host": "basemaps.cartocdn.com", "purpose": "Map tiles for GPX preview" }
    ]
  }
}
```

## 10. Mantine theme bridge

The `frontend/src/theme/mantineBridge.ts` module produces SurveyJS theme JSON (`Model.applyTheme()`) from a `themeCode` (`default`, `modern`, `high-contrast`). The Creator and the runtime read the same bridge so the visual identity stays consistent between authoring and runtime.

The plugin's `surveyJsTheme` lookup table holds the available theme codes; admins can extend the list by writing a plugin migration that inserts more rows.

## 11. Mobile experience (v1)

The mobile package contributes a `surveyjs` style that renders questions read-only and shows a fallback "Open on web" button. Submissions are not supported in v1; the host renders unsupported question types via `OpenOnWebFallback`. A future v2 will use `survey-react-native` for native rendering.

## 12. License key

Set `SURVEYJS_LICENSE_KEY=…` in the backend `.env.local` (or your secret manager). The Designer page fetches it from `/cms-api/v1/admin/plugins/surveyjs/license-key` at boot and configures the Creator instance. The endpoint is admin-only; the value is never logged or echoed to non-admins.

## 13. Local-dev quickstart

```bash
# Backend
cd sh-selfhelp_backend
composer install
php bin/console doctrine:migrations:migrate -n
php bin/console selfhelp:plugin:install /abs/path/to/plugins/sh2-shp-survey-js/plugin.json

# Frontend
cd ../sh-selfhelp_frontend
npm ci
npm run plugins:sync -- --backend http://localhost:8000
npm install
npm run dev

# Mobile (optional)
cd ../sh-selfhelp_mobile
npm ci
SELFHELP_API_TOKEN=… npm run plugins:sync -- production-default --backend https://cms.example.com
npm install
```

Now visit `/admin/plugins-host/sh2-shp-survey-js/surveys` to create a survey, then publish a page using the `surveyjs` style with the survey's `key_slug` to embed it.

## 14. Operational notes

- **Backups**: include the four plugin tables in your regular backup set.
- **Restore**: restoring `survey` + `survey_version` is sufficient to recreate the published definitions; `survey_run` / `survey_answer_link` reference core `data_rows` / `data_cells` so restoring those alongside the core form storage keeps responses intact.
- **GDPR**: the plugin's `SurveyJsGdprService` returns per-user run metadata. Core's GDPR pipeline uses `survey_answer_link.id_data_cell` to remove the actual answer cells.
- **Migration safety**: every plugin migration must follow the SemVer rules; minor versions must ship a migration, patches must not.
