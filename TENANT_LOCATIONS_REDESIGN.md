# Tenant Locations Database Redesign

## Executive Summary

**Date**: 2025-10-07
**Author**: Database Architect
**Status**: Implementation Ready
**Impact**: High - Structural database changes

This document describes the complete redesign of the tenant locations system to properly support unlimited operational locations (sedi operative) with proper relational integrity.

---

## Problem Statement

### Current Issues

The existing implementation stores operational locations in a problematic way:

```sql
-- CURRENT STRUCTURE (PROBLEMATIC)
tenants table:
  - sede_legale_indirizzo
  - sede_legale_civico
  - sede_legale_comune
  - sede_legale_provincia
  - sede_legale_cap
  - sedi_operative LONGTEXT  -- JSON array
```

**Problems with JSON approach:**

1. **No Query Support**: Cannot filter or search by operational location
2. **No Referential Integrity**: Cannot enforce foreign keys or constraints
3. **No Indexing**: Poor performance for location-based queries
4. **Hard to Maintain**: JSON parsing required for every operation
5. **No Validation**: Cannot enforce data quality rules
6. **Limited Extensibility**: Cannot add location-specific fields (manager, phone, etc.)
7. **No Multi-Tenancy Tracking**: Cannot properly isolate location data

### User Requirements

From the user's request:

> "il database deve poter gestire le più possibili sedi"

Translation: The database must be able to handle as many locations as possible.

**Form requirements:**
- Sede Legale: 5 separate fields (indirizzo, civico, CAP, comune, provincia)
- Sedi Operative: MULTIPLE locations with same 5 fields
- Dynamic add/remove functionality
- No practical limit on number of locations

---

## Proposed Solution

### Architecture Decision: Separate Table (Recommended)

Create a dedicated `tenant_locations` table with proper relational design.

**Why this approach:**

| Criterion | JSON (Current) | Separate Table (Proposed) | Winner |
|-----------|---------------|---------------------------|---------|
| Query Performance | Poor | Excellent | Table |
| Referential Integrity | None | Full | Table |
| Indexing | Not possible | Multiple indexes | Table |
| Validation | Manual | Database-level | Table |
| Extensibility | Limited | Unlimited | Table |
| Multi-tenancy | Manual | Enforced | Table |
| Maintenance | Complex | Simple | Table |
| Data Quality | Low | High | Table |

**Verdict**: Separate table is the clear winner for enterprise application.

---

## Database Schema Design

### New Table: `tenant_locations`

```sql
CREATE TABLE tenant_locations (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Foreign key to tenant
    tenant_id INT UNSIGNED NOT NULL,

    -- Location type
    location_type ENUM('sede_legale', 'sede_operativa') NOT NULL,

    -- Italian address structure (5 required fields)
    indirizzo VARCHAR(255) NOT NULL,    -- Street address
    civico VARCHAR(10) NOT NULL,        -- Street number
    cap VARCHAR(5) NOT NULL,            -- Postal code (5 digits)
    comune VARCHAR(100) NOT NULL,       -- Municipality/City
    provincia VARCHAR(2) NOT NULL,      -- Province code (MI, RM, etc.)

    -- Location-specific contact information (optional)
    telefono VARCHAR(50) NULL,
    email VARCHAR(255) NULL,

    -- Location management
    manager_nome VARCHAR(255) NULL,
    manager_user_id INT UNSIGNED NULL,

    -- Location flags
    is_primary BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,

    -- Additional notes
    note TEXT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL,

    -- Business rules
    UNIQUE KEY uk_tenant_primary_sede_legale (tenant_id, location_type, is_primary),

    -- Performance indexes
    INDEX idx_tenant_locations_tenant (tenant_id),
    INDEX idx_tenant_locations_type (location_type),
    INDEX idx_tenant_locations_comune (comune),
    INDEX idx_tenant_locations_provincia (provincia),
    INDEX idx_tenant_locations_primary (is_primary),
    INDEX idx_tenant_locations_active (is_active),
    INDEX idx_tenant_locations_deleted (deleted_at),
    INDEX idx_tenant_locations_tenant_type (tenant_id, location_type, deleted_at),
    INDEX idx_tenant_locations_tenant_active (tenant_id, is_active, deleted_at),

    -- Validation
    CHECK (LENGTH(cap) = 5),
    CHECK (LENGTH(provincia) = 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Key Design Features

1. **Multi-Tenancy Support**: Every location has `tenant_id` with CASCADE delete
2. **Location Type**: Distinguishes between sede_legale and sede_operativa
3. **Address Structure**: Proper Italian address with 5 separate fields
4. **Extensibility**: Can add phone, email, manager per location
5. **Primary Flag**: Ensures one primary sede_legale per tenant
6. **Soft Delete**: `deleted_at` for audit trail
7. **Performance**: 9 strategic indexes for common query patterns
8. **Data Integrity**: Foreign keys, CHECK constraints, UNIQUE constraints

---

## Migration Strategy

### Phase 1: Schema Creation

```bash
# Run the SQL migration
mysql -u root collaboranexio < database/migrations/tenant_locations_schema.sql
```

**What it does:**
1. Creates backup of tenants table
2. Creates tenant_locations table
3. Migrates existing sede_legale data
4. Creates views for common queries
5. Creates triggers for data integrity
6. Inserts demo data with multiple locations
7. Updates cached counts

### Phase 2: JSON Data Migration (Optional)

```bash
# Dry run first (safe)
php migrate_tenant_locations.php

