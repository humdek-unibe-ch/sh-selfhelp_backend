# Authentication APIs

## Overview

The SelfHelp API provides comprehensive authentication and user management functionality. This includes JWT-based authentication with refresh tokens, two-factor authentication support, and user profile management.

## Authentication Flow

```mermaid
sequenceDiagram
    participant Client
    participant API
    participant DB

    Client->>API: POST /auth/login (email, password)
    API->>DB: Validate credentials
    DB-->>API: User data + 2FA status
    alt 2FA Required
        API-->>Client: 2FA required + user_id
        Client->>API: POST /auth/two-factor-verify (code, user_id)
        API->>DB: Verify 2FA code
        DB-->>API: Code valid
    end
    API-->>Client: JWT tokens + user data

    Note over Client,API: Subsequent requests include Bearer token
    Client->>API: GET /protected-endpoint (Authorization: Bearer token)
    API-->>Client: Protected data

    Note over Client,API: Token refresh when needed
    Client->>API: POST /auth/refresh-token (refresh_token)
    API-->>Client: New JWT tokens
```

## Core Authentication Endpoints

### Login

Authenticate a user and receive JWT tokens.

**Endpoint:** `POST /cms-api/v1/auth/login`

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/auth/login.json)
```json
{
  "email": "user@example.com",
  "password": "userpassword"
}
```

**Success Response (Normal Login):**
[View JSON Schema](../../config/schemas/api/v1/responses/auth/login.json)
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
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
    "refresh_token": "abc123def456...",
    "user": {
      "id": 123,
      "email": "user@example.com",
      "name": "John Doe",
      "language_id": 1,
      "language_locale": "en"
    }
  }
}
```

**Success Response (2FA Required):**
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
  "data": {
    "requires_2fa": true,
    "id_users": 123
  }
}
```

**Error Responses:**
- `401 Unauthorized`: Invalid credentials
- `400 Bad Request`: Missing or invalid fields
- `422 Unprocessable Entity`: Validation errors

**Permissions:** None (public endpoint)

### Two-Factor Authentication Verification

Complete the login process when 2FA is enabled.

**Endpoint:** `POST /cms-api/v1/auth/two-factor-verify`

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/auth/2fa_verify.json)
```json
{
  "code": "123456",
  "id_users": 123
}
```

**Success Response:**
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
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
    "refresh_token": "abc123def456...",
    "user": {
      "id": 123,
      "email": "user@example.com",
      "name": "John Doe",
      "language_id": 1,
      "language_locale": "en"
    }
  }
}
```

**Error Responses:**
- `401 Unauthorized`: Invalid or expired verification code
- `404 Not Found`: User not found
- `400 Bad Request`: Missing or invalid fields

**Permissions:** None (public endpoint)

### Refresh Token

Obtain new JWT tokens using a refresh token.

**Endpoint:** `POST /cms-api/v1/auth/refresh-token`

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/auth/refresh_token.json)
```json
{
  "refresh_token": "abc123def456..."
}
```

**Success Response:**
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
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
    "refresh_token": "new_refresh_token_hash..."
  }
}
```

**Error Responses:**
- `401 Unauthorized`: Invalid or expired refresh token
- `400 Bad Request`: Missing refresh token

**Permissions:** None (public endpoint)

### Logout

Invalidate the current session and refresh token.

**Endpoint:** `POST /cms-api/v1/auth/logout`

**Headers:**
```
Authorization: Bearer your_jwt_token
```

**Request Body (Optional):**
```json
{
  "refresh_token": "abc123def456..."
}
```

**Success Response:**
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
  "data": {
    "message": "Successfully logged out"
  }
}
```

**Error Responses:**
- `401 Unauthorized`: No valid access token provided
- `400 Bad Request`: Invalid JSON payload

**Permissions:** None (requires valid JWT token)

## User Data & Profile Management

### Get Current User Data

Retrieve comprehensive user information including roles and permissions.

**Endpoint:** `GET /cms-api/v1/auth/user-data`

**Headers:**
```
Authorization: Bearer your_jwt_token
```

**Success Response:**
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
    "user": {
      "id": 123,
      "email": "user@example.com",
      "name": "John Doe",
      "user_name": "johndoe",
      "user_type_id": 1,
      "blocked": false,
      "id_languages": 1,
      "language_locale": "en",
      "timezone": "Europe/Berlin",
      "last_login": "2025-01-23T09:15:00Z",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2025-01-20T14:45:00Z"
    },
    "roles": [
      {
        "id": 1,
        "name": "admin",
        "description": "Administrator role"
      }
    ],
    "permissions": [
      {
        "name": "admin.access",
        "description": "Can view and enter the admin/backend area"
      },
      {
        "name": "admin.page.read",
        "description": "Can read existing pages"
      }
    ],
    "groups": [
      {
        "id": 1,
        "name": "admin",
        "description": "Admin group"
      }
    ]
  }
}
```

