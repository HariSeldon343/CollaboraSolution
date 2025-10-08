# Tenant Locations Migration Guide

## Overview

The CollaboraNexio tenant management system has been updated to properly support unlimited operational locations (sedi operative) for companies using a relational database approach instead of JSON storage.

## What Changed

### Before (JSON Approach)
- Sede legale stored in 5 separate columns in `tenants` table
- Sedi operative stored as JSON in `tenants.sedi_operative` LONGTEXT column
- **Problems:**
  - Cannot query/filter by operational location
  - No referential integrity
  - Cannot index location data
  - Difficult to maintain
  - No proper validation
  - Cannot support location-specific data (manager, phone, etc.)

### After (Relational Approach)
- New `tenant_locations` table with proper structure
- Each location is a separate row with full validation
- Support for unlimited locations
- Proper indexing and foreign keys
- Location-specific contact information
- Manager assignment per location
- Soft-delete support

## Database Schema

### New Table: `tenant_locations`

```sql
CREATE TABLE tenant_locations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT UNSIGNED NOT NULL,
    location_type ENUM('sede_legale', 'sede_operativa') NOT NULL,

    -- Italian address structure
    indirizzo VARCHAR(255) NOT NULL,
    civico VARCHAR(10) NOT NULL,
    cap VARCHAR(5) NOT NULL,
    comune VARCHAR(100) NOT NULL,
    provincia VARCHAR(2) NOT NULL,

    -- Location-specific contact info
    telefono VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    manager_nome VARCHAR(255) NULL,

    -- Location flags
    is_primary BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    note TEXT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uk_tenant_primary_sede_legale (tenant_id, location_type, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## API Changes

### 1. `/api/tenants/create.php` - Create Tenant

**Request Format:**
```json
{
    "denominazione": "Company Name",
    "codice_fiscale": "CMPNY12345678901",
    "partita_iva": "12345678901",
    "sede_legale": {
        "indirizzo": "Via Roma",
        "civico": "123",
        "cap": "20100",
        "comune": "Milano",
        "provincia": "MI",
        "telefono": "+39 02 12345678",
        "email": "sede@company.it"
    },
    "sedi_operative": [
        {
            "indirizzo": "Via Torino",
            "civico": "45",
            "cap": "10121",
            "comune": "Torino",
            "provincia": "TO",
            "telefono": "+39 011 99887766",
            "email": "torino@company.it",
            "manager_nome": "Mario Rossi",
            "note": "Centro R&D"
        }
    ]
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "tenant_id": 123,
        "denominazione": "Company Name",
        "locations_created": 2
    },
    "message": "Azienda creata con successo"
}
```

**Changes:**
- Inserts sede legale into `tenant_locations` table with `is_primary = 1`
- Inserts each sede operativa as separate row in `tenant_locations`
- Maintains backward compatibility with legacy `sede_legale_*` columns in `tenants` table

### 2. `/api/tenants/update.php` - Update Tenant

**Request Format:**
```json
{
    "tenant_id": 123,
    "sede_legale": {
        "indirizzo": "Via Milano",
        "civico": "100",
        "cap": "20100",
        "comune": "Milano",
        "provincia": "MI"
    },
    "sedi_operative": [
        {
            "indirizzo": "Via Roma",
            "civico": "45",
            "cap": "00100",
            "comune": "Roma",
            "provincia": "RM"
        }
    ]
}
```

**Behavior:**
- Soft-deletes existing locations of the same type
- Inserts new locations
- All operations are transactional

### 3. `/api/tenants/list.php` - List Tenants

**Response Format:**
```json
{
    "success": true,
    "data": {
        "tenants": [
            {
                "id": 123,
                "denominazione": "Company Name",
                "sede_comune": "Milano",
                "sede_provincia": "MI",
                "sede_indirizzo": "Via Roma",
                "sede_civico": "123",
                "sede_cap": "20100",
                "sedi_operative_count": 3
            }
        ],
        "total": 1
    }
}
```

**Changes:**
- JOINs with `tenant_locations` to get sede legale data
- Includes subquery to count active sedi operative
- Shows full sede legale address information

### 4. `/api/tenants/get.php` - Get Single Tenant

**Response Format:**
```json
{
    "success": true,
    "data": {
        "id": 123,
        "denominazione": "Company Name",
        "sede_legale": {
            "id": 456,
            "indirizzo": "Via Roma",
            "civico": "123",
            "cap": "20100",
            "comune": "Milano",
            "provincia": "MI",
            "telefono": "+39 02 12345678",
            "email": "sede@company.it",
            "is_primary": true
        },
        "sedi_operative": [
            {
                "id": 457,
                "indirizzo": "Via Torino",
                "civico": "45",
                "cap": "10121",
                "comune": "Torino",
                "provincia": "TO",
                "telefono": "+39 011 99887766",
                "email": "torino@company.it",
                "manager_nome": "Mario Rossi",
                "note": "Centro R&D",
                "is_active": true
            }
        ]
    }
}
```

**Changes:**
- Fetches sede legale from `tenant_locations` table
- Fetches all sedi operative as array
- Returns structured location data with IDs

### 5. `/api/tenants/delete.php` - Delete Tenant

**Response Format:**
```json
{
    "success": true,
    "data": {
        "tenant_id": 123,
        "cascade_info": {
            "users_deleted": 5,
            "files_deleted": 120,
            "projects_deleted": 8,
            "locations_deleted": 4,
            "accesses_removed": 2
        }
    }
}
```

**Changes:**
- Cascades soft-delete to `tenant_locations`
- Reports number of locations deleted in response

## Backward Compatibility

The migration maintains backward compatibility during the transition period:

1. **Legacy columns kept:** `sede_legale_indirizzo`, `sede_legale_civico`, etc. in `tenants` table
2. **Dual write:** Both old columns and new `tenant_locations` table are updated
3. **Deprecation notices:** Added in code comments
4. **Gradual migration:** Old columns can be removed after 2-3 months

## Testing

Run the test script to verify all APIs work correctly:

```bash
php test_tenant_locations_api.php
```

The test script verifies:
- ✓ tenant_locations table exists and has correct structure
- ✓ Create API inserts locations correctly
- ✓ Get API retrieves structured location data
- ✓ Update API modifies locations properly
- ✓ List API shows location counts
- ✓ Delete API cascades to locations

## Migration Steps

### Step 1: Run Database Migration

```bash
# Execute the tenant_locations schema migration
mysql -u root collaboranexio < database/migrations/tenant_locations_schema.sql
```

This will:
- Create `tenant_locations` table
- Migrate existing sede legale data
- Create database views
- Create triggers for data integrity

### Step 2: Verify Migration

Check that existing tenants have their sede legale migrated:

```sql
SELECT
    t.denominazione,
    COUNT(tl.id) as total_locations,
    SUM(CASE WHEN tl.location_type = 'sede_legale' THEN 1 ELSE 0 END) as sede_legale_count