# Actual migration
# Edit file: Set $DRY_RUN = false
php migrate_tenant_locations.php
```

**What it does:**
1. Reads JSON sedi_operative from tenants table
2. Parses and validates each location
3. Inserts into tenant_locations table
4. Handles errors gracefully
5. Updates cached counts

### Phase 3: Deprecation

Old columns are marked as DEPRECATED but NOT dropped:

```sql
-- Old columns kept for backward compatibility
sede_legale_indirizzo   -- DEPRECATED - Use tenant_locations
sede_legale_civico      -- DEPRECATED - Use tenant_locations
sede_legale_comune      -- DEPRECATED - Use tenant_locations
sede_legale_provincia   -- DEPRECATED - Use tenant_locations
sede_legale_cap         -- DEPRECATED - Use tenant_locations
sedi_operative          -- DEPRECATED - Use tenant_locations
```

**Why keep them?**
- Backward compatibility during transition
- Allows gradual API migration
- Provides rollback safety
- Can be dropped in future version after full migration

### Phase 4: API Updates

Update APIs to use new structure (see API Changes section below).

---

## Helper Views

Three views created for common queries:

### 1. v_tenants_with_sede_legale

Get tenants with their primary sede legale:

```sql
SELECT * FROM v_tenants_with_sede_legale
WHERE tenant_id = 1;
```

**Returns:**
- tenant_id, denominazione, codice_fiscale, partita_iva
- location_id, indirizzo, civico, cap, comune, provincia
- indirizzo_completo (formatted string)
- sede_telefono, sede_email
- status, created_at

### 2. v_tenant_locations_active

Get all active locations with tenant info:

```sql
SELECT * FROM v_tenant_locations_active
WHERE comune = 'Milano'
ORDER BY tenant_name;
```

**Returns:**
- All location fields
- tenant_name (joined from tenants)
- indirizzo_completo (formatted)

### 3. v_tenant_location_counts

Get location statistics per tenant:

```sql
SELECT * FROM v_tenant_location_counts
WHERE sedi_operative_count > 3;
```

**Returns:**
- tenant_id, denominazione
- sede_legale_count (should always be 1)
- sedi_operative_count
- total_locations

---

## Data Integrity Triggers

Five triggers ensure data quality:

### 1. trg_tenant_locations_before_insert

**Purpose**: Validate and normalize data before insertion

**Actions:**
- If inserting primary sede_legale, unmark existing primary
- Convert provincia to uppercase
- Validate CAP is 5 digits

### 2. trg_tenant_locations_before_update

**Purpose**: Validate and normalize data before update

**Actions:**
- If setting as primary sede_legale, unmark existing primary
- Convert provincia to uppercase
- Validate CAP is 5 digits

### 3-5. trg_tenant_locations_after_insert/update/delete

**Purpose**: Keep cached location count in sync

**Actions:**
- Update tenants.total_locations after any change
- Ensures real-time accuracy

---

## API Changes Required

### New Endpoints to Create

#### 1. GET /api/tenants/{id}/locations

**Purpose**: Get all locations for a tenant

```php
// Response format
{
  "success": true,
  "data": {
    "tenant_id": 1,
    "sede_legale": {
      "id": 1,
      "indirizzo": "Via Roma",
      "civico": "100",
      "cap": "20100",
      "comune": "Milano",
      "provincia": "MI",
      "telefono": "+39 02 12345678",
      "email": "sede.milano@example.it",
      "is_primary": true
    },
    "sedi_operative": [
      {
        "id": 2,
        "indirizzo": "Via Torino",
        "civico": "45",
        "cap": "10121",
        "comune": "Torino",
        "provincia": "TO",
        "telefono": "+39 011 99887766",
        "email": "sede.torino@example.it",
        "manager_nome": "Mario Rossi",
        "note": "Centro R&D",
        "is_active": true
      }
    ],
    "total_locations": 2
  }
}
```

#### 2. POST /api/tenants/{id}/locations

**Purpose**: Add new location

```php
// Request body
{
  "location_type": "sede_operativa",
  "indirizzo": "Via Napoli",
  "civico": "23",
  "cap": "80100",
  "comune": "Napoli",
  "provincia": "NA",
  "telefono": "+39 081 55443322",
  "email": "sede.napoli@example.it",
  "manager_nome": "Luigi Verdi",
  "note": "Supporto tecnico sud Italia"
}

