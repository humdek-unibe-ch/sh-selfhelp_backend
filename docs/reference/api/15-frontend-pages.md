# Frontend Public APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-06-23.
Source of truth: Controllers, JSON schemas, route definitions, and exported types in this repository.

## Overview

The Frontend Public APIs provide read-only access to published content for end users. These endpoints are publicly accessible without authentication and serve the published website content.

## Core Concepts

### Public Content
- **Published Pages**: Live website content accessible to visitors
- **Published Sections**: Content blocks within published pages
- **Language Support**: Multi-language content delivery
- **Access Control**: Public content with optional restrictions

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
      "navPosition": 1,
      "language_id": 1
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
- `preview`: `true` serves the unpublished draft. **Requires authentication**
  (an anonymous `preview=true` request is rejected with `401`) plus page ACL
  select; preview responses are sent with no-store/no-cache headers.

**Response:** Complete page content including sections and translations

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
