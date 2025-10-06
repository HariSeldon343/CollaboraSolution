# Session Sharing Configuration Guide

## Overview
This guide explains how the session sharing between localhost and production (app.nexiosolution.it) has been configured.

## Configuration Changes

### 1. Session Initialization (`/includes/session_init.php`)
- Automatically detects the environment (production vs development)
- Sets appropriate cookie domain:
  - Production: `.nexiosolution.it` (with leading dot for subdomain support)
  - Development: empty (for localhost)
- Uses common session name: `COLLAB_SID`
- Configures secure cookies for HTTPS in production
- Uses `Lax` SameSite policy to allow cross-domain navigation

### 2. Configuration File (`/config.php`)
- Dynamic environment detection based on hostname
- Automatic BASE_URL configuration:
  - Production: `https://app.nexiosolution.it/CollaboraNexio`
  - Development: `http://localhost:8888/CollaboraNexio` (or appropriate port)
- Environment-specific error reporting

### 3. Authentication (`/includes/auth.php`)
- Updated to use centralized session initialization
- Proper cookie domain handling in logout function

### 4. CORS Helper (`/includes/cors_helper.php`)
- Allows cross-origin requests between allowed domains
- Manages security headers appropriately

### 5. Session Sync API (`/api/session/sync.php`)
- Provides endpoints for session management:
  - `check`: Verify session status
  - `validate`: Validate CSRF token
  - `refresh`: Refresh CSRF token
  - `bridge`: Create bridge token for session transfer
  - `restore`: Restore session from bridge token

## Testing

### Test Session Configuration
Access `/test_session_config.php` to verify:
- Current environment detection
- Session cookie parameters
- Cookie domain settings

### Session Bridging (Advanced)
For transferring sessions between domains:

1. On source domain:
```javascript
fetch('/api/session/sync.php?action=bridge')
  .then(r => r.json())
  .then(data => {
    // Use data.bridge_token to transfer session
  });
```

2. On target domain:
```javascript
fetch('/api/session/sync.php?action=restore', {
  method: 'POST',
  body: JSON.stringify({ bridge_token: token })
});
```

## Important Notes

### Security Considerations
- Session cookies are httpOnly and secure (in production)
- CSRF tokens are maintained across environments
- Bridge tokens expire after 60 seconds
- Session data is isolated per tenant

### Cookie Behavior
- Production cookies use domain `.nexiosolution.it`
- This allows sharing between:
  - app.nexiosolution.it
  - www.nexiosolution.it
  - Any other subdomain
- Localhost uses no domain restriction

### Debugging
- Check `/test_session_config.php` for current configuration
- Use browser DevTools to inspect cookies
- Verify `COLLAB_SID` cookie is set with correct domain

### Migration Notes
- Existing sessions may need to be re-established after update
- Users may need to log in again initially
- CSRF tokens will be regenerated

## Troubleshooting

### Session Not Sharing
1. Verify cookie domain in browser DevTools
2. Check that session name is `COLLAB_SID`
3. Ensure HTTPS is used in production
4. Clear browser cookies and retry

### CSRF Token Mismatch
1. Use `/api/session/sync.php?action=refresh` to get new token
2. Ensure token is passed in all state-changing requests
3. Check token hasn't expired

### Environment Detection Issues
1. Check `$_SERVER['HTTP_HOST']` value
2. Verify BASE_URL is correctly set
3. Review `/test_session_config.php` output

## Rollback
To revert changes:
1. Restore original `/includes/session_init.php`
2. Restore original `/config.php`
3. Restore original `/includes/auth.php`
4. Remove `/includes/cors_helper.php`
5. Remove `/api/session/sync.php`
6. Remove test files