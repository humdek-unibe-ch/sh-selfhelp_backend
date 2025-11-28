# Admin Pages & Sections APIs

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
      "navPosition": 1,
      "footerPosition": null,
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
    "navPosition": 1,
    "footerPosition": null,
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

### Create Page

Create a new page with specified properties.

**Endpoint:** `POST /cms-api/v1/admin/pages`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/admin/create_page.json)
```json
{
  "keyword": "about-us",
  "pageAccessTypeCode": "public",
  "headless": false,
  "openAccess": true,
  "url": "/about",
  "navPosition": 2,
  "footerPosition": null,
  "parent": null
}
```

**Field Descriptions:**
- `keyword`: Unique identifier for the page (required)
- `pageAccessTypeCode`: Access control type ('public', 'authenticated', 'role_based') (required)
- `headless`: Whether page should be excluded from navigation (default: false)
- `openAccess`: Whether page allows public access (default: false)
- `url`: Custom URL path for the page
- `navPosition`: Position in navigation menu
- `footerPosition`: Position in footer
- `parent`: ID of parent page for hierarchical structure

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
    "navPosition": 2,
    "footerPosition": null,
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
[View JSON Schema](../../config/schemas/api/v1/requests/admin/update_page.json)
```json
{
  "pageData": {
    "keyword": "about-us-updated",
    "url": "/about-us",
    "navPosition": 3,
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

Remove a section from a page (but don't delete the section itself).

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

Delete a section and all its content.

**Endpoint:** `DELETE /cms-api/v1/admin/pages/{page_id}/sections/{section_id}`

**Authentication:** Required (JWT Bearer token)

**Path Parameters:**
- `page_id`: ID of the parent page
- `section_id`: ID of the section to delete

**Response:** 204 No Content

**Permissions:** `admin.page.delete`

### Force Delete Section

Force delete a section that may have dependencies or constraints.

**Endpoint:** `DELETE /cms-api/v1/admin/pages/{page_id}/sections/{section_id}/force-delete`

**Authentication:** Required (JWT Bearer token)

**Response:** 204 No Content

**Permissions:** `admin.page.delete`

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

**Permissions:** `admin.page.update`

### Get Reference Containers

Get sections that serve as containers for other sections.

**Endpoint:** `GET /cms-api/v1/admin/sections/ref-containers`

**Authentication:** Required (JWT Bearer token)

**Response:** Array of container sections

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

**Next:** [Admin Languages](./05-admin-languages.md) | **Previous:** [User Validation APIs](./03-user-validation.md) | **Back to:** [API Overview](../README.md)
