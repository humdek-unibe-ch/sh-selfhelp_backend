# API Design Patterns

## 🌐 RESTful API Design

The SelfHelp Symfony Backend follows strict RESTful principles with standardized patterns for consistency, maintainability, and developer experience.

## 📋 Response Format Standard

### Universal Response Envelope
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
        // Actual response data here
    }
}
```

### Response Fields
- **`status`**: HTTP status code (200, 400, 401, 403, 404, 500, etc.)
- **`message`**: Human-readable status message
- **`error`**: Error details (null for successful responses)
- **`logged_in`**: Boolean indicating authentication status
- **`meta`**: Response metadata (version, timestamp, pagination, etc.)
- **`data`**: The actual response payload

### ApiResponseFormatter Implementation
```php
<?php
namespace App\Service\Core;

class ApiResponseFormatter
{
    /**
     * Whether to validate the response schema. It consumes a lot of resources and should be disabled in production.
     *
     * @var bool
     */
    private const VALIDATE_RESPONSE_SCHEMA = false;

    public function formatSuccess($data = null, ?string $responseSchemaName = null, int $status = Response::HTTP_OK, bool $isLoggedIn = false): JsonResponse
    {
        $isLoggedIn = $isLoggedIn || $this->security->getUser() !== null;

        // Normalize any Doctrine entities in the data using Symfony Serializer
        $normalizedData = Utils::normalizeWithSymfonySerializer($data);

        $responseData = [
            'status' => $status,
            'message' => Response::$statusTexts[$status] ?? 'OK',
            'error' => null,
            'logged_in' => $isLoggedIn,
            'meta' => [
                'version' => 'v1', // Consider making this configurable
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
            'data' => $normalizedData,
            // validation field is not included in success responses
        ];

        // Only perform schema validation in non-production environments and when explicitly enabled
        if (self::VALIDATE_RESPONSE_SCHEMA) {
            try {
                // Deep convert arrays to objects for proper JSON Schema validation
                $responseDataForValidation = $this->arrayToObject($responseData);

                // Validate the entire responseData object
                $validationErrors = $this->jsonSchemaValidationService->validate($responseDataForValidation, $responseSchemaName);

                if (!empty($validationErrors)) {
                    $this->logger->error('API Response Schema Validation Failed.', [
                        'schema' => $responseSchemaName,
                        'errors' => $validationErrors,
                        // 'data' => $responseData, // Be cautious with logging sensitive data
                    ]);

                    // Add debug info directly to the responseData for non-prod environments
                    $responseData['_debug'] = ['validation_errors' => $validationErrors];
                }
            } catch (\Exception $e) {
                $this->logger->error('Error during response schema validation.', [
                    'schema' => $responseSchemaName,
                    'exception' => $e->getMessage(),
                ]);
                $responseData['_debug'] = ['validation_exception' => $e->getMessage()];
            }
        }

        return new JsonResponse($responseData, $status);
    }

    public function formatError(string $error, int $status = Response::HTTP_BAD_REQUEST, $data = null, ?array $validationErrors = null): JsonResponse
    {
        $isLoggedIn = $this->security->getUser() !== null;

        $responseData = [
            'status' => $status,
            'message' => Response::$statusTexts[$status] ?? 'Unknown status',
            'error' => $error,
            'logged_in' => $isLoggedIn,
            'meta' => [
                'version' => 'v1',
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
            ],
            'data' => $data
        ];

        // Add validation errors if provided
        if ($validationErrors !== null) {
            $responseData['validation'] = $validationErrors;
        }

        // Only perform schema validation in non-production environments
        if ($this->kernel->getEnvironment() !== 'prod') {
            try {
                // Deep convert arrays to objects for proper JSON Schema validation
                $responseDataForValidation = $this->arrayToObject($responseData);

                // Determine which schema to use based on status code
                $schemaName = 'responses/common/_error_response_envelope';

                // Use specific error schemas for common status codes
                if ($status === Response::HTTP_NOT_FOUND) {
                    $schemaName = 'responses/errors/not_found_error';
                } elseif ($status === Response::HTTP_BAD_REQUEST) {
                    $schemaName = 'responses/errors/bad_request_error';
                } elseif ($status === Response::HTTP_UNAUTHORIZED) {
                    $schemaName = 'responses/errors/unauthorized_error';
                } elseif ($status === Response::HTTP_FORBIDDEN) {
                    $schemaName = 'responses/errors/forbidden_error';
                } elseif ($status === Response::HTTP_INTERNAL_SERVER_ERROR) {
                    $schemaName = 'responses/errors/internal_server_error';
                }

                // Validate against the appropriate error response schema
                $validationErrors = $this->jsonSchemaValidationService->validate(
                    $responseDataForValidation,
                    $schemaName
                );

                if (!empty($validationErrors)) {
                    $this->logger->error('API Error Response Schema Validation Failed.', [
                        'schema' => $schemaName,
                        'errors' => $validationErrors,
                    ]);

                    // Add debug info directly to the responseData for non-prod environments
                    $responseData['_debug'] = ['validation_errors' => $validationErrors];
                }
            } catch (\Exception $e) {
                $this->logger->error('Error during error response schema validation.', [
                    'exception' => $e->getMessage(),
                ]);
                $responseData['_debug'] = ['validation_exception' => $e->getMessage()];
            }
        }

        return new JsonResponse($responseData, $status);
    }
}
```

## 🛣️ URL Structure & Naming

### URL Pattern
```
/cms-api/{version}/{area}/{resource}[/{id}][/{action}]
```

### Examples
- `GET /cms-api/v1/admin/pages` - List pages
- `GET /cms-api/v1/admin/pages/123` - Get specific page
- `POST /cms-api/v1/admin/pages` - Create page
- `PUT /cms-api/v1/admin/pages/123` - Update page
- `DELETE /cms-api/v1/admin/pages/123` - Delete page
- `POST /cms-api/v1/admin/pages/123/publish` - Custom action

### Naming Conventions
- **Resources**: Plural nouns (`pages`, `users`, `assets`)
- **Actions**: Verbs for custom operations (`publish`, `activate`, `export`)
- **Parameters**: Snake_case in URLs, camelCase in JSON
- **Versions**: Simple version numbers (`v1`, `v2`)

## 🔧 HTTP Methods & Status Codes

### HTTP Method Usage
| Method | Purpose | Request Body | Response Body |
|--------|---------|--------------|---------------|
| GET | Retrieve resource(s) | None | Resource data |
| POST | Create new resource | Resource data | Created resource |
| PUT | Update entire resource | Complete resource | Updated resource |
| PATCH | Partial update | Changed fields | Updated resource |
| DELETE | Remove resource | None | Confirmation |

### HTTP Status Codes
| Code | Usage | Description |
|------|-------|-------------|
| 200 | GET, PUT, PATCH success | Request successful |
| 201 | POST success | Resource created |
| 204 | DELETE success | No content to return |
| 400 | Validation error | Bad request data |
| 401 | Authentication failure | Invalid/missing token |
| 403 | Authorization failure | Insufficient permissions |
| 404 | Resource not found | Resource doesn't exist |
| 409 | Conflict | Resource already exists |
| 422 | Validation failure | Invalid entity data |
| 500 | Server error | Internal server error |

## 🔍 API Discovery Pattern

### API Routes Endpoint
The SelfHelp API provides an endpoint for frontend applications to discover available API routes and their required permissions. This allows frontend code to dynamically check permissions before making API calls, reducing unnecessary requests and improving user experience.

#### Endpoint
```
GET /cms-api/v1/admin/api-routes
```

#### Response Format
```json
{
    "status": 200,
    "message": "OK",
    "error": null,
    "logged_in": true,
    "meta": {
        "version": "v1",
        "timestamp": "2025-10-30T14:26:39+01:00"
    },
    "data": {
        "routes": [
            {
                "id": 8,
                "route_name": "admin_lookups",
                "version": "v1",
                "path": "/admin/lookups",
                "controller": "App\\Controller\\Api\\V1\\Admin\\Common\\LookupController::getAllLookups",
                "methods": "GET",
                "requirements": null,
                "params": null,
                "required_permissions": [
                    {
                        "name": "admin.access",
                        "description": "Can view and enter the admin/backend area"
                    }
                ]
            },
            {
                "id": 10,
                "route_name": "admin_pages_get_all",
                "version": "v1",
                "path": "/admin/pages",
                "controller": "App\\Controller\\Api\\V1\\Admin\\AdminPageController::getPages",
                "methods": "GET",
                "requirements": null,
                "params": {
                    "page": {"in": "query", "required": false},
                    "pageSize": {"in": "query", "required": false}
                },
                "required_permissions": [
                    {
                        "name": "admin.page.read",
                        "description": "Can read existing pages"
                    }
                ]
            }
        ],
        "total": 150
    }
}
```

#### Usage Pattern
```javascript
// Frontend: Check if user can read pages before fetching
const apiRoutes = await fetch('/cms-api/v1/admin/api-routes');
const routesData = await apiRoutes.json();

// Find the route for reading pages
const pagesRoute = routesData.data.routes.find(route =>
    route.route_name === 'admin_pages_get_all'
);

// Check if user has required permissions
const userPermissions = ['admin.page.read', 'admin.access'];
const canReadPages = pagesRoute.required_permissions.every(perm =>
    userPermissions.includes(perm.name)
);

if (canReadPages) {
    // Safe to make the API call
    const pages = await fetch('/cms-api/v1/admin/pages');
} else {
    // Show permission denied message or hide UI element
    console.log('User lacks required permissions');
}
```

#### Implementation Benefits
- **Reduced API calls**: Frontend can check permissions before making requests
- **Better UX**: UI elements can be conditionally shown/hidden based on permissions
- **Centralized permission logic**: Single source of truth for API permissions
- **Dynamic UI**: Frontend can adapt based on user's actual permissions

## 📊 Pagination Pattern

### Request Parameters
```
GET /cms-api/v1/admin/pages?page=2&per_page=20&sort=created_at&order=desc
```

### Response Format
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
            "current_page": 2,
            "per_page": 20,
            "total_items": 150,
            "total_pages": 8,
            "has_next": true,
            "has_previous": true,
            "next_page": 3,
            "previous_page": 1
        }
    },
    "data": [
        // Array of resources
    ]
}
```

### Pagination Implementation
```php
<?php
public function getPages(Request $request): JsonResponse
{
    $page = (int)$request->query->get('page', 1);
    $perPage = min((int)$request->query->get('per_page', 20), 100);
    $sort = $request->query->get('sort', 'id');
    $order = $request->query->get('order', 'asc');
    
    $result = $this->adminPageService->getPaginatedPages($page, $perPage, $sort, $order);
    
    return $this->responseFormatter->formatSuccess(
        $result['data'],
        'responses/admin/pages/page',
        Response::HTTP_OK
    );
}
```

## 🔍 Filtering & Searching

### Query Parameter Patterns
```
GET /cms-api/v1/admin/pages?search=welcome&status=active&created_after=2024-01-01
```

### Filter Implementation
```php
<?php
public function buildFilters(Request $request): array
{
    $filters = [];
    
    // Text search
    if ($search = $request->query->get('search')) {
        $filters['search'] = $search;
    }
    
    // Status filter
    if ($status = $request->query->get('status')) {
        $filters['status'] = $status;
    }
    
    // Date range filters
    if ($createdAfter = $request->query->get('created_after')) {
        $filters['created_after'] = new \DateTime($createdAfter);
    }
    
    return $filters;
}
```

## 📝 Request Validation Pattern

### JSON Schema Validation
All requests are validated against JSON schemas stored in `/config/schemas/api/v1/requests/`:

```php
<?php
use App\Controller\Trait\RequestValidatorTrait;

class AdminPageController extends AbstractController
{
    use RequestValidatorTrait;
    
    public function createPage(Request $request): JsonResponse
    {
        try {
            // Validate request against schema
            $validatedData = $this->validateRequest(
                $request,
                'requests/admin/create_page',
                $this->jsonSchemaValidationService
            );
            
            // Process validated data
            $page = $this->adminPageService->createPage($validatedData);
            
            return $this->responseFormatter->formatSuccess(
                $page,
                'responses/admin/pages/page',
                Response::HTTP_CREATED
            );
            
        } catch (RequestValidationException $e) {
            return $this->responseFormatter->formatError(
                'Validation failed',
                Response::HTTP_BAD_REQUEST,
                $e->getValidationErrors()
            );
        }
    }
}
```

### Schema Example
```json
{
    "$schema": "http://json-schema.org/draft-07/schema#",
    "type": "object",
    "required": ["keyword", "pageType"],
    "properties": {
        "keyword": {
            "type": "string",
            "minLength": 1,
            "maxLength": 100,
            "pattern": "^[a-zA-Z0-9_-]+$"
        },
        "pageType": {
            "type": "integer",
            "minimum": 1
        },
        "url": {
            "type": "string",
            "maxLength": 255
        },
        "isHeadless": {
            "type": "boolean",
            "default": false
        }
    }
}
```

## 🚨 Error Handling Pattern

### Error Response Structure
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
        "Field 'keyword': This field is required",
        "Field 'pageType': Must be a positive integer"
    ]
}
```

### Exception Handling
```php
<?php
namespace App\EventListener;

class ApiExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();
        
        // Only handle API requests
        if (!str_starts_with($request->getPathInfo(), '/cms-api/')) {
            return;
        }
        
        $response = match (true) {
            $exception instanceof RequestValidationException => $this->handleValidationException($exception),
            $exception instanceof AccessDeniedException => $this->handleAccessDeniedException($exception),
            $exception instanceof NotFoundHttpException => $this->handleNotFoundException($exception),
            default => $this->handleGenericException($exception)
        };
        
        $event->setResponse($response);
    }
}
```

## 🔄 Versioning Pattern

### URL Versioning
```
/cms-api/v1/admin/pages  # Version 1
/cms-api/v2/admin/pages  # Version 2
```

### Controller Versioning
```php
<?php
// Version 1
namespace App\Controller\Api\V1\Admin;
class AdminPageController { }

// Version 2
namespace App\Controller\Api\V2\Admin;
class AdminPageController { }
```

### Database Route Versioning
```sql
INSERT INTO `api_routes` (`route_name`, `version`, `path`, `controller`) VALUES
('admin_get_pages', 'v1', '/admin/pages', 'App\\Controller\\AdminPageController::getPages'),
('admin_get_pages', 'v2', '/admin/pages', 'App\\Controller\\AdminPageController::getPages');
```

## 📦 Resource Representation

### Single Resource
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
        "id": 123,
        "keyword": "welcome",
        "url": "/welcome",
        "isHeadless": false,
        "navPosition": 1,
        "createdAt": "2024-01-15T10:30:00Z",
        "updatedAt": "2024-01-20T14:45:00Z"
    }
}
```

