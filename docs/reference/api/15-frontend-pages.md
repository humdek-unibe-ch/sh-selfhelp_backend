# Frontend Public APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-11-13.
Source of truth: Controllers, JSON schemas, route definitions, and exported types in this repository.

## Overview

The Frontend Public APIs provide read-only access to published content for end users. These endpoints are publicly accessible without authentication and serve the published website content.

## Core Concepts

### Public Content
- **Published Pages**: Live website content accessible to visitors
- **Published Sections**: Content blocks within published pages
- **Language Support**: Multi-language content delivery
- **Access Control**: Public content with optional restrictions

### Public navigation menus

Global menu placement (web header, web footer, mobile drawer, mobile bottom tabs)
is **not** stored on pages. Resolve menus for rendering via:

**Endpoint:** `GET /cms-api/v1/navigation`

See `docs/developer/29-navigation-menu-builder.md` and the `get_navigation.json` schema.

## Page Access

### Get All Pages

Retrieve a list of all published pages.

**Endpoint:** `GET /cms-api/v1/pages`

**Authentication:** None (public endpoint)

**Query Parameters:**
- `language_id`: Filter by language

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": false,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": [
    {
      "id": 1,
      "keyword": "home",
      "url": "/",
      "is_headless": 0,
      "title": "Home"
    }
  ]
}
```

### Get Page by Language

Get all pages filtered by a specific language.

**Endpoint:** `GET /cms-api/v1/pages/language/{language_id}`

**Authentication:** None (public endpoint)

**Path Parameters:**
- `language_id`: Language ID

**Response:** Pages in the specified language

### Get Single Page (by keyword)

Retrieve content for a specific published page. This is the single
page-content endpoint: the web/mobile BFF resolves a slug directly to full
page content without first fetching the navigation to discover a numeric id.
(The legacy numeric `GET /cms-api/v1/pages/{page_id}` route was removed.)

**Endpoint:** `GET /cms-api/v1/pages/by-keyword/{keyword}`

**Authentication:** None (public endpoint). Open-access pages and pages the
caller's groups are ACL-granted are returned; otherwise the response is `403`.
Anonymous callers are treated as a guest (they inherit no group ACLs).

**Path Parameters:**
- `keyword`: Page keyword (slug), matching `[a-zA-Z0-9_\-]+`

**Query Parameters:**
- `language_id`: Language ID (defaults to the CMS default language)
- `preview`: `true` serves the unpublished draft only to a caller who may
  **edit** the page (`admin.page.update` plus the update data-access grant;
  the admin role passes). An anonymous request is rejected with `401`; a
  signed-in caller without those rights gets `403` and nothing from the draft.
  Page ACL select still applies on top. Preview responses are sent with
  no-store/no-cache headers.

**Response:** Complete page content including sections and translations

**Lists on the page.** A section of style `entry-form-list` or `data-view` carries
`list_state` (rows, columns, controls, pagination, capabilities) or `list_error`
next to its configuration. A `data-view` with the layout `custom` carries no
children in this payload: its sections are the layout of one record and arrive
drawn, once per record, in `list_state.rows[].sections`. The list parameters of the page URL (`<key>.page`,
`<key>.q`, `<key>.sort`, `<key>.f.<id>`, `<key>.record`, `<key>.mode`) are read from
the raw query string of this request, and `GET /cms-api/v1/pages/resolve?path=...`
behaves the same. The cached page holds the configuration only; the rows are added
after the cache per visitor, and a page that contains a list is answered
`Cache-Control: private, no-store`. See [22-form-lists.md](22-form-lists.md).

## Language Support

### Get Available Languages

Retrieve list of supported languages for the frontend.

**Endpoint:** `GET /cms-api/v1/languages`

**Authentication:** None (public endpoint)

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": false,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": [
    {
      "id": 1,
      "locale": "en",
      "language": "English",
      "is_default": true
    }
  ]
}
```

## Utility APIs

### Get CSS Classes

Retrieve available CSS classes for styling (open access).

**Endpoint:** `GET /cms-api/v1/frontend/css-classes`

**Authentication:** None (public endpoint)

**Response:** List of available CSS classes and their definitions

---

**Next:** [Form Submissions](./17-form-submissions.md) | **Back to:** [API Overview](../../README.md)