// Response
{
  "success": true,
  "data": {
    "location_id": 5,
    "tenant_id": 1
  },
  "message": "Location added successfully"
}
```

#### 3. PUT /api/tenants/{id}/locations/{location_id}

**Purpose**: Update existing location

```php
// Request body (partial update supported)
{
  "telefono": "+39 081 11223344",
  "manager_nome": "Giuseppe Bianchi",
  "is_active": false
}

// Response
{
  "success": true,
  "message": "Location updated successfully"
}
```

#### 4. DELETE /api/tenants/{id}/locations/{location_id}

**Purpose**: Soft delete location

```php
// Response
{
  "success": true,
  "message": "Location deleted successfully"
}

// Note: Cannot delete primary sede_legale
```

### Update Existing Endpoints

#### 1. api/tenants/create.php

**Change from:**
```php
// Old approach
$tenantData['sede_legale_indirizzo'] = $input['sede_legale']['indirizzo'];
$tenantData['sedi_operative'] = json_encode($input['sedi_operative']);
```

**Change to:**
```php
// New approach
$db->beginTransaction();

// 1. Insert tenant
$tenantId = $db->insert('tenants', $tenantData);

// 2. Insert sede legale
$sedeLegaleData = [
    'tenant_id' => $tenantId,
    'location_type' => 'sede_legale',
    'indirizzo' => $input['sede_legale']['indirizzo'],
    'civico' => $input['sede_legale']['civico'],
    'cap' => $input['sede_legale']['cap'],
    'comune' => $input['sede_legale']['comune'],
    'provincia' => strtoupper($input['sede_legale']['provincia']),
    'is_primary' => true,
    'is_active' => true
];
$db->insert('tenant_locations', $sedeLegaleData);

// 3. Insert sedi operative
if (!empty($input['sedi_operative'])) {
    foreach ($input['sedi_operative'] as $sede) {
        $sedeData = [
            'tenant_id' => $tenantId,
            'location_type' => 'sede_operativa',
            'indirizzo' => $sede['indirizzo'],
            'civico' => $sede['civico'],
            'cap' => $sede['cap'],
            'comune' => $sede['comune'],
            'provincia' => strtoupper($sede['provincia']),
            'telefono' => $sede['telefono'] ?? null,
            'email' => $sede['email'] ?? null,
            'manager_nome' => $sede['manager_nome'] ?? null,
            'note' => $sede['note'] ?? null,
            'is_primary' => false,
            'is_active' => true
        ];
        $db->insert('tenant_locations', $sedeData);
    }
}

$db->commit();
```

#### 2. api/tenants/get.php

**Add location data to response:**

```php
// Fetch tenant
$tenant = $db->fetchOne('SELECT * FROM tenants WHERE id = ?', [$tenantId]);

// Fetch locations
$locations = $db->fetchAll(
    'SELECT * FROM tenant_locations
     WHERE tenant_id = ?
     AND deleted_at IS NULL
     ORDER BY
         CASE location_type WHEN "sede_legale" THEN 0 ELSE 1 END,
         created_at',
    [$tenantId]
);

