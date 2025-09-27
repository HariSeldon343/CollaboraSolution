# Authentication API Documentation

## Base URL
```
http://localhost/CollaboraNexio/api/auth.php
```

## Response Format
All API responses follow this consistent JSON structure:

```json
{
    "success": boolean,
    "data": {} | [] | null,
    "message": "Human-readable message",
    "metadata": {
        "timestamp": "ISO 8601 format",
        "request_id": "unique request identifier",
        "page": number (for paginated responses),
        "total": number (total count for lists)
    }
}
```

## Security Headers
All responses include these security headers:
- `Content-Type: application/json`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Cache-Control: no-cache, no-store, must-revalidate`

## Rate Limiting
- Login endpoint: 5 attempts per 5 minutes per IP
- Other endpoints: 60 requests per minute per IP
- Returns HTTP 429 when rate limit is exceeded

---

## Endpoints

### 1. Login
**Endpoint:** `POST /api/auth.php?action=login`

**Purpose:** Authenticate user and create session

**Request Headers:**
```
Content-Type: application/json
X-CSRF-Token: {token} (optional, if CSRF protection enabled)
```

**Request Body:**
```json
{
    "email": "user@example.com",
    "password": "secure_password",
    "remember_me": false,
    "csrf_token": "token_value" (optional)
}
```

**Success Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "email": "user@example.com",
            "name": "John Doe",
            "role": "admin",
            "tenant_id": 1,
            "tenant_code": "ACME",
            "is_platform_admin": false
        },
        "session_id": "abc123...",
        "csrf_token": "xyz789...",
        "token": "jwt_token_here" (if JWT enabled)
    },
    "message": "Login successful",
    "metadata": {
        "timestamp": "2025-01-21T12:00:00Z",
        "request_id": "req_65abc123",
        "session_timeout": 1800
    }
}
```

**Error Responses:**

401 Unauthorized - Invalid credentials:
```json
{
    "success": false,
    "data": {
        "error_code": "INVALID_CREDENTIALS",
        "details": {}
    },
    "message": "Invalid email or password",
    "metadata": {...}
}
```

429 Too Many Requests - Rate limit exceeded:
```json
{
    "success": false,
    "data": {
        "error_code": "RATE_LIMIT_EXCEEDED",
        "details": {
            "remaining_attempts": 0,
            "retry_after": 300
        }
    },
    "message": "Too many login attempts. Please try again later",
    "metadata": {...}
}
```

403 Forbidden - Account inactive:
```json
{
    "success": false,
    "data": {
        "error_code": "ACCOUNT_INACTIVE",
        "details": {}
    },
    "message": "Account is not active",
    "metadata": {...}
}
```

**2FA Required Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "requires_2fa": true,
        "session_id": "temp_session_id"
    },
    "message": "Two-factor authentication required",
    "metadata": {...}
}
```

---

### 2. Logout
**Endpoint:** `POST /api/auth.php?action=logout`

**Purpose:** Terminate user session and clear authentication

**Request Headers:**
```
Content-Type: application/json
```

**Request Body:** None required

**Success Response (200 OK):**
```json
{
    "success": true,
    "data": null,
    "message": "Logout successful",
    "metadata": {
        "timestamp": "2025-01-21T12:05:00Z",
        "request_id": "req_65abc456"
    }
}
```

---

### 3. Check Authentication
**Endpoint:** `GET /api/auth.php?action=check`

**Purpose:** Quick check if user is authenticated

**Request Headers:** None required

**Authenticated Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "authenticated": true,
        "user": {
            "id": 1,
            "email": "user@example.com",
            "name": "John Doe",
            "role": "admin",
            "tenant_name": "ACME Corp"
        }
    },
    "message": "User is authenticated",
    "metadata": {...}
}
```

**Not Authenticated Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "authenticated": false
    },
    "message": "User is not authenticated",
    "metadata": {...}
}
```

---

### 4. Get Session Details
**Endpoint:** `GET /api/auth.php?action=session`

**Purpose:** Retrieve complete session information for authenticated user

**Request Headers:** None required

**Success Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "session_id": "abc123...",
        "user": {
            "id": 1,
            "email": "user@example.com",
            "name": "John Doe",
            "role": "admin",
            "language": "it",
            "is_platform_admin": false,
            "is_multi_tenant": true
        },
        "tenant": {
            "id": 1,
            "code": "ACME",
            "name": "ACME Corporation"
        },
        "accessible_tenants": [
            {"id": 1, "code": "ACME", "name": "ACME Corporation"},
            {"id": 2, "code": "BETA", "name": "Beta Company"}
        ],
        "session_info": {
            "login_time": 1705842000,
            "last_activity": 1705843200,
            "ip_address": "192.168.1.100"
        },
        "csrf_token": "xyz789..."
    },
    "message": "Session details retrieved",
    "metadata": {...}
}
```

**Error Response (401 Unauthorized):**
```json
{
    "success": false,
    "data": {
        "error_code": "NOT_AUTHENTICATED",
        "details": {}
    },
    "message": "Not authenticated",
    "metadata": {...}
}
```

---

