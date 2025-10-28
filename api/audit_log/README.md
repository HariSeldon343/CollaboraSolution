# Audit Log API Endpoints

**Version:** 1.0.0
**Date:** 2025-10-27
**Status:** Production Ready

---

## Quick Reference

### Endpoints Overview

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/audit_log/list.php` | GET | admin, super_admin | List logs with filters and pagination |
| `/api/audit_log/detail.php` | GET | admin, super_admin | Get single log details |
| `/api/audit_log/delete.php` | POST | super_admin ONLY | Delete logs with tracking |
| `/api/audit_log/stats.php` | GET | admin, super_admin | Dashboard statistics |

---

## 1. List Logs API

**URL:** `GET /api/audit_log/list.php`

**Query Parameters:**
```
?page=1
&per_page=50
&date_from=2025-01-01 00:00:00
&date_to=2025-12-31 23:59:59
&user_id=123
&tenant_id=1
&action=file_uploaded
&entity_type=file
&severity=critical
&sort=created_at
&order=DESC
```

**cURL Example:**
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/audit_log/list.php?page=1&per_page=50" \
  -H "Cookie: PHPSESSID=your-session-id"
```

**JavaScript Example:**
```javascript
fetch('/api/audit_log/list.php?page=1&per_page=50&severity=critical')
  .then(res => res.json())
  .then(data => {
    console.log(`Found ${data.data.pagination.total_records} logs`);
    console.log(data.data.logs);
  });
```

---

## 2. Detail Log API

**URL:** `GET /api/audit_log/detail.php`

**Query Parameters:**
```
?id=123
```

**cURL Example:**
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/audit_log/detail.php?id=123" \
  -H "Cookie: PHPSESSID=your-session-id"
```

**JavaScript Example:**
```javascript
fetch('/api/audit_log/detail.php?id=123')
  .then(res => res.json())
  .then(data => {
    const log = data.data.log;
    console.log(`Action: ${log.action}, User: ${log.user_name}`);
  });
```

---

## 3. Delete Logs API (Super Admin Only)

**URL:** `POST /api/audit_log/delete.php`

**Request Body (JSON):**
```json
{
  "mode": "range",
  "date_from": "2025-01-01 00:00:00",
  "date_to": "2025-01-31 23:59:59",
  "reason": "Scheduled cleanup per 90-day retention policy",
  "tenant_id": 1,
  "csrf_token": "your-csrf-token"
}
```

**Mode Options:**
- `"all"` - Delete ALL logs for tenant (use with caution!)
- `"range"` - Delete logs within date range (recommended)

**cURL Example:**
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/api/audit_log/delete.php" \
  -H "Cookie: PHPSESSID=your-session-id" \
  -H "Content-Type: application/json" \
  -d '{
    "mode": "range",
    "date_from": "2025-01-01 00:00:00",
    "date_to": "2025-01-31 23:59:59",
    "reason": "Maintenance cleanup",
    "tenant_id": 1,
    "csrf_token": "abc123..."
  }'
```

**JavaScript Example:**
```javascript
fetch('/api/audit_log/delete.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify({
    mode: 'range',
    date_from: '2025-01-01 00:00:00',
    date_to: '2025-01-31 23:59:59',
    reason: 'Scheduled cleanup',
    tenant_id: 1,
    csrf_token: csrfToken
  })
})
.then(res => res.json())
.then(data => {
  console.log(`Deleted ${data.data.deleted_count} logs`);
  console.log(`Deletion ID: ${data.data.deletion_id}`);
});
```

---

## 4. Statistics API

**URL:** `GET /api/audit_log/stats.php`

**No parameters required**

**cURL Example:**
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/audit_log/stats.php" \
  -H "Cookie: PHPSESSID=your-session-id"
```

**JavaScript Example:**
```javascript
fetch('/api/audit_log/stats.php')
  .then(res => res.json())
  .then(data => {
    const stats = data.data;
    console.log(`Events today: ${stats.events_today}`);
    console.log(`Active users: ${stats.active_users}`);
    console.log(`Critical events: ${stats.critical_events}`);
  });