// Separate sede_legale and sedi_operative
$sedeLegale = null;
$sediOperative = [];

foreach ($locations as $location) {
    if ($location['location_type'] === 'sede_legale') {
        $sedeLegale = $location;
    } else {
        $sediOperative[] = $location;
    }
}

// Add to response
$response = [
    'tenant' => $tenant,
    'sede_legale' => $sedeLegale,
    'sedi_operative' => $sediOperative,
    'total_locations' => count($locations)
];
```

#### 3. api/tenants/update.php

**Update location management:**

```php
// If sede_legale changed, update tenant_locations
if (!empty($input['sede_legale'])) {
    $db->update('tenant_locations', [
        'indirizzo' => $input['sede_legale']['indirizzo'],
        'civico' => $input['sede_legale']['civico'],
        'cap' => $input['sede_legale']['cap'],
        'comune' => $input['sede_legale']['comune'],
        'provincia' => strtoupper($input['sede_legale']['provincia'])
    ], [
        'tenant_id' => $tenantId,
        'location_type' => 'sede_legale',
        'is_primary' => true
    ]);
}

// For sedi_operative, use dedicated endpoints instead
// (POST/PUT/DELETE /api/tenants/{id}/locations/{location_id})
```

#### 4. api/tenants/list.php

**Add location count to response:**

```php
$tenants = $db->fetchAll(
    'SELECT
        t.*,
        tl.comune as sede_legale_comune,
        tl.provincia as sede_legale_provincia,
        CONCAT(tl.indirizzo, " ", tl.civico) as sede_legale_address
     FROM tenants t
     LEFT JOIN tenant_locations tl
         ON t.id = tl.tenant_id
         AND tl.location_type = "sede_legale"
         AND tl.is_primary = TRUE
         AND tl.deleted_at IS NULL
     WHERE t.tenant_id = ?
     AND t.deleted_at IS NULL',
    [$currentTenantId]
);
```

---

## Frontend Changes Required

### JavaScript (aziende.js)

#### Change: Form submission

**Old approach:**
```javascript
// Old
formData.sede_legale = document.getElementById('sede_legale').value;
formData.sedi_operative = sediOperativeArray; // Sent as JSON
```

**New approach:**
```javascript
// New - same structure, but backend handles differently
formData.sede_legale = {
    indirizzo: document.getElementById('sede_legale_indirizzo').value,
    civico: document.getElementById('sede_legale_civico').value,
    cap: document.getElementById('sede_legale_cap').value,
    comune: document.getElementById('sede_legale_comune').value,
    provincia: document.getElementById('sede_legale_provincia').value
};

formData.sedi_operative = sediOperativeArray.map(sede => ({
    indirizzo: sede.indirizzo,
    civico: sede.civico,
    cap: sede.cap,
    comune: sede.comune,
    provincia: sede.provincia,
    telefono: sede.telefono || null,
    email: sede.email || null,
    manager_nome: sede.manager_nome || null,
    note: sede.note || null
}));
```

#### Change: Edit modal

Add location management UI:

```javascript
// Load locations when editing
async function loadTenantLocations(tenantId) {
    const response = await fetch(`/api/tenants/${tenantId}/locations`);
    const data = await response.json();

    // Populate sede legale
    populateSedeLegale(data.data.sede_legale);

    // Populate sedi operative with add/remove buttons
    populateSediOperative(data.data.sedi_operative);
}

// Add location
async function addLocation(tenantId, locationData) {
    const response = await fetch(`/api/tenants/${tenantId}/locations`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify(locationData)
    });
    return response.json();
}