### Collection Resource
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
            "total_items": 50,
            "total_pages": 3
        }
    },
    "data": [
        {
            "id": 123,
            "keyword": "welcome",
            "url": "/welcome"
        },
        {
            "id": 124,
            "keyword": "about",
            "url": "/about"
        }
    ]
}
```

## 🔗 HATEOAS (Hypermedia)

### Resource Links
```json
{
    "data": {
        "id": 123,
        "keyword": "welcome",
        "_links": {
            "self": "/cms-api/v1/admin/pages/123",
            "sections": "/cms-api/v1/admin/pages/123/sections",
            "publish": "/cms-api/v1/admin/pages/123/publish",
            "preview": "/cms-api/v1/pages/welcome?preview=true"
        }
    }
}
```

## 🏷️ Content Negotiation

### Accept Header Support
```
Accept: application/json                    # Default JSON response
Accept: application/vnd.selfhelp.v1+json   # Versioned JSON response
Accept: application/xml                     # XML response (if supported)
```

### Content-Type Requirements
```
Content-Type: application/json  # For POST/PUT/PATCH requests
```

## 🔒 Security Headers

### Required Headers
```
Authorization: Bearer {jwt_token}    # JWT authentication
Content-Type: application/json       # Request content type
Accept: application/json             # Response content type
```

### Response Security Headers
```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000
```

## 🧪 Testing Patterns

### Controller Testing
```php
<?php
public function testCreatePage(): void
{
    $pageData = [
        'keyword' => 'test-page',
        'pageType' => 1,
        'url' => '/test-page',
        'isHeadless' => false
    ];
    
    $response = $this->client->request('POST', '/cms-api/v1/admin/pages', [
        'json' => $pageData,
        'headers' => ['Authorization' => 'Bearer ' . $this->getAuthToken()]
    ]);
    
    $this->assertResponseStatusCodeSame(201);
    $data = json_decode($response->getContent(), true);
    $this->assertEquals('test-page', $data['data']['keyword']);
}
```

### Schema Validation Testing
```php
<?php
public function testRequestValidation(): void
{
    $invalidData = [
        'keyword' => '', // Invalid: empty string
        'pageType' => -1  // Invalid: negative number
    ];
    
    $response = $this->client->request('POST', '/cms-api/v1/admin/pages', [
        'json' => $invalidData,
        'headers' => ['Authorization' => 'Bearer ' . $this->getAuthToken()]
    ]);
    
    $this->assertResponseStatusCodeSame(400);
    $data = json_decode($response->getContent(), true);
    $this->assertArrayHasKey('error_details', $data);
}
```

## 📈 Performance Patterns

### Caching Strategy
```php
<?php
public function getPages(Request $request): JsonResponse
{
    $cacheKey = 'pages_' . md5($request->getQueryString());
    
    $data = $this->cache->get($cacheKey, function (ItemInterface $item) use ($request) {
        $item->expiresAfter(300); // 5 minutes
        return $this->adminPageService->getPages($this->buildFilters($request));
    });
    
    return $this->responseFormatter->formatSuccess($data, 'responses/admin/pages/page');
}
```

### Eager Loading
```php
<?php
// Repository method with eager loading
public function findPagesWithSections(): array
{
    return $this->createQueryBuilder('p')
        ->leftJoin('p.pageSections', 'ps')
        ->leftJoin('ps.section', 's')
        ->addSelect('ps', 's')
        ->getQuery()
        ->getResult();
}
```

---

**Next**: [JSON Schema Validation](./06-json-schema-validation.md)