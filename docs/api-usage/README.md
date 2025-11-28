# SelfHelp Backend API Documentation

## Overview

Welcome to the SelfHelp Backend API documentation. This comprehensive guide covers all API endpoints available in the SelfHelp CMS system, organized by functionality and API version.

## API Architecture

### Base URL
```
https://your-domain.com/cms-api/{version}/
```

### Versioning
The API uses URL-based versioning:
- **v1**: Current stable version (recommended for new integrations)
- **v2+**: Future versions (when available)

### Response Format
All API responses follow a consistent JSON envelope structure:

```json
{
  "status": 200,
  "message": "OK",
  "error": null,
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z",
    "request_id": "req_abc123"
  },
  "data": {
    // Response data here
  }
}
```

### Authentication
Most API endpoints require JWT authentication. Include the JWT token in the `Authorization` header:

```
Authorization: Bearer your_jwt_token_here
```

## Getting Started

1. **Authentication**: Start by authenticating via the login endpoint
2. **API Discovery**: Use the API routes endpoint to discover available endpoints and their permissions
3. **JSON Schemas**: Review the JSON schemas for request/response formats (linked in each API section)
4. **Permissions**: Check user permissions before making API calls

## API Categories

### 🔐 Authentication & User Management
User authentication, profile management, and user data operations.

- **[Authentication APIs](./01-authentication.md)** - Login, logout, token refresh, 2FA
- **[User Profile APIs](./02-user-profile.md)** - Profile updates, language settings
- **[User Validation APIs](./03-user-validation.md)** - Email validation and account activation

### 🏗️ Admin APIs (Require Authentication)

#### Content Management
- **[Pages & Sections](./04-admin-pages-sections.md)** - Page and section CRUD operations
- **[Languages](./05-admin-languages.md)** - Language management
- **[Assets](./06-admin-assets.md)** - File upload and asset management

#### User & Access Management
- **[Users](./07-admin-users.md)** - User CRUD operations
- **[Groups](./08-admin-groups.md)** - Group management
- **[Roles & Permissions](./09-admin-roles.md)** - Role and permission management
- **[Data Access Control](./10-admin-data-access.md)** - Custom data access permissions

#### System Management
- **[Cache Management](./11-admin-cache.md)** - Cache operations and monitoring
- **[Audit Logs](./12-admin-audit.md)** - Security audit logging
- **[Scheduled Jobs](./13-admin-scheduled-jobs.md)** - Background job management
- **[Actions](./14-admin-actions.md)** - Automated action system

### 🌐 Public APIs (No Authentication Required)

#### Frontend Content
- **[Public Pages](./15-frontend-pages.md)** - Public page content access
- **[Languages](./16-frontend-languages.md)** - Available languages

#### Form Handling
- **[Form Submissions](./17-form-submissions.md)** - Contact forms and data collection

### 🎨 Utility APIs
- **[CSS Classes](./18-css-classes.md)** - Available CSS classes for styling

## Common Patterns

### Pagination
Many list endpoints support pagination:

```http
GET /cms-api/v1/admin/users?page=2&pageSize=20&search=john&sort=email&sortDirection=asc
```

### Filtering & Sorting
```http
GET /cms-api/v1/admin/pages?created_after=2024-01-01&status=active
```

### Error Handling
All errors follow the standard response format with appropriate HTTP status codes:

```json
{
  "status": 400,
  "message": "Bad Request",
  "error": "Validation failed",
  "logged_in": true,
  "meta": {
    "version": "v1",
    "timestamp": "2025-01-23T10:30:00Z"
  },
  "data": null,
  "validation": [
    "Field 'email': This field is required",
    "Field 'email': Must be a valid email address"
  ]
}
```

## Permissions System

The API uses a comprehensive permission system. Each endpoint requires specific permissions:

- `admin.access` - Basic admin access
- `admin.page.read` - Read pages
- `admin.page.create` - Create pages
- `admin.user.read` - Read users
- And many more...

Use the API routes discovery endpoint to check required permissions for each endpoint.

## API Discovery

### Get All Available Routes
```http
GET /cms-api/v1/admin/api-routes
Authorization: Bearer your_jwt_token
```

This endpoint returns all available API routes with their required permissions, making it easy for frontend applications to dynamically check permissions and build UI accordingly.

### JSON Schema Validation

All API requests and responses are validated against JSON schemas located in the `config/schemas/api/v1/` directory:

- **Request Schemas**: `config/schemas/api/v1/requests/` - Define expected input formats
- **Response Schemas**: `config/schemas/api/v1/responses/` - Define response structures

Each API documentation page includes **direct links** to the relevant JSON schemas (look for "[View JSON Schema]" links). Click these links to see the exact validation rules, required fields, and data types for each API endpoint.

**Example Schema Link:**
```
[View JSON Schema](../../config/schemas/api/v1/requests/auth/login.json)
```

These schemas ensure data consistency and provide automatic validation in development environments. Use them as references when implementing API calls in your frontend applications.

## Rate Limiting

The API implements rate limiting to prevent abuse. Rate limits vary by endpoint type:
- Authentication endpoints: 5 requests per minute
- Admin endpoints: 100 requests per minute
- Public endpoints: 500 requests per minute

## Data Formats

### Date/Time Handling
All dates are stored in UTC and returned in the user's preferred timezone (based on CMS preferences or user settings).

### File Uploads
File upload endpoints accept `multipart/form-data` with proper validation for file types and sizes.

## Testing

### Test Environment
Use the test environment for development and testing:
```
https://test.your-domain.com/cms-api/v1/
```

### API Testing Tools
- Postman collections available in `/docs/postman/`
- Automated tests in `/tests/Api/`

## Support

For API support:
1. Check this documentation first
2. Review the JSON schemas in `/config/schemas/api/v1/`
3. Check the developer documentation in `/docs/developer/`
4. Contact the development team

## Changelog

### v8.0.0 (Current)
- Added data access management system
- Enhanced audit logging
- Improved caching system
- Added page versioning and publishing

### v7.6.0
- Initial comprehensive API documentation
- JWT authentication system
- Permission-based access control
- Full CRUD operations for all entities

---

**Next Steps:**
- Start with [Authentication APIs](./01-authentication.md) to understand the login flow
- Use the [API Discovery](./01-authentication.md#api-discovery) endpoint to explore available routes
- Check [User Permissions](./09-admin-roles.md) for access control details