FROM tenants t
LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id AND tl.deleted_at IS NULL
WHERE t.deleted_at IS NULL
GROUP BY t.id, t.denominazione;
```

### Step 3: Test APIs

Run the test script:

```bash
php test_tenant_locations_api.php
```

### Step 4: Update Frontend (if needed)

Frontend should continue to work as APIs maintain the same request/response structure. The main difference is in the response structure for `/api/tenants/get.php` which now returns structured arrays instead of JSON strings.

## Benefits

1. **Scalability:** Unlimited operational locations per tenant
2. **Performance:** Indexed queries on location data
3. **Data Integrity:** Foreign key constraints and validation
4. **Flexibility:** Location-specific contact information and managers
5. **Queryability:** Can filter/search by location comune, provincia, etc.
6. **Maintainability:** Proper relational design instead of JSON parsing

## Database Views

The migration creates helpful views:

### `v_tenants_with_sede_legale`
Shows all tenants with their primary sede legale information.

### `v_tenant_locations_active`
Shows all active locations with tenant information.

### `v_tenant_location_counts`
Shows location counts per tenant.

## Triggers

Automatic triggers maintain data integrity:

1. **trg_tenant_locations_before_insert:** Ensures only one primary sede legale per tenant
2. **trg_tenant_locations_before_update:** Validates data on update
3. **trg_tenant_locations_after_*:** Updates cached location counts

## Performance Considerations

- Indexes added on `tenant_id`, `location_type`, `comune`, `provincia`
- Composite indexes for common query patterns
- Cached location count in `tenants.total_locations`
- Triggers automatically maintain cache

## Security

All APIs maintain existing security measures:
- CSRF token validation
- Role-based access control
- Tenant isolation for multi-tenant users
- Soft-delete support
- Audit logging

## Future Enhancements

Possible future improvements:
- Location-specific user assignments
- Geographic coordinates (lat/lng) for mapping
- Opening hours per location
- Service availability per location
- Location-specific document storage
- Multi-language location names

## Rollback Procedure

If needed, the migration can be rolled back:

```sql
-- See database/migrations/tenant_locations_schema.sql
-- Section: ROLLBACK SCRIPT (Emergency use only)
```

## Support

For issues or questions:
1. Check logs in `/logs/php_errors.log`
2. Run test script: `php test_tenant_locations_api.php`
3. Verify database structure matches schema
4. Check API responses for detailed error messages

---

**Migration Date:** 2025-10-07
**Version:** 1.0.0
**Status:** ✓ Completed