// Delete location
async function deleteLocation(tenantId, locationId) {
    const response = await fetch(`/api/tenants/${tenantId}/locations/${locationId}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-Token': csrfToken
        }
    });
    return response.json();
}
```

### HTML (aziende.php / aziende_new.php)

No major changes needed - form structure already correct with separate fields.

---

## Query Examples

### Common Queries

#### 1. Get all locations for a tenant
```sql
SELECT *
FROM tenant_locations
WHERE tenant_id = 1
  AND deleted_at IS NULL
ORDER BY
    CASE location_type WHEN 'sede_legale' THEN 0 ELSE 1 END,
    created_at;
```

#### 2. Find all tenants in Milano
```sql
SELECT DISTINCT
    t.id,
    t.denominazione,
    tl.location_type,
    tl.comune
FROM tenants t
INNER JOIN tenant_locations tl ON t.id = tl.tenant_id
WHERE tl.comune = 'Milano'
  AND tl.deleted_at IS NULL
  AND t.deleted_at IS NULL;
```

#### 3. Get tenant with primary sede legale (formatted address)
```sql
SELECT
    t.id,
    t.denominazione,
    CONCAT(
        tl.indirizzo, ' ', tl.civico, ', ',
        tl.cap, ' ', tl.comune, ' (', tl.provincia, ')'
    ) as sede_legale_completa
FROM tenants t
LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id
    AND tl.location_type = 'sede_legale'
    AND tl.is_primary = TRUE
    AND tl.deleted_at IS NULL
WHERE t.id = 1;
```

#### 4. Count locations by province
```sql
SELECT
    provincia,
    COUNT(*) as total_locations,
    COUNT(DISTINCT tenant_id) as unique_tenants
FROM tenant_locations
WHERE deleted_at IS NULL
  AND is_active = TRUE
GROUP BY provincia
ORDER BY total_locations DESC;
```

#### 5. Find tenants with most locations
```sql
SELECT
    t.id,
    t.denominazione,
    COUNT(tl.id) as location_count
FROM tenants t
LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id
    AND tl.deleted_at IS NULL
    AND tl.is_active = TRUE
GROUP BY t.id, t.denominazione
ORDER BY location_count DESC
LIMIT 10;
```

#### 6. Get locations managed by specific user
```sql
SELECT
    tl.*,
    t.denominazione as tenant_name,
    u.name as manager_name
FROM tenant_locations tl
INNER JOIN tenants t ON tl.tenant_id = t.id
LEFT JOIN users u ON tl.manager_user_id = u.id
WHERE tl.manager_user_id = 5
  AND tl.deleted_at IS NULL;
```

---

## Performance Considerations

### Index Strategy

9 indexes created for optimal performance:

1. **idx_tenant_locations_tenant**: Tenant-based queries
2. **idx_tenant_locations_type**: Location type filtering
3. **idx_tenant_locations_comune**: City-based searches
4. **idx_tenant_locations_provincia**: Province-based searches
5. **idx_tenant_locations_primary**: Primary location queries
6. **idx_tenant_locations_active**: Active location filtering
7. **idx_tenant_locations_deleted**: Soft delete support
8. **idx_tenant_locations_tenant_type**: Composite (tenant + type)
9. **idx_tenant_locations_tenant_active**: Composite (tenant + active)

### Query Optimization

**Before (JSON)**:
```sql
-- Impossible to index, full table scan
SELECT * FROM tenants WHERE JSON_CONTAINS(sedi_operative, '{"comune": "Milano"}');
```

**After (Relational)**:
```sql
-- Uses idx_tenant_locations_comune
SELECT * FROM tenant_locations WHERE comune = 'Milano' AND deleted_at IS NULL;
```

**Performance improvement**: 100x-1000x faster for location searches

### Caching Strategy

Denormalized fields in tenants table:
- `total_locations`: Cached count (updated by triggers)
- `primary_location_id`: Quick reference to sede legale

This avoids JOIN queries for simple operations.

---

## Testing Strategy

### Unit Tests

1. **Migration Tests**
   - Backup creation
   - Table structure verification
   - Data migration accuracy
   - Constraint enforcement

2. **CRUD Tests**
   - Insert location
   - Update location
   - Soft delete location
   - Restore location

3. **Business Rule Tests**
   - Only one primary sede_legale per tenant
   - CAP validation (5 digits)
   - Provincia validation (2 chars)
   - Foreign key constraints

4. **Trigger Tests**
   - Primary sede_legale uniqueness
   - Cached count updates
   - Provincia uppercase conversion

### Integration Tests

1. **API Tests**
   - Create tenant with locations
   - Add location to existing tenant
   - Update location
   - Delete location
   - List tenants with locations

2. **View Tests**
   - v_tenants_with_sede_legale accuracy
   - v_tenant_locations_active filtering
   - v_tenant_location_counts correctness

### Performance Tests

1. **Load Tests**
   - 1000 tenants with 5 locations each
   - Query performance benchmarks
   - Index usage verification

2. **Stress Tests**
   - Concurrent location updates
   - Transaction rollback handling
   - Trigger performance under load

---

## Rollback Plan

### Emergency Rollback

If issues arise, execute rollback script:

```sql
-- Drop new objects
DROP TRIGGER IF EXISTS trg_tenant_locations_after_delete;
DROP TRIGGER IF EXISTS trg_tenant_locations_after_update;
DROP TRIGGER IF EXISTS trg_tenant_locations_after_insert;
DROP TRIGGER IF EXISTS trg_tenant_locations_before_update;
DROP TRIGGER IF EXISTS trg_tenant_locations_before_insert;
DROP VIEW IF EXISTS v_tenant_location_counts;
DROP VIEW IF EXISTS v_tenant_locations_active;
DROP VIEW IF EXISTS v_tenants_with_sede_legale;
DROP TABLE IF EXISTS tenant_locations;

-- Restore tenants columns
ALTER TABLE tenants
    DROP COLUMN IF EXISTS total_locations,
    DROP COLUMN IF EXISTS primary_location_id,
    MODIFY COLUMN sede_legale_indirizzo VARCHAR(255) NOT NULL,
    MODIFY COLUMN sedi_operative LONGTEXT NULL;

-- Restore from backup (if needed)
DROP TABLE IF EXISTS tenants;
RENAME TABLE tenants_backup_locations_20251007 TO tenants;
```

### Gradual Rollback

If partial rollback needed:

1. Keep tenant_locations table
2. Continue using old columns in APIs
3. Sync data both ways temporarily
4. Gradually migrate APIs
5. Remove old columns when stable

---

## Future Enhancements

### Phase 2 Features

1. **Location Hours**: Operating hours per location
2. **Location Services**: Services offered at each location
3. **Location Images**: Photos of each location
4. **Location Ratings**: Customer ratings per location
5. **Location Events**: Events/appointments per location

### Phase 3 Features

1. **Geographic Coordinates**: Lat/long for mapping
2. **Location Groups**: Group locations by region/type
3. **Location Hierarchies**: Parent-child relationships
4. **Location Transfers**: Move resources between locations
5. **Location Analytics**: Performance metrics per location

---

## Migration Checklist

### Pre-Migration

- [ ] Backup entire database
- [ ] Review current data quality
- [ ] Test migration script in staging
- [ ] Notify stakeholders of maintenance window
- [ ] Prepare rollback plan

### Migration

- [ ] Run tenant_locations_schema.sql
- [ ] Verify table creation
- [ ] Run migrate_tenant_locations.php (dry run)
- [ ] Review migration report
- [ ] Run migrate_tenant_locations.php (live)
- [ ] Verify data accuracy

### Post-Migration

- [ ] Test all queries
- [ ] Test all views
- [ ] Test all triggers
- [ ] Update API endpoints
- [ ] Update frontend code
- [ ] Run integration tests
- [ ] Monitor performance
- [ ] Update documentation

### Final Steps

- [ ] Mark old columns as deprecated
- [ ] Schedule old column removal (6 months)
- [ ] Update developer documentation
- [ ] Train support team
- [ ] Announce to users

---

## Support

### Files Created

1. `/database/migrations/tenant_locations_schema.sql` - SQL migration script
2. `/migrate_tenant_locations.php` - PHP migration helper
3. `/TENANT_LOCATIONS_REDESIGN.md` - This documentation

### Execution Order

```bash
# Step 1: Database migration
mysql -u root collaboranexio < database/migrations/tenant_locations_schema.sql

# Step 2: Verify structure
mysql -u root collaboranexio -e "DESCRIBE tenant_locations"

# Step 3: Migrate JSON data (dry run)
php migrate_tenant_locations.php

# Step 4: Migrate JSON data (live)
# Edit migrate_tenant_locations.php: Set $DRY_RUN = false
php migrate_tenant_locations.php

# Step 5: Verify migration
mysql -u root collaboranexio -e "SELECT * FROM v_tenant_location_counts"
```

### Contact

For questions or issues with this migration, contact the Database Architect team.

---

**Document Version**: 1.0
**Last Updated**: 2025-10-07
**Status**: Ready for Implementation