**Permissions:** None (requires valid JWT token)

### Update User Name

Update the current user's display name.

**Endpoint:** `PUT /cms-api/v1/auth/user/name`

**Headers:**
```
Authorization: Bearer your_jwt_token
```

**Request Body:**
```json
{
  "name": "John Smith"
}
```

**Success Response:**
Returns updated user data (same format as GET /auth/user-data)

**Permissions:** None (requires valid JWT token)

### Update User Timezone

Update the current user's timezone preference.

**Endpoint:** `PUT /cms-api/v1/auth/user/timezone`

**Headers:**
```
Authorization: Bearer your_jwt_token
```

**Request Body:**
```json
{
  "timezone": "America/New_York"
}
```

**Success Response:**
Returns updated user data (same format as GET /auth/user-data)

**Permissions:** None (requires valid JWT token)

### Update User Password

Change the current user's password.

**Endpoint:** `PUT /cms-api/v1/auth/user/password`

**Headers:**
```
Authorization: Bearer your_jwt_token
```

**Request Body:**
```json
{
  "current_password": "oldpassword123",
  "new_password": "newpassword456",
  "confirm_password": "newpassword456"
}
```

**Success Response:**
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
    "message": "Password updated successfully"
  }
}
```

**Error Responses:**
- `400 Bad Request`: Current password incorrect, passwords don't match
- `422 Unprocessable Entity`: Password validation failed

**Permissions:** None (requires valid JWT token)

### Delete User Account

Permanently delete the current user's account.

**Endpoint:** `DELETE /cms-api/v1/auth/user/account`

**Headers:**
```
Authorization: Bearer your_jwt_token
```

**Request Body:**
```json
{
  "password": "userpassword",
  "confirmation": "DELETE_MY_ACCOUNT"
}
```

**Success Response:**
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
  "data": {
    "message": "Account deleted successfully"
  }
}
```

**Permissions:** None (requires valid JWT token)

## User Language Settings

### Set User Language

Update the user's preferred language.

**Endpoint:** `POST /cms-api/v1/auth/set-language`

**Headers:**
```
Authorization: Bearer your_jwt_token
```

**Request Body:**
```json
{
  "language_id": 2
}
```

**Success Response:**
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
    "language_id": 2,
    "language_locale": "de",
    "language_name": "German"
  }
}
```

**Permissions:** None (requires valid JWT token)

## User Validation (Account Activation)

### Validate Token

Check if a user validation token is valid before account activation.

**Endpoint:** `GET /cms-api/v1/validate/{user_id}/{token}`

**Path Parameters:**
- `user_id`: User ID (numeric)
- `token`: Validation token (32-character hex string)

**Success Response:**
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
  "data": {
    "user_id": 123,
    "email": "user@example.com",
    "name": "John Doe",
    "token_valid": true,
    "message": "Token is valid. You can proceed with account setup."
  }
}
```

**Error Responses:**
- `400 Bad Request`: Invalid route parameters or token
- `404 Not Found`: User not found

**Permissions:** None (public endpoint)

### Complete Validation

Complete the user account validation process.

**Endpoint:** `POST /cms-api/v1/validate/{user_id}/{token}/complete`

**Path Parameters:**
- `user_id`: User ID (numeric)
- `token`: Validation token (32-character hex string)

**Request Body:**
```json
{
  "name": "John Doe",
  "user_name": "johndoe",
  "password": "securepassword123",
  "confirm_password": "securepassword123",
  "language_id": 1
}
```

