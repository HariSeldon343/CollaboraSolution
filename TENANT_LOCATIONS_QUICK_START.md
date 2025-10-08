# Tenant Locations - Quick Start Guide

## Overview

This guide provides a quick reference for implementing the new tenant locations system that replaces the problematic JSON-based approach with proper relational database design.

---

## Installation (3 Steps)

### Step 1: Run Database Migration

```bash
mysql -u root collaboranexio < /mnt/c/xampp/htdocs/CollaboraNexio/database/migrations/tenant_locations_schema.sql
```

**What it does:**
- Creates `tenant_locations` table
- Migrates existing sede_legale data
- Creates 3 helper views
- Creates 5 triggers for data integrity
- Inserts demo data (2 companies with multiple locations)

### Step 2: Migrate JSON Data (If Applicable)

```bash
# Dry run first (safe)
php /mnt/c/xampp/htdocs/CollaboraNexio/migrate_tenant_locations.php

# If satisfied with dry run, edit file and set $DRY_RUN = false, then:
php /mnt/c/xampp/htdocs/CollaboraNexio/migrate_tenant_locations.php
```

### Step 3: Test Installation

```bash
mysql -u root collaboranexio -e "SELECT * FROM v_tenant_location_counts"
```

Expected output: List of tenants with their location counts.

---

## Database Schema

### Table: tenant_locations

```
id                  INT UNSIGNED (PK)
tenant_id           INT UNSIGNED (FK → tenants.id)
location_type       ENUM('sede_legale', 'sede_operativa')
indirizzo           VARCHAR(255)
civico              VARCHAR(10)
cap                 VARCHAR(5)
comune              VARCHAR(100)
provincia           VARCHAR(2)
telefono            VARCHAR(50)
email               VARCHAR(255)
manager_nome        VARCHAR(255)
manager_user_id     INT UNSIGNED (FK → users.id)
is_primary          BOOLEAN
is_active           BOOLEAN
note                TEXT
created_at          TIMESTAMP
updated_at          TIMESTAMP
deleted_at          TIMESTAMP (soft delete)
```

---

## API Endpoints

### List Locations
```http
GET /api/tenants/5/locations
Authorization: Bearer {token}
```

### Create Location
```http
POST /api/tenants/5/locations
Authorization: Bearer {token}
X-CSRF-Token: {token}
Content-Type: application/json

{
  "location_type": "sede_operativa",
  "indirizzo": "Via Napoli",
  "civico": "23",
  "cap": "80100",
  "comune": "Napoli",
  "provincia": "NA",
  "telefono": "+39 081 55443322",
  "email": "napoli@example.it",
  "manager_nome": "Mario Rossi",
  "note": "Hub sud Italia"
}
```

### Update Location
```http
PUT /api/tenants/5/locations/12
Authorization: Bearer {token}
X-CSRF-Token: {token}
Content-Type: application/json

{
  "telefono": "+39 081 99887766",
  "is_active": true
}
```

### Delete Location
```http
DELETE /api/tenants/5/locations/12
Authorization: Bearer {token}
X-CSRF-Token: {token}
```

---

## Query Examples

### Get all locations for tenant
```sql
SELECT *
FROM tenant_locations
WHERE tenant_id = 1
  AND deleted_at IS NULL
ORDER BY
  CASE location_type WHEN 'sede_legale' THEN 0 ELSE 1 END,
  created_at;
```

### Find tenants in Milano
```sql
SELECT t.denominazione, tl.indirizzo, tl.civico
FROM tenants t
INNER JOIN tenant_locations tl ON t.id = tl.tenant_id
WHERE tl.comune = 'Milano'
  AND tl.deleted_at IS NULL;
```

### Count locations per tenant
```sql
SELECT * FROM v_tenant_location_counts
WHERE sedi_operative_count > 0;
```

---

## PHP Database Operations

### Insert Location
```php
$db = Database::getInstance();

$locationId = $db->insert('tenant_locations', [
    'tenant_id' => 1,
    'location_type' => 'sede_operativa',
    'indirizzo' => 'Via Roma',
    'civico' => '100',
    'cap' => '20100',
    'comune' => 'Milano',
    'provincia' => 'MI',
    'is_primary' => false,
    'is_active' => true
]);
```

### Update Location
```php
$db->update('tenant_locations', [
    'telefono' => '+39 02 12345678',
    'email' => 'milano@example.it'
], [
    'id' => 5
]);
```

