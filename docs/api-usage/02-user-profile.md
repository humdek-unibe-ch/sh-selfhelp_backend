# User Profile Management APIs

## Overview

The User Profile Management APIs provide endpoints for users to manage their own account settings, preferences, and personal information. These endpoints require authentication and operate on the currently logged-in user.

## Profile Update Endpoints

### Update User Name

Update the display name for the current user.

**Endpoint:** `PUT /cms-api/v1/auth/user/name`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/auth/update_name.json)
```json
{
  "name": "John Smith"
}
```

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
    "user": {
      "id": 123,
      "email": "user@example.com",
      "name": "John Smith",
      "user_name": "johndoe",
      "user_type_id": 1,
      "blocked": false,
      "id_languages": 1,
      "language_locale": "en",
      "timezone": "Europe/Berlin",
      "last_login": "2025-01-23T09:15:00Z",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2025-01-23T10:30:00Z"
    },
    "roles": [...],
    "permissions": [...],
    "groups": [...]
  }
}
```

**Validation Rules:**
- `name`: Required, string, 1-255 characters

**Error Responses:**
- `400 Bad Request`: Invalid name format
- `401 Unauthorized`: Not authenticated

### Update User Timezone

Set the user's timezone preference for date/time display.

**Endpoint:** `PUT /cms-api/v1/auth/user/timezone`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/auth/update_timezone.json)
```json
{
  "timezone": "America/New_York"
}
```

**Response:**
Returns complete user data (same format as name update)

**Validation Rules:**
- `timezone`: Must be a valid PHP timezone identifier (e.g., "America/New_York", "Europe/London")

**Common Timezones:**
- `UTC` - Coordinated Universal Time
- `America/New_York` - Eastern Time
- `America/Chicago` - Central Time
- `America/Denver` - Mountain Time
- `America/Los_Angeles` - Pacific Time
- `Europe/London` - GMT/BST
- `Europe/Berlin` - CET/CEST
- `Asia/Tokyo` - Japan Standard Time
- `Australia/Sydney` - Australian Eastern Time

### Update Password

Change the user's password with current password verification.

**Endpoint:** `PUT /cms-api/v1/auth/user/password`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/auth/update_password.json)
```json
{
  "current_password": "oldpassword123",
  "new_password": "newSecurePassword456!",
  "confirm_password": "newSecurePassword456!"
}
```

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
    "message": "Password updated successfully"
  }
}
```

**Password Requirements:**
- Minimum 8 characters
- Must contain at least one uppercase letter
- Must contain at least one lowercase letter
- Must contain at least one number
- Must contain at least one special character
- `new_password` and `confirm_password` must match

**Error Responses:**
- `400 Bad Request`: Current password incorrect, passwords don't match
- `422 Unprocessable Entity`: Password doesn't meet requirements

### Delete Account

Permanently delete the user's account and all associated data.

**Endpoint:** `DELETE /cms-api/v1/auth/user/account`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
[View JSON Schema](../../config/schemas/api/v1/requests/auth/delete_account.json)
```json
{
  "password": "userpassword",
  "confirmation": "DELETE_MY_ACCOUNT"
}
```

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
  "data": {
    "message": "Account deleted successfully"
  }
}
```

**Security Notes:**
- Requires current password verification
- Requires exact confirmation text "DELETE_MY_ACCOUNT"
- This action cannot be undone
- All user data will be permanently removed

**Error Responses:**
- `400 Bad Request`: Incorrect password or confirmation text
- `401 Unauthorized`: Not authenticated

## User Data Retrieval

### Get Current User Data

Retrieve comprehensive information about the current user including roles, permissions, and groups.

**Endpoint:** `GET /cms-api/v1/auth/user-data`

**Authentication:** Required (JWT Bearer token)

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
        "description": "Administrator role with full access"
      },
      {
        "id": 2,
        "name": "editor",
        "description": "Content editor role"
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
      },
      {
        "name": "admin.page.create",
        "description": "Can create new pages"
      }
    ],
    "groups": [
      {
        "id": 1,
        "name": "admin",
        "description": "Administrator group"
      }
    ]
  }
}
```

**Data Fields:**
- `user`: Basic user information
- `roles`: Array of roles assigned to the user
- `permissions`: Array of effective permissions (combined from all roles)
- `groups`: Array of groups the user belongs to

## Language Settings

### Set User Language

Update the user's preferred language for the interface.

**Endpoint:** `POST /cms-api/v1/auth/set-language`

**Authentication:** Required (JWT Bearer token)

