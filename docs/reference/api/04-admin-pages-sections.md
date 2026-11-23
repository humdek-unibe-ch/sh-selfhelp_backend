# Admin Pages & Sections APIs

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 Symfony backend.
Last verified: 2026-11-13.
Source of truth: Controllers, JSON schemas, route definitions, and exported types in this repository.

## Overview

The Admin Pages & Sections APIs provide comprehensive content management functionality for the SelfHelp CMS. These endpoints allow administrators to create, read, update, and delete pages and their associated sections, which form the core content structure of the CMS.

## Page Management

### Core Concepts

- **Pages**: Top-level content containers with metadata and settings
- **Sections**: Content blocks within pages that can be nested
- **Styles**: Predefined templates that define section appearance and behavior
- **Page Access Types**: Control who can view pages (public, authenticated, role-based)

### Get All Pages

Retrieve a list of all pages accessible to the current user based on their permissions.

**Endpoint:** `GET /cms-api/v1/admin/pages`

**Authentication:** Required (JWT Bearer token)

**Query Parameters:**
- `page`: Page number for pagination (default: 1)
- `pageSize`: Number of items per page (default: 20)
- `search`: Search term for filtering pages
- `sort`: Sort field (default: 'id')
- `sortDirection`: Sort direction ('asc' or 'desc', default: 'asc')

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z",
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total_items": 45,
      "total_pages": 3,
      "has_next": true,
      "has_previous": false,
      "next_page": 2
    }
  },
  "data": [
    {
      "id": 1,
      "keyword": "welcome",
      "url": "/welcome",
      "headless": false,
      "openAccess": true,
      "navigationMembership": [
        { "menu_key": "web_header", "menu_item_id": 12, "explicit": true }
      ],
      "parent": null,
      "pageAccessTypeCode": "public",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-20T14:45:00Z"
    }
  ]
}
```

**Permissions:** `admin.page.read`

### Get Pages by Language

Get all pages filtered by a specific language.

**Endpoint:** `GET /cms-api/v1/admin/pages/language/{language_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `language_id`: Language ID to filter pages by

**Response:** Same as Get All Pages, but filtered by language

**Permissions:** `admin.page.read`

### Get Single Page

Retrieve detailed information about a specific page including its fields and translations.

**Endpoint:** `GET /cms-api/v1/admin/pages/{page_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: The ID of the page to retrieve

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "id": 1,
    "keyword": "welcome",
    "url": "/welcome",
    "headless": false,
    "openAccess": true,
    "navigationMembership": [
      { "menu_key": "web_header", "menu_item_id": 12, "explicit": true }
    ],
    "parent": null,
    "pageAccessTypeCode": "public",
    "pageFields": {
      "1": {
        "field_name": "title",
        "translations": {
          "en": {
            "value": "Welcome to Our Site",
            "language_id": 1
          },
          "de": {
            "value": "Willkommen auf unserer Seite",
            "language_id": 2
          }
        }
      }
    },
    "created_at": "2024-01-15T10:30:00Z",
    "updated_at": "2024-01-20T14:45:00Z"
  }
}
```

**Permissions:** `admin.page.read`

**Caller rights (since 0.1.48):** `data.rights` says what the signed-in person
may do with this page, so the editor can hide what would be refused:

```json
"rights": { "can_update": true, "can_delete": false, "can_publish": true }
```

Each flag needs the route permission **and** the grant bit on this page
(`can_update`: `admin.page.update` + update; `can_delete`: `admin.page.delete` +
delete; `can_publish`: `admin.page_version.publish` + update); the superuser
role gets all. It is computed per request after the cached page is read and is
never cached with it. The server still checks every write on its own.

**Draft version:** the data also carries `draft_version` (integer), and the response header `X-Page-Version` has the same value. Send it back as `X-Page-Version` on the next write to this page (see [Draft Version](#draft-version-x-page-version)).

### Create Page

Create a new page with specified properties.

**Endpoint:** `POST /cms-api/v1/admin/pages`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../../config/schemas/api/v1/requests/admin/create_page.json)
```json
{
  "keyword": "about-us",
  "pageAccessTypeCode": "public",
  "headless": false,
  "openAccess": true,
  "url": "/about",
  "parent": null,
  "navigationAssignments": [
    {
      "menuKey": "web_header",
      "parentItemId": null,
      "position": 20
    }
  ]
}
```

**Field Descriptions:**
- `keyword`: Unique identifier for the page (required)
- `pageAccessTypeCode`: Access control type ('public', 'authenticated', 'role_based') (required)
- `headless`: Whether page should be excluded from global shell chrome (default: false)
- `openAccess`: Whether page allows public access (default: false)
- `url`: Custom URL path for the page
- `parent`: ID of parent page for hierarchical structure (page tree; separate from menu nesting)
- `navigationAssignments`: Optional menu-builder placements (`menuKey` one of `web_header`, `web_footer`, `mobile_drawer`, `mobile_bottom_tabs`). Creates `navigation_menu_items` in the same transaction. Menu-only moves do not change page URLs.

**Response:**
```json
{
  "status": 201,
  "message": "Created",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "id": 2,
    "keyword": "about-us",
    "url": "/about",
    "headless": false,
    "openAccess": true,
    "navigationMembership": [
      { "menu_key": "web_header", "menu_item_id": 15, "explicit": true }
    ],
    "parent": null,
    "pageAccessTypeCode": "public",
    "created_at": "2025-01-23T10:30:00Z",
    "updated_at": "2025-01-23T10:30:00Z"
  }
}
```

**Permissions:** `admin.page.create`

### Update Page

Update an existing page's properties and field translations.

**Endpoint:** `PUT /cms-api/v1/admin/pages/{page_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the page to update

**Request Body:**
[View JSON Schema](../../../config/schemas/api/v1/requests/admin/update_page.json)
```json
{
  "pageData": {
    "keyword": "about-us-updated",
    "url": "/about-us",
    "openAccess": false
  },
  "fields": [
    {
      "field_name": "title",
      "translations": [
        {
          "language_id": 1,
          "value": "About Our Company"
        },
        {
          "language_id": 2,
          "value": "Über unser Unternehmen"
        }
      ]
    }
  ]
}
```

**Response:** Updated page data (same format as GET single page)

**Permissions:** `admin.page.update`

### Delete Page

Delete a page and all its associated sections.

**Endpoint:** `DELETE /cms-api/v1/admin/pages/{page_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the page to delete

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "id": 2,
    "keyword": "about-us",
    "deleted": true
  }
}
```

**Permissions:** `admin.page.delete`

### Draft Version (`X-Page-Version`)

Every write to a page's draft must name the version it is based on, so an editor never overwrites a newer draft unseen. This covers: update page, delete page, add / create / remove / update / import sections (to the page and to a section), restore from version, publish, unpublish, publish a version and delete a version (every non-GET `/admin/pages/{page_id}/...` route except the lock routes).

**Request header:** `X-Page-Version: <draft_version>` (a positive whole number; read it from `GET /admin/pages/{page_id}` or from the header of the last write).

| Case | Status | `error` | `data` |
|---|---|---|---|
| header missing or not a positive integer | `400` | `page_version_required` | `null` |
| header is not the current version | `409` | `stale_page_version` | `{ "page_id": 12, "current_version": 4 }` |
| header is the current version | 2xx | | the response header `X-Page-Version` is the new version (current + 1) |

A refused or failed write leaves the version unchanged. Of two writes that send the same version, exactly one runs and the other gets `409 stale_page_version`. A client that gets 409 reloads the page (`GET`), merges, and sends the new version. The header is an allowed CORS request header and an exposed response header.

### Page Edit Lock

One editor at a time holds the lock of a page. The lease ends **120 seconds after the last renewal** and **15 minutes after the holder's last draft write** (after the acquisition when they wrote nothing). A client renews while the page is open by repeating `POST .../lock`.

| Endpoint | Purpose |
|---|---|
| `GET /cms-api/v1/admin/pages/{page_id}/lock` | status of the lock; changes nothing |
| `POST /cms-api/v1/admin/pages/{page_id}/lock` | acquire a free lock, or renew your own; `409 page_locked` when someone else holds it |
| `POST /cms-api/v1/admin/pages/{page_id}/lock/take-over` | become the holder, whoever held it |
| `DELETE /cms-api/v1/admin/pages/{page_id}/lock` | release your own lock; no effect when it is not yours |

**Permissions:** `admin.page.update` and the update grant on the page (the same as a page write); otherwise 403, or 404 for a page that does not exist.

**Response data** ([schema](../../../config/schemas/api/v1/responses/admin/pages/page_lock.json)):
```json
{
  "page_id": 12,
  "held": true,
  "mine": false,
  "holder": { "id": 7, "name": "Alex Editor" },
  "expires_at": "2026-11-20T12:02:00Z",
  "idle_expires_at": "2026-11-20T12:15:00Z"
}
```
`held` is false and `holder`, `expires_at`, `idle_expires_at` are `null` when nobody holds the lock. `mine` is true when the caller holds it.

**Lock and drafts.** A draft write by someone other than the holder answers `409` with error `page_locked` and the same data as above (the lock is checked before the version). A draft write while nobody holds the lock succeeds and leaves the caller holding it. After a take-over, the previous holder's next write gets `409 page_locked`. A change of who holds the lock sends the live-sync notice `page_lock:<page_id>` to the roles that may edit the page.

## Section Management

### Get Page Sections

Retrieve all sections directly attached to a page.

**Endpoint:** `GET /cms-api/v1/admin/pages/{page_id}/sections`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the page

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "pageId": 1,
    "sections": [
      {
        "id": 10,
        "sectionId": 5,
        "position": 1,
        "styleId": 2,
        "sectionName": "Hero Section",
        "created_at": "2024-01-15T10:30:00Z"
      },
      {
        "id": 11,
        "sectionId": 6,
        "position": 2,
        "styleId": 3,
        "sectionName": "Content Section",
        "created_at": "2024-01-15T11:00:00Z"
      }
    ]
  }
}
```

**Permissions:** `admin.page.read`

### Get Single Section

Retrieve detailed information about a specific section including its content fields.

**Endpoint:** `GET /cms-api/v1/admin/pages/{page_id}/sections/{section_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the parent page
- `section_id`: ID of the section

**Response:**
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "section": {
      "id": 5,
      "styleId": 2,
      "sectionName": "Hero Section",
      "created_at": "2024-01-15T10:30:00Z"
    },
    "fields": [
      {
        "id": 100,
        "field_name": "title",
        "field_type": "text",
        "translations": {
          "en": {
            "value": "Welcome Message",
            "language_id": 1
          }
        }
      }
    ],
    "languages": [
      {
        "id": 1,
        "locale": "en",
        "language": "English"
      }
    ]
  }
}
```

**Permissions:** `admin.page.read`

**Data access:** the caller's role needs the READ grant on the page, and the
section must belong to that page; otherwise `403` (`404` for an unknown page or
section). The response is built from a shared cache, but this check runs on every
read, so a cached section is never served to a role without the grant. The option
lists of the pickers inside `fields[].config.options` are read per request (assets
only those the caller may manage).

For `entry-form-list` and `data-view` sections the response also carries
`list_source`, the columns and row fields the list editor may offer; see
[22-form-lists.md](22-form-lists.md#authoring-administration-api).

### Create Page Section

Create a new section and add it directly to a page.

**Endpoint:** `POST /cms-api/v1/admin/pages/{page_id}/sections/create`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the page to add the section to

**Request Body:**
```json
{
  "styleId": 2,
  "position": 1
}
```

**Response:**
```json
{
  "status": 201,
  "message": "Created",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "id": 12,
    "position": 1
  }
}
```

**Permissions:** `admin.page.update`

### Add Existing Section to Page

Add an existing section to a page at a specific position.

**Endpoint:** `PUT /cms-api/v1/admin/pages/{page_id}/sections`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the page

**Request Body:**
```json
{
  "sectionId": 15,
  "position": 2,
  "oldParentSectionId": null
}
```

**Response:** Same format as create section

**Permissions:** `admin.page.update`

### Remove Section from Page

Detach a section from a page without destroying the section record. Unlinks the
section from this page only (its direct page link, or its hierarchy link when
nested); the section row survives for every other page that references it. This
is the correct operation for shared `ref-container` sections. To destroy the
record entirely, use [Delete Section](#delete-section).

**Endpoint:** `DELETE /cms-api/v1/admin/pages/{page_id}/sections/{section_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the parent page
- `section_id`: ID of the section to remove

**Response:**
```json
{
  "status": 204,
  "message": "No Content",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": null
}
```

**Permissions:** `admin.page.update`

### Bulk Remove Sections from Page

Detach several sections from a page in one request. This is the multi-select
equivalent of [Remove Section from Page](#remove-section-from-page) and has the
**same detach-only** semantics: each section is unlinked from this page only
(its direct page link, or its hierarchy link when nested) and the section
**record survives** for every other page that references it. Bulk remove never
destroys a shared `ref-container` — to destroy a record entirely use
[Delete Section](#delete-section). Cache invalidation fans out to every page
that referenced each detached section.

**Endpoint:** `DELETE /cms-api/v1/admin/pages/{page_id}/sections`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the page to detach the sections from

**Request Body:**
[View JSON Schema](../../../config/schemas/api/v1/requests/section/bulk_remove_sections.json)
```json
{
  "sectionIds": [15, 16, 17]
}
```

**Response:** `200 OK` with the number of page links detached and a per-section
error list for any ids that could not be detached.
```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": {
    "deleted_count": 3,
    "errors": []
  }
}
```

**Permissions:** `admin.page.update`

## Nested Section Management

### Get Children Sections

Retrieve all sections that are children of a specific parent section.

**Endpoint:** `GET /cms-api/v1/admin/pages/{page_id}/sections/{parent_section_id}/sections`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the parent page
- `parent_section_id`: ID of the parent section

**Response:** Array of child sections (similar to page sections)

**Permissions:** `admin.page.read`

### Create Child Section

Create a new section and add it as a child to an existing section.

**Endpoint:** `POST /cms-api/v1/admin/pages/{page_id}/sections/{parent_section_id}/sections/create`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the parent page
- `parent_section_id`: ID of the parent section

**Request Body:** Same as create page section

**Response:** Same as create page section

**Permissions:** `admin.page.update`

### Add Section to Section

Move an existing section to be a child of another section.

**Endpoint:** `PUT /cms-api/v1/admin/pages/{page_id}/sections/{parent_section_id}/sections`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the parent page
- `parent_section_id`: ID of the new parent section

**Request Body:**
```json
{
  "childSectionId": 20,
  "position": 1,
  "oldParentPageId": 1,
  "oldParentSectionId": 5
}
```

**Response:** Same format as create section

**Permissions:** `admin.page.update`

### Remove Section from Section

Remove a child section from its parent section.

**Endpoint:** `DELETE /cms-api/v1/admin/pages/{page_id}/sections/{parent_section_id}/sections/{child_section_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the parent page
- `parent_section_id`: ID of the parent section
- `child_section_id`: ID of the child section to remove

**Response:** 204 No Content

**Permissions:** `admin.page.update`

## Section Operations

### Update Section

Update a section's content fields and properties.

**Endpoint:** `PUT /cms-api/v1/admin/pages/{page_id}/sections/{section_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the parent page
- `section_id`: ID of the section to update

**Request Body:**
```json
{
  "sectionId": 5,
  "sectionName": "Updated Hero Section",
  "contentFields": [
    {
      "field_name": "title",
      "translations": [
        {
          "language_id": 1,
          "value": "New Welcome Message"
        }
      ]
    }
  ],
  "propertyFields": [
    {
      "field_name": "background_color",
      "value": "#ffffff"
    }
  ]
}
```

**Response:** Updated section data

**Permissions:** `admin.page.update`

### Delete Section

Permanently delete a section record, regardless of which pages use it. This is
page-independent: it destroys the section and every relationship it has on every
page. A shared section (e.g. a `ref-container`) deleted this way disappears from
all pages that referenced it. To merely unlink a section from one page while
keeping the record for its other usages, use
[Remove Section from Page](#remove-section-from-page) instead.

**Endpoint:** `DELETE /cms-api/v1/admin/sections/{section_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `section_id`: ID of the section to delete

**Response:** 204 No Content

**Permissions:** `admin.page.delete` (on every page that currently references the section)

## Section Export/Import

### Export Page Sections

Export all sections from a page for backup or migration.

**Endpoint:** `GET /cms-api/v1/admin/pages/{page_id}/sections/export`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the page to export

**Response:** JSON export data containing all page sections

**Permissions:** `admin.page.export`

### Export Single Section

Export a specific section for backup or reuse.

**Endpoint:** `GET /cms-api/v1/admin/pages/{page_id}/sections/{section_id}/export`

**Authentication:** Required (JWT Bearer token)

**Response:** JSON export data for the specific section

**Permissions:** `admin.page.export`

### Import Sections to Page

Import previously exported sections to a page.

**Endpoint:** `POST /cms-api/v1/admin/pages/{page_id}/sections/import`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "sections": [
    {
      "styleId": 2,
      "sectionName": "Imported Section",
      "position": 1,
      "contentFields": [...]
    }
  ],
  "position": 3
}
```

**Response:** Import results with new section IDs

**Permissions:** `admin.page.export`

### Import Sections to Section

Import sections as children of an existing section.

**Endpoint:** `POST /cms-api/v1/admin/pages/{page_id}/sections/{parent_section_id}/import`

**Authentication:** Required (JWT Bearer token)

**Request Body:** Same as import to page

**Response:** Import results

**Permissions:** `admin.page.export`

### Restore from Version

Restore sections from a specific page version.

**Endpoint:** `POST /cms-api/v1/admin/pages/{page_id}/sections/restore-from-version/{version_id}`

**Authentication:** Required (JWT Bearer token)

**Response:** Restoration results

**Permissions:** `admin.page.export`

## Section Utilities

### Get Unused Sections

Retrieve sections that are not attached to any page.

**Endpoint:** `GET /cms-api/v1/admin/sections/unused`

**Authentication:** Required (JWT Bearer token)

**Response:** Array of unused sections

**Permissions:** `admin.section.read`

### Get Reference Containers

List all sections whose style is `ref-container`. These are reusable structural blocks that can be placed on multiple pages. `ref-container` is a transparent pass-through: it introduces no visual styling, layout, or presentation of its own; all rendering comes from its children. This endpoint is used by the admin page builder to let editors select an existing reusable block when wiring a page.

**Endpoint:** `GET /cms-api/v1/admin/sections/ref-containers`

**Authentication:** Required (JWT Bearer token)

**Response:** Array of `{ id, name, idStyles, styleName }` objects (styleName is always `ref-container`)

**Permissions:** `admin.page.update`

### Delete Unused Section

Delete a specific unused section.

**Endpoint:** `DELETE /cms-api/v1/admin/sections/unused/{section_id}`

**Authentication:** Required (JWT Bearer token)

**Response:** 204 No Content

**Permissions:** `admin.section.delete`

### Delete All Unused Sections

Clean up all unused sections in the system.

**Endpoint:** `DELETE /cms-api/v1/admin/sections/unused`

**Authentication:** Required (JWT Bearer token)

**Response:** Deletion results

**Permissions:** `admin.section.delete`

## Frontend Integration Examples

### Page Builder Component

```javascript
const PageBuilder = ({ pageId }) => {
  const [page, setPage] = useState(null);
  const [sections, setSections] = useState([]);

  useEffect(() => {
    loadPageData();
  }, [pageId]);

  const loadPageData = async () => {
    try {
      const [pageResponse, sectionsResponse] = await Promise.all([
        apiRequest(`/admin/pages/${pageId}`),
        apiRequest(`/admin/pages/${pageId}/sections`)
      ]);

      setPage(pageResponse.data);
      setSections(sectionsResponse.data.sections);
    } catch (error) {
      console.error('Failed to load page data:', error);
    }
  };

  const addSection = async (styleId, position) => {
    try {
      await apiRequest(`/admin/pages/${pageId}/sections/create`, {
        method: 'POST',
        body: JSON.stringify({ styleId, position })
      });
      loadPageData(); // Refresh data
    } catch (error) {
      console.error('Failed to add section:', error);
    }
  };

  const deleteSection = async (sectionId) => {
    try {
      await apiRequest(`/admin/pages/${pageId}/sections/${sectionId}`, {
        method: 'DELETE'
      });
      loadPageData(); // Refresh data
    } catch (error) {
      console.error('Failed to delete section:', error);
    }
  };

  return (
    <div className="page-builder">
      <h2>{page?.keyword}</h2>

      <div className="sections-list">
        {sections.map(section => (
          <SectionItem
            key={section.id}
            section={section}
            onDelete={() => deleteSection(section.id)}
          />
        ))}
      </div>

      <button onClick={() => addSection(1, sections.length + 1)}>
        Add Section
      </button>
    </div>
  );
};
```

### Section Drag & Drop

```javascript
const SectionList = ({ sections, onReorder }) => {
  const [draggedSection, setDraggedSection] = useState(null);

  const handleDragStart = (section) => {
    setDraggedSection(section);
  };

  const handleDragOver = (e) => {
    e.preventDefault();
  };

  const handleDrop = async (targetSection) => {
    if (!draggedSection || draggedSection.id === targetSection.id) return;

    try {
      // Calculate new position
      const newPosition = targetSection.position;

      // Update section position via API
      await apiRequest(`/admin/pages/${pageId}/sections`, {
        method: 'PUT',
        body: JSON.stringify({
          sectionId: draggedSection.id,
          position: newPosition
        })
      });

      onReorder(); // Refresh the list
    } catch (error) {
      console.error('Failed to reorder sections:', error);
    }

    setDraggedSection(null);
  };

  return (
    <div className="sections-container">
      {sections.map(section => (
        <div
          key={section.id}
          draggable
          onDragStart={() => handleDragStart(section)}
          onDragOver={handleDragOver}
          onDrop={() => handleDrop(section)}
          className="section-item"
        >
          <SectionContent section={section} />
        </div>
      ))}
    </div>
  );
};
```

## Error Handling

```javascript
const handlePageOperation = async (operation, ...args) => {
  try {
    const response = await apiRequest(operation, ...args);

    if (response.status === 200 || response.status === 201) {
      return { success: true, data: response.data };
    }
  } catch (error) {
    const errorData = error.response?.data;

    if (errorData?.status === 403) {
      return {
        success: false,
        error: 'Insufficient permissions',
        action: 'show_permission_error'
      };
    } else if (errorData?.status === 404) {
      return {
        success: false,
        error: 'Page or section not found',
        action: 'show_not_found'
      };
    } else if (errorData?.status === 409 && errorData?.error === 'page_locked') {
      return {
        success: false,
        error: `Locked by ${errorData.data.holder.name}`,
        action: 'show_lock_holder'
      };
    } else if (errorData?.status === 409 && errorData?.error === 'stale_page_version') {
      return {
        success: false,
        error: 'The page changed; reload it',
        action: 'reload_page'
      };
    } else if (errorData?.status === 409) {
      return {
        success: false,
        error: 'Section is referenced elsewhere',
        action: 'show_dependency_error'
      };
    } else {
      return {
        success: false,
        error: 'An unexpected error occurred'
      };
    }
  }
};
```

## Best Practices

1. **Batch Operations**: When moving multiple sections, use batch API calls
2. **Caching**: Cache page structures on the frontend for better performance
3. **Optimistic Updates**: Update UI immediately, then sync with server
4. **Validation**: Validate section data before sending to server
5. **Error Recovery**: Provide undo functionality for failed operations
6. **Loading States**: Show loading indicators during async operations
7. **Permissions Check**: Verify user permissions before showing UI elements

---

**Next:** [Admin Languages](./05-admin-languages.md) | **Previous:** [User Validation APIs](./03-user-validation.md) | **Back to:** [API Overview](../../README.md)