### Fetch Locations
```php
$locations = $db->fetchAll(
    'SELECT * FROM tenant_locations
     WHERE tenant_id = ?
     AND deleted_at IS NULL',
    [1]
);
```

### Soft Delete
```php
$db->update('tenant_locations', [
    'deleted_at' => date('Y-m-d H:i:s')
], [
    'id' => 5
]);
```

---

## Helper Views

### v_tenants_with_sede_legale
Get tenants with their primary sede legale:
```sql
SELECT * FROM v_tenants_with_sede_legale WHERE tenant_id = 1;
```

### v_tenant_locations_active
Get all active locations:
```sql
SELECT * FROM v_tenant_locations_active WHERE comune = 'Milano';
```

### v_tenant_location_counts
Get location statistics:
```sql
SELECT * FROM v_tenant_location_counts;
```

---

## Frontend Integration

### JavaScript - Load Locations
```javascript
async function loadTenantLocations(tenantId) {
    const response = await fetch(`/api/tenants/${tenantId}/locations`, {
        headers: {
            'Authorization': `Bearer ${token}`
        }
    });
    const data = await response.json();

    // data.data.sede_legale
    // data.data.sedi_operative (array)
    // data.data.total_locations
}
```

### JavaScript - Add Location
```javascript
async function addLocation(tenantId, locationData) {
    const response = await fetch(`/api/tenants/${tenantId}/locations`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`,
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify(locationData)
    });
    return response.json();
}
```

### JavaScript - Delete Location
```javascript
async function deleteLocation(tenantId, locationId) {
    const response = await fetch(`/api/tenants/${tenantId}/locations/${locationId}`, {
        method: 'DELETE',
        headers: {
            'Authorization': `Bearer ${token}`,
            'X-CSRF-Token': csrfToken
        }
    });
    return response.json();
}
```

---

## Data Validation Rules

### Required Fields
- `tenant_id` - Must exist in tenants table
- `location_type` - Must be 'sede_legale' or 'sede_operativa'
- `indirizzo` - Cannot be empty
- `civico` - Cannot be empty
- `cap` - Must be exactly 5 digits
- `comune` - Cannot be empty
- `provincia` - Must be exactly 2 uppercase letters

### Optional Fields
- `telefono` - Italian phone format recommended
- `email` - Must be valid email format
- `manager_nome` - Free text
- `manager_user_id` - Must exist in users table with manager role
- `note` - Free text

### Business Rules
- Each tenant must have exactly ONE primary sede_legale
- Cannot delete primary sede_legale
- Unlimited sedi operative per tenant
- Provincia automatically converted to uppercase
- Soft delete preserves audit trail

---

## Troubleshooting

### Problem: Migration fails with foreign key error
**Solution**: Ensure tenants table exists and has proper primary key

### Problem: Cannot insert location with CAP
**Solution**: CAP must be exactly 5 digits (e.g., "20100" not "20100 ")

### Problem: Cannot set is_primary on sede_operativa
**Solution**: Only sede_legale can be marked as primary

### Problem: Cannot delete location
**Solution**: Cannot delete primary sede_legale. Set another sede_legale as primary first.

---

## Performance Tips

1. **Use indexes**: All common queries use existing indexes
2. **Use views**: Pre-joined views for common patterns
3. **Cache counts**: `tenants.total_locations` is cached and auto-updated by triggers
4. **Soft delete**: Use `deleted_at IS NULL` in WHERE clauses
5. **Batch operations**: Use transactions for multiple inserts

---

## Files Reference

### SQL Migration
`/mnt/c/xampp/htdocs/CollaboraNexio/database/migrations/tenant_locations_schema.sql`

### PHP Migration Helper
`/mnt/c/xampp/htdocs/CollaboraNexio/migrate_tenant_locations.php`

### API Endpoints
```
/api/tenants/locations/list.php
/api/tenants/locations/create.php
/api/tenants/locations/update.php
/api/tenants/locations/delete.php
```

### Documentation
```
TENANT_LOCATIONS_REDESIGN.md (Complete guide)
TENANT_LOCATIONS_QUICK_START.md (This file)
```

---

## Support

For detailed documentation, see:
- `/mnt/c/xampp/htdocs/CollaboraNexio/TENANT_LOCATIONS_REDESIGN.md`

For issues or questions, contact the Database Architect team.

---

**Version**: 1.0
**Last Updated**: 2025-10-07
**Status**: Production Ready