**Request Body:**
```json
{
  "language_id": 2
}
```

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
    "language_id": 2,
    "language_locale": "de",
    "language_name": "German"
  }
}
```

**Notes:**
- `language_id` must reference an existing language in the system
- This affects UI language and date/time formatting

## Frontend Integration Examples

### Profile Update Form

```javascript
// Update user name
const updateName = async (newName) => {
  try {
    const response = await apiRequest('/auth/user/name', {
      method: 'PUT',
      body: JSON.stringify({ name: newName })
    });

    if (response.status === 200) {
      // Update local user state
      setUser(response.data.user);
      showSuccess('Name updated successfully');
    }
  } catch (error) {
    showError('Failed to update name');
  }
};

// Update password
const updatePassword = async (currentPassword, newPassword, confirmPassword) => {
  try {
    const response = await apiRequest('/auth/user/password', {
      method: 'PUT',
      body: JSON.stringify({
        current_password: currentPassword,
        new_password: newPassword,
        confirm_password: confirmPassword
      })
    });

    if (response.status === 200) {
      showSuccess('Password updated successfully');
      // Clear form
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    }
  } catch (error) {
    if (error.response?.data?.error) {
      showError(error.response.data.error);
    } else {
      showError('Failed to update password');
    }
  }
};

// Update timezone
const updateTimezone = async (timezone) => {
  try {
    const response = await apiRequest('/auth/user/timezone', {
      method: 'PUT',
      body: JSON.stringify({ timezone })
    });

    if (response.status === 200) {
      setUser(response.data.user);
      showSuccess('Timezone updated successfully');
    }
  } catch (error) {
    showError('Failed to update timezone');
  }
};
```

### Account Deletion

```javascript
const deleteAccount = async (password) => {
  // Show confirmation dialog
  const confirmed = await showConfirmationDialog(
    'Are you sure you want to delete your account?',
    'This action cannot be undone. All your data will be permanently removed.'
  );

  if (!confirmed) return;

  try {
    const response = await apiRequest('/auth/user/account', {
      method: 'DELETE',
      body: JSON.stringify({
        password: password,
        confirmation: 'DELETE_MY_ACCOUNT'
      })
    });

    if (response.status === 200) {
      // Clear local storage and redirect to login
      localStorage.removeItem('access_token');
      localStorage.removeItem('refresh_token');
      window.location.href = '/login';
    }
  } catch (error) {
    showError('Failed to delete account');
  }
};
```

### Timezone Selector

```javascript
// Get available timezones (you might want to hardcode common ones)
const COMMON_TIMEZONES = [
  { value: 'UTC', label: 'UTC' },
  { value: 'America/New_York', label: 'Eastern Time' },
  { value: 'America/Chicago', label: 'Central Time' },
  { value: 'America/Denver', label: 'Mountain Time' },
  { value: 'America/Los_Angeles', label: 'Pacific Time' },
  { value: 'Europe/London', label: 'London' },
  { value: 'Europe/Berlin', label: 'Berlin' },
  { value: 'Asia/Tokyo', label: 'Tokyo' },
  { value: 'Australia/Sydney', label: 'Sydney' }
];

const TimezoneSelector = ({ currentTimezone, onChange }) => {
  return (
    <select
      value={currentTimezone}
      onChange={(e) => onChange(e.target.value)}
    >
      {COMMON_TIMEZONES.map(tz => (
        <option key={tz.value} value={tz.value}>
          {tz.label}
        </option>
      ))}
    </select>
  );
};
```

## Security Best Practices

1. **Password Strength**: Always enforce strong password requirements
2. **Current Password Verification**: Require current password for sensitive changes
3. **Confirmation for Destructive Actions**: Require explicit confirmation for account deletion
4. **Rate Limiting**: Implement rate limiting on profile update endpoints
5. **Audit Logging**: Log all profile changes for security monitoring
6. **Data Validation**: Validate all input data against strict schemas
7. **Secure Storage**: Never store sensitive data in local storage

## Error Handling

```javascript
// Comprehensive error handling for profile updates
const handleProfileUpdate = async (endpoint, data) => {
  try {
    const response = await apiRequest(endpoint, {
      method: 'PUT',
      body: JSON.stringify(data)
    });

    if (response.status === 200) {
      return { success: true, data: response.data };
    }
  } catch (error) {
    const errorData = error.response?.data;

    if (errorData?.status === 400) {
      // Validation errors
      return {
        success: false,
        error: 'Validation failed',
        details: errorData.error
      };
    } else if (errorData?.status === 401) {
      // Authentication error
      return {
        success: false,
        error: 'Authentication required',
        action: 'redirect_to_login'
      };
    } else if (errorData?.status === 422) {
      // Business logic validation
      return {
        success: false,
        error: 'Invalid data',
        validation: errorData.validation
      };
    } else {
      // Generic error
      return {
        success: false,
        error: 'An unexpected error occurred'
      };
    }
  }
};
```

---

**Next:** [User Validation APIs](./03-user-validation.md) | **Previous:** [Authentication](./01-authentication.md) | **Back to:** [API Overview](../README.md)
