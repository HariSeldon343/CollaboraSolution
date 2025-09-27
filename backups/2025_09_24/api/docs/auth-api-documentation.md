# Authentication API Documentation

## Overview

The CollaboraNexio Authentication API provides secure endpoints for user authentication, session management, and authorization checks. All endpoints follow REST principles and return standardized JSON responses.

**Base URL:** `/api/auth.php`

## Response Format

All API responses follow this standardized structure:

```json
{
    "success": boolean,
    "data": {} | [] | null,
    "message": "Human-readable message",
    "metadata": {
        "timestamp": "ISO 8601 format",
        "page": number (optional, for paginated responses),
        "total": number (optional, total count for lists)
    }
}
```

## Security Headers

All responses include the following security headers:

- `Content-Type: application/json; charset=UTF-8`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- `Referrer-Policy: strict-origin-when-cross-origin`

## Endpoints

### 1. User Login

Authenticates a user and creates a new session.

**Endpoint:** `POST /api/auth.php?action=login`

**Request Headers:**
```
Content-Type: application/json
```

**Request Body:**
```json
{
    "email": "user@example.com",
    "password": "userpassword"
}
```

**Success Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "user@example.com",
            "role": "admin",
            "tenant_name": "ACME Corp"
        }
    },
    "message": "Login successful",
    "metadata": {
        "timestamp": "2024-01-20T10:30:00Z",
        "session_id": "sess_****"
    }
}
```

**Error Response (401 Unauthorized):**
```json
{
    "success": false,
    "data": null,
    "message": "Authentication failed",
    "metadata": {
        "timestamp": "2024-01-20T10:30:00Z"
    }
}
```

**Error Response (400 Bad Request):**
```json
{
    "success": false,
    "data": null,
    "message": "Missing required parameters: email and password",
    "metadata": {
        "timestamp": "2024-01-20T10:30:00Z"
    }
}
```

### 2. User Logout

Terminates the current user session.

**Endpoint:** `POST /api/auth.php?action=logout`

**Request Headers:**
```
None required (uses session cookies)
```

**Success Response (200 OK):**
```json
{
    "success": true,
    "data": null,
    "message": "Logout successful",
    "metadata": {
        "timestamp": "2024-01-20T10:30:00Z"
    }
}
```

### 3. Check Authentication Status

Verifies if the current user is authenticated.

**Endpoint:** `GET /api/auth.php?action=check`

**Request Headers:**
```
None required (uses session cookies)
```

**Success Response - Authenticated (200 OK):**
```json
{
    "success": true,
    "data": {
        "authenticated": true,
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "user@example.com",
            "role": "admin",
            "tenant_name": "ACME Corp"
        }
    },
    "message": "User is authenticated",
    "metadata": {
        "timestamp": "2024-01-20T10:30:00Z"
    }
}
```

**Success Response - Not Authenticated (200 OK):**
```json
{
    "success": true,
    "data": {
        "authenticated": false
    },
    "message": "User is not authenticated",
    "metadata": {
        "timestamp": "2024-01-20T10:30:00Z"
    }
}
```

### 4. Get Session Details

Retrieves detailed information about the current session.

**Endpoint:** `GET /api/auth.php?action=session`

**Request Headers:**
```
None required (uses session cookies)
```

**Success Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "session": {
            "id": "sess_****",
            "created_at": "2024-01-20T10:00:00Z",
            "last_activity": "2024-01-20T10:25:00Z",
            "expires_at": "2024-01-20T10:55:00Z",
            "time_remaining_seconds": 1800,
            "ip_address": "192.168.1.1",
            "user_agent": "Mozilla/5.0..."
        },
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "user@example.com",
            "role": "admin",
            "avatar": null
        },
        "tenant": {
            "id": 1,
            "name": "ACME Corp"
        },
        "multi_tenant": {
            "enabled": false,
            "available_tenants": []
        }
    },
    "message": "Session details retrieved successfully",
    "metadata": {
        "timestamp": "2024-01-20T10:30:00Z",
        "total": 1,
        "page": 1
    }
}
```

**Error Response (401 Unauthorized):**
```json
{
    "success": false,
    "data": null,
    "message": "Authentication required",
    "metadata": {
        "timestamp": "2024-01-20T10:30:00Z"
    }
}
```

## Error Codes

| HTTP Status | Description | Common Causes |
|-------------|-------------|---------------|
| 200 | Success | Request processed successfully |
| 400 | Bad Request | Missing or invalid parameters |
| 401 | Unauthorized | Authentication failed or required |
| 405 | Method Not Allowed | Wrong HTTP method for endpoint |
| 429 | Too Many Requests | Rate limit exceeded |
| 500 | Internal Server Error | Server-side error |

## Rate Limiting

The API implements rate limiting to prevent abuse:

- **Login attempts:** 5 attempts per 5 minutes per IP address
- **API requests:** 100 requests per minute per authenticated session

When rate limit is exceeded, the API returns a 429 status code with retry information.

## CORS Support

The API supports Cross-Origin Resource Sharing (CORS) with:

- Allowed methods: GET, POST, OPTIONS
- Allowed headers: Content-Type, Authorization, X-CSRF-Token
- Credentials: Supported (cookies included)

## Security Features

1. **Session Security:**
   - HTTP-only cookies
   - Secure flag enabled (HTTPS only)
   - SameSite attribute set to Strict
   - Session regeneration on login
   - 30-minute timeout with automatic renewal

2. **Input Validation:**
   - Email format validation
   - Password minimum requirements
   - SQL injection prevention
   - XSS protection

3. **Rate Limiting:**
   - Failed login attempt tracking
   - IP-based blocking for suspicious activity
   - Gradual backoff for repeated failures

4. **Logging:**
   - All authentication events logged
   - Failed attempts tracked
   - Session activities monitored

## Usage Examples

### JavaScript/Fetch

```javascript
// Login
const response = await fetch('/api/auth.php?action=login', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    credentials: 'include',
    body: JSON.stringify({
        email: 'user@example.com',
        password: 'password123'
    })
});
const data = await response.json();

// Check authentication
const response = await fetch('/api/auth.php?action=check', {
    credentials: 'include'
});
const data = await response.json();

// Logout
const response = await fetch('/api/auth.php?action=logout', {
    method: 'POST',
    credentials: 'include'
});
const data = await response.json();
```

### PHP/cURL

```php
// Login
$ch = curl_init('http://localhost/api/auth.php?action=login');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'user@example.com',
    'password' => 'password123'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt');
$response = curl_exec($ch);
$data = json_decode($response, true);
```

## Migration Guide

### From Old API

If migrating from the previous authentication system:

1. Update endpoint URLs to use query parameters (`?action=...`)
2. Ensure all requests send JSON in request body (not form data)
3. Update response handling to use the standardized format
4. Check for `success` field instead of HTTP status alone
5. Extract user data from `data.user` instead of root level

## Support

For API support or to report issues:
- Documentation: `/api/docs/`
- Test Console: `/api/test_auth_api.html`
- Error Tracking: Check `metadata.error_id` in error responses

---

*Last Updated: January 2024*
*API Version: 2.0.0*