**Success Response:**
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
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
    "refresh_token": "abc123def456...",
    "user": {
      "id": 123,
      "email": "user@example.com",
      "name": "John Doe",
      "user_name": "johndoe",
      "language_id": 1,
      "language_locale": "en"
    },
    "message": "Account validated successfully"
  }
}
```

**Error Responses:**
- `400 Bad Request`: Invalid token, validation errors
- `404 Not Found`: User not found
- `409 Conflict`: Account already validated

**Permissions:** None (public endpoint)

## API Discovery

### Get API Routes

Discover all available API routes and their required permissions.

**Endpoint:** `GET /cms-api/v1/admin/api-routes`

**Headers:**
```
Authorization: Bearer your_jwt_token
```

**Query Parameters:**
- `page`: Page number (default: 1)
- `pageSize`: Items per page (default: 50)
- `search`: Search in route names or paths

**Success Response:**
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
      "per_page": 50,
      "total_items": 150,
      "total_pages": 3,
      "has_next": true,
      "has_previous": false,
      "next_page": 2
    }
  },
  "data": {
    "routes": [
      {
        "id": 1,
        "route_name": "auth_login",
        "version": "v1",
        "path": "/auth/login",
        "controller": "App\\Controller\\Api\\V1\\Auth\\AuthController::login",
        "methods": "POST",
        "requirements": null,
        "params": null,
        "required_permissions": []
      },
      {
        "id": 2,
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

**Permissions:** `admin.access`

## Usage Examples

### JavaScript (Frontend Integration)

```javascript
// Login
const login = async (email, password) => {
  const response = await fetch('/cms-api/v1/auth/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ email, password })
  });

  const data = await response.json();

  if (data.data.requires_2fa) {
    // Handle 2FA flow
    return handleTwoFactor(data.data.id_users);
  }

  // Store tokens
  localStorage.setItem('access_token', data.data.access_token);
  localStorage.setItem('refresh_token', data.data.refresh_token);

  return data.data.user;
};

// Make authenticated requests
const apiRequest = async (endpoint, options = {}) => {
  const token = localStorage.getItem('access_token');

  const response = await fetch(`/cms-api/v1${endpoint}`, {
    ...options,
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
      ...options.headers
    }
  });

  // Handle token refresh if needed
  if (response.status === 401) {
    await refreshToken();
    return apiRequest(endpoint, options);
  }

  return response.json();
};

// Refresh token
const refreshToken = async () => {
  const refreshToken = localStorage.getItem('refresh_token');

  const response = await fetch('/cms-api/v1/auth/refresh-token', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ refresh_token: refreshToken })
  });

  const data = await response.json();

  localStorage.setItem('access_token', data.data.access_token);
  localStorage.setItem('refresh_token', data.data.refresh_token);
};
```

### PHP (Backend Integration)

```php
<?php
class ApiClient
{
    private $baseUrl;
    private $accessToken;
    private $refreshToken;

    public function __construct($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    public function login($email, $password)
    {
        $response = $this->makeRequest('POST', '/auth/login', [
            'email' => $email,
            'password' => $password
        ]);

        if (isset($response['data']['requires_2fa'])) {
            // Handle 2FA
            return $this->verifyTwoFactor($response['data']['id_users'], $code);
        }

        $this->accessToken = $response['data']['access_token'];
        $this->refreshToken = $response['data']['refresh_token'];

        return $response['data']['user'];
    }

    public function makeAuthenticatedRequest($method, $endpoint, $data = null)
    {
        $response = $this->makeRequest($method, $endpoint, $data, true);

        // Handle token refresh
        if ($response['status'] === 401) {
            $this->refreshTokens();
            return $this->makeAuthenticatedRequest($method, $endpoint, $data);
        }

        return $response;
    }

    private function refreshTokens()
    {
        $response = $this->makeRequest('POST', '/auth/refresh-token', [
            'refresh_token' => $this->refreshToken
        ]);

        $this->accessToken = $response['data']['access_token'];
        $this->refreshToken = $response['data']['refresh_token'];
    }

    private function makeRequest($method, $endpoint, $data = null, $authenticated = false)
    {
        $url = $this->baseUrl . '/cms-api/v1' . $endpoint;

        $headers = ['Content-Type: application/json'];

        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}
```

## Security Considerations

1. **Token Storage**: Store tokens securely (HttpOnly cookies recommended for web apps)
2. **Token Refresh**: Implement automatic token refresh before expiration
3. **Password Requirements**: Enforce strong password policies
4. **Rate Limiting**: Respect API rate limits to avoid blocking
5. **HTTPS Only**: Always use HTTPS in production
6. **Token Expiration**: Handle expired tokens gracefully

## Error Handling

All authentication endpoints follow the standard API error format. Common error scenarios:

- **Invalid Credentials**: 401 with message "Invalid credentials"
- **Expired Token**: 401 with message "JWT Token not found" or "Expired JWT Token"
- **Missing Permissions**: 403 with message "Access Denied"
- **Validation Errors**: 400/422 with validation details in the response

---

**Next:** [User Profile Management](./02-user-profile.md) | **Back to:** [API Overview](../README.md)