```

---

## Response Format

### Success Response
```json
{
  "success": true,
  "data": {...},
  "message": "Operation successful"
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error message"
}
```

### HTTP Status Codes
- `200 OK` - Success
- `400 Bad Request` - Invalid parameters
- `401 Unauthorized` - Not authenticated
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `500 Internal Server Error` - Server error

---

## Security Notes

### Authentication Required
All endpoints require active session. If not authenticated, returns:
```json
{
  "error": "Non autorizzato",
  "success": false
}
```
HTTP Status: `401 Unauthorized`

### CSRF Protection
Delete endpoint requires valid CSRF token in request body:
```json
{
  "csrf_token": "your-csrf-token",
  ...
}
```

Get CSRF token from frontend:
```javascript
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
```

### Role-Based Access
- **list.php, detail.php, stats.php:** admin OR super_admin
- **delete.php:** super_admin ONLY

Attempting access with insufficient role returns:
```json
{
  "error": "Accesso negato. Solo super_admin...",
  "success": false
}
```
HTTP Status: `403 Forbidden`

---

## Filtering Examples

### Filter by Date Range
```javascript
fetch('/api/audit_log/list.php?' + new URLSearchParams({
  date_from: '2025-01-01 00:00:00',
  date_to: '2025-12-31 23:59:59',
  per_page: 100
}))
```

### Filter by User
```javascript
fetch('/api/audit_log/list.php?' + new URLSearchParams({
  user_id: 123,
  page: 1
}))
```

### Filter by Severity
```javascript
fetch('/api/audit_log/list.php?' + new URLSearchParams({
  severity: 'critical',
  sort: 'created_at',
  order: 'DESC'
}))
```

### Filter by Action
```javascript
fetch('/api/audit_log/list.php?' + new URLSearchParams({
  action: 'file_uploaded',
  entity_type: 'file'
}))
```

### Combined Filters
```javascript
const params = new URLSearchParams({
  date_from: '2025-10-01 00:00:00',
  date_to: '2025-10-27 23:59:59',
  severity: 'error',
  user_id: 123,
  page: 1,
  per_page: 50
});

fetch(`/api/audit_log/list.php?${params}`)
  .then(res => res.json())
  .then(data => console.log(data));
```

---

## Pagination Example

```javascript
function loadPage(page) {
  fetch(`/api/audit_log/list.php?page=${page}&per_page=50`)
    .then(res => res.json())
    .then(data => {
      const pagination = data.data.pagination;

      console.log(`Page ${pagination.current_page} of ${pagination.total_pages}`);
      console.log(`Total records: ${pagination.total_records}`);

      // Render logs
      renderLogs(data.data.logs);

      // Render pagination controls
      if (pagination.has_prev_page) {
        showPrevButton(() => loadPage(page - 1));
      }
      if (pagination.has_next_page) {
        showNextButton(() => loadPage(page + 1));
      }
    });
}
```

---

## Error Handling

```javascript
fetch('/api/audit_log/list.php?page=1')
  .then(res => {
    if (!res.ok) {
      // Handle HTTP errors
      if (res.status === 401) {
        window.location.href = '/index.php'; // Redirect to login
      } else if (res.status === 403) {
        alert('Access denied');
      }
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }
    return res.json();
  })
  .then(data => {
    if (!data.success) {
      // Handle API errors
      console.error('API Error:', data.error);
      alert(data.error);
      return;
    }
    // Success - process data
    console.log(data.data);
  })
  .catch(error => {
    console.error('Request failed:', error);
    alert('Network error. Please try again.');
  });
```

---

## Database Integration

### Stored Procedures Used

**delete.php** calls:
```sql
CALL record_audit_log_deletion(...)
```

**stats.php** calls:
```sql
SELECT get_deletion_stats(tenant_id)
```

### Tables Accessed

- `audit_logs` - Main audit log table (with soft delete)
- `audit_log_deletions` - Immutable deletion tracking
- `users` - User details for joins
- `tenants` - Tenant details for joins

### Soft Delete Filter

All queries include:
```sql
WHERE deleted_at IS NULL
```

To show only active (not deleted) logs.

---

## Performance Notes

### Pagination
Maximum `per_page` is **200** to prevent performance issues.

### Indexes Used
- `idx_audit_tenant_deleted (tenant_id, deleted_at, created_at)`
- `idx_audit_deleted (deleted_at, created_at)`

### Expected Response Times
- **list.php:** < 100ms for 50 records
- **detail.php:** < 50ms (single row)
- **delete.php:** < 2s for 10,000 logs
- **stats.php:** < 200ms (aggregations)

---

## Testing

### Test Authentication
```bash
# Without session (should return 401)
curl -X GET "http://localhost:8888/CollaboraNexio/api/audit_log/list.php"

# With session (should return 200)
curl -X GET "http://localhost:8888/CollaboraNexio/api/audit_log/list.php" \
  -H "Cookie: PHPSESSID=your-session-id"
```

### Test Pagination
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/audit_log/list.php?page=1&per_page=10" \
  -H "Cookie: PHPSESSID=your-session-id" | jq '.data.pagination'
```

### Test Filtering
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/audit_log/list.php?severity=critical" \
  -H "Cookie: PHPSESSID=your-session-id" | jq '.data.logs | length'
```

---

## Common Issues

### 401 Unauthorized
**Problem:** Not authenticated
**Solution:** Ensure valid session cookie is sent with request

### 403 Forbidden
**Problem:** Insufficient permissions
**Solution:** Check user role (need admin or super_admin)

### 404 Not Found
**Problem:** Log ID doesn't exist or no logs match filters
**Solution:** Verify ID exists or adjust filters

### Empty Results
**Problem:** No logs match criteria
**Solution:** Check date range, filters, tenant isolation

---

## Documentation

- **Full Implementation:** `/AUDIT_LOG_API_IMPLEMENTATION_SUMMARY.md`
- **Database Schema:** `/database/AUDIT_LOG_SCHEMA_DOCUMENTATION.md`
- **Frontend Page:** `/audit_log.php`

---

**Last Updated:** 2025-10-27
**Maintained By:** Backend Engineering Team