### 5. Refresh Session
**Endpoint:** `POST /api/auth.php?action=refresh`

**Purpose:** Refresh session timeout and regenerate security tokens

**Request Headers:**
```
Content-Type: application/json
```

**Success Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "session_id": "new_session_id",
        "csrf_token": "new_csrf_token",
        "expires_in": 1800
    },
    "message": "Session refreshed successfully",
    "metadata": {...}
}
```

---

### 6. Get CSRF Token
**Endpoint:** `GET /api/auth.php?action=csrf`

**Purpose:** Generate new CSRF token for form submissions

**Request Headers:** None required

**Success Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "csrf_token": "abc123xyz789..."
    },
    "message": "CSRF token generated",
    "metadata": {...}
}
```

---

## Error Codes Reference

| Code | HTTP Status | Description |
|------|------------|-------------|
| INVALID_ACTION | 400 | Unknown or missing action parameter |
| VALIDATION_ERROR | 400 | Input validation failed |
| INVALID_EMAIL | 400 | Email format is invalid |
| METHOD_NOT_ALLOWED | 405 | Wrong HTTP method for endpoint |
| INVALID_CREDENTIALS | 401 | Email or password incorrect |
| NOT_AUTHENTICATED | 401 | User not logged in |
| ACCOUNT_INACTIVE | 403 | User account is disabled |
| TENANT_INACTIVE | 403 | Tenant organization is disabled |
| CSRF_VALIDATION_FAILED | 403 | CSRF token validation failed |
| RATE_LIMIT_EXCEEDED | 429 | Too many requests |
| ACCOUNT_LOCKED | 429 | Account locked due to failed attempts |
| INTERNAL_ERROR | 500 | Server error occurred |

---

## CORS Configuration

When CORS is enabled in configuration, the API supports:
- Allowed Origins: Configured in CORS_ORIGINS constant
- Allowed Methods: GET, POST, PUT, DELETE, OPTIONS
- Allowed Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token
- Credentials: Supported (cookies/sessions)
- Max Age: 86400 seconds (24 hours)

---

## Integration Examples

### JavaScript/Fetch Example
```javascript
// Login
async function login(email, password) {
    const response = await fetch('http://localhost/CollaboraNexio/api/auth.php?action=login', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({
            email: email,
            password: password,
            remember_me: false
        })
    });

    const data = await response.json();

    if (data.success) {
        console.log('Login successful', data.data.user);
        // Store CSRF token for future requests
        localStorage.setItem('csrf_token', data.data.csrf_token);
    } else {
        console.error('Login failed:', data.message);
    }
}

// Check authentication
async function checkAuth() {
    const response = await fetch('http://localhost/CollaboraNexio/api/auth.php?action=check', {
        method: 'GET',
        credentials: 'include'
    });

    const data = await response.json();
    return data.data.authenticated;
}

// Logout
async function logout() {
    const response = await fetch('http://localhost/CollaboraNexio/api/auth.php?action=logout', {
        method: 'POST',
        credentials: 'include'
    });

    const data = await response.json();
    if (data.success) {
        localStorage.removeItem('csrf_token');
        window.location.href = '/login';
    }
}
```

### PHP/cURL Example
```php
// Login request
$ch = curl_init('http://localhost/CollaboraNexio/api/auth.php?action=login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'user@example.com',
    'password' => 'password123'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);
if ($data['success']) {
    // Login successful
    $user = $data['data']['user'];
}
```

---

## Security Best Practices

1. **Always use HTTPS in production** - Never send credentials over unencrypted connections
2. **Store CSRF tokens securely** - Use httpOnly cookies or secure session storage
3. **Implement request signing** - For enhanced security, sign API requests
4. **Monitor rate limits** - Track and respond to rate limit headers
5. **Handle errors gracefully** - Don't expose sensitive information in error messages
6. **Validate SSL certificates** - Ensure proper certificate validation in production
7. **Use strong passwords** - Enforce password complexity requirements
8. **Enable 2FA when possible** - Support two-factor authentication for enhanced security
9. **Audit API access** - Log and monitor authentication attempts
10. **Keep sessions short** - Use appropriate session timeouts

---

## Testing with cURL

```bash
# Login
curl -X POST "http://localhost/CollaboraNexio/api/auth.php?action=login" \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password123"}' \
  -c cookies.txt

# Check authentication
curl -X GET "http://localhost/CollaboraNexio/api/auth.php?action=check" \
  -b cookies.txt

# Get session details
curl -X GET "http://localhost/CollaboraNexio/api/auth.php?action=session" \
  -b cookies.txt

# Logout
curl -X POST "http://localhost/CollaboraNexio/api/auth.php?action=logout" \
  -b cookies.txt

# Get CSRF token
curl -X GET "http://localhost/CollaboraNexio/api/auth.php?action=csrf" \
  -b cookies.txt
```

---

## Migration Requirements

To use the rate limiting feature, execute the following SQL migration:

```sql
CREATE TABLE IF NOT EXISTS `api_rate_limits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identifier` VARCHAR(255) NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_identifier_action` (`identifier`, `action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Version History

- **v1.0.0** (2025-01-21): Initial release with complete authentication endpoints