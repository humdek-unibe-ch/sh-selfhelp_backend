# Frontend Public APIs

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

### Get Single Page

Retrieve content for a specific published page.

**Endpoint:** `GET /cms-api/v1/pages/{page_id}`

**Authentication:** None (public endpoint)

**Path Parameters:**
- `page_id`: Page ID

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

**Next:** [Form Submissions](./17-form-submissions.md) | **Back to:** [API Overview](../README.md)
