# Tenant Locations - Quick Reference Guide

## For Developers: Working with Tenant Locations

### Database Structure

```
tenant_locations
├── id (INT UNSIGNED, PRIMARY KEY)
├── tenant_id (INT UNSIGNED, FK to tenants.id)
├── location_type (ENUM: 'sede_legale', 'sede_operativa')
├── indirizzo (VARCHAR 255, required)
├── civico (VARCHAR 10, required)
├── cap (VARCHAR 5, required, must be 5 digits)
├── comune (VARCHAR 100, required)
├── provincia (VARCHAR 2, required, must be 2 uppercase chars)
├── telefono (VARCHAR 50, optional)
├── email (VARCHAR 255, optional)
├── manager_nome (VARCHAR 255, optional)
├── is_primary (BOOLEAN, default FALSE)
├── is_active (BOOLEAN, default TRUE)
├── note (TEXT, optional)
├── created_at (TIMESTAMP)
├── updated_at (TIMESTAMP)
└── deleted_at (TIMESTAMP, for soft-delete)
```

### Business Rules

1. **Each tenant MUST have exactly ONE sede legale** with `is_primary = 1`
2. **Unlimited sedi operative allowed** (recommended max: 5 for UX)
3. **CAP must be exactly 5 digits** (validated by trigger)
4. **Provincia must be 2 uppercase characters** (auto-converted by trigger)
5. **Soft-delete is used** - always check `deleted_at IS NULL`

### Common Queries

#### Get Sede Legale for a Tenant

```php
$sedeLegale = $db->fetchOne(
    'SELECT * FROM tenant_locations
     WHERE tenant_id = ?
       AND location_type = "sede_legale"
       AND deleted_at IS NULL
     LIMIT 1',
    [$tenantId]
);
```

#### Get All Sedi Operative for a Tenant

```php
$sediOperative = $db->fetchAll(
    'SELECT * FROM tenant_locations
     WHERE tenant_id = ?
       AND location_type = "sede_operativa"
       AND deleted_at IS NULL
     ORDER BY created_at ASC',
    [$tenantId]
);
```

#### Count Locations

```php
$count = $db->count('tenant_locations', [
    'tenant_id' => $tenantId,
    'location_type' => 'sede_operativa',
    'deleted_at' => null
]);
```

#### Insert New Sede Operativa

```php
$locationId = $db->insert('tenant_locations', [
    'tenant_id' => $tenantId,
    'location_type' => 'sede_operativa',
    'indirizzo' => 'Via Roma',
    'civico' => '123',
    'cap' => '20100',
    'comune' => 'Milano',
    'provincia' => 'MI', // Will be auto-uppercased
    'telefono' => '+39 02 12345678',
    'email' => 'milano@company.it',
    'manager_nome' => 'Mario Rossi',
    'note' => 'Ufficio commerciale',
    'is_primary' => 0,
    'is_active' => 1
]);
```

#### Update Location (Replace Pattern)

```php
$db->beginTransaction();

try {
    // Soft-delete existing locations
    $db->update(
        'tenant_locations',
        ['deleted_at' => date('Y-m-d H:i:s')],
        [
            'tenant_id' => $tenantId,
            'location_type' => 'sede_operativa'
        ]
    );

    // Insert new locations
    foreach ($newSediOperative as $sede) {
        $db->insert('tenant_locations', [
            'tenant_id' => $tenantId,
            'location_type' => 'sede_operativa',
            'indirizzo' => $sede['indirizzo'],
            'civico' => $sede['civico'],
            'cap' => $sede['cap'],
            'comune' => $sede['comune'],
            'provincia' => $sede['provincia'],
            'is_primary' => 0,
            'is_active' => 1
        ]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}
```

#### Soft-Delete Locations (Cascade on Tenant Delete)

```php
$db->update(
    'tenant_locations',
    ['deleted_at' => date('Y-m-d H:i:s')],
    ['tenant_id' => $tenantId]
);
```

### Validation Rules

```php
// Validate sede legale/operativa
function validateLocation(array $location): array {
    $errors = [];

    if (empty($location['indirizzo'])) {
        $errors[] = 'Indirizzo obbligatorio';
    }

    if (empty($location['civico'])) {
        $errors[] = 'Civico obbligatorio';
    }

    if (empty($location['comune'])) {
        $errors[] = 'Comune obbligatorio';
    }

    if (empty($location['provincia'])) {
        $errors[] = 'Provincia obbligatoria';
    } elseif (strlen($location['provincia']) !== 2) {
        $errors[] = 'Provincia deve essere 2 caratteri (es. MI, RM)';
    }

    if (empty($location['cap'])) {
        $errors[] = 'CAP obbligatorio';
    } elseif (!preg_match('/^\d{5}$/', $location['cap'])) {
        $errors[] = 'CAP deve essere 5 cifre';
    }

    return $errors;
}
```

### API Usage Examples

#### Create Tenant with Locations

```javascript
// Frontend JavaScript
const tenantData = {
    denominazione: 'Company Name',
    codice_fiscale: 'CMPNY12345678901',
    partita_iva: '12345678901',
    sede_legale: {
        indirizzo: 'Via Roma',
        civico: '123',
        cap: '20100',
        comune: 'Milano',
        provincia: 'MI'
    },
    sedi_operative: [
        {
            indirizzo: 'Via Torino',
            civico: '45',
            cap: '10121',
            comune: 'Torino',
            provincia: 'TO'
        }
    ]
};

const response = await fetch('/api/tenants/create.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify(tenantData)
});

const result = await response.json();
console.log('Tenant created:', result.data.tenant_id);
console.log('Locations created:', result.data.locations_created);
```

#### Get Tenant with Locations

```javascript
const response = await fetch(`/api/tenants/get.php?tenant_id=${tenantId}`);
const result = await response.json();

console.log('Sede legale:', result.data.sede_legale);
console.log('Sedi operative:', result.data.sedi_operative);

// Access sede legale data
const comune = result.data.sede_legale.comune;
const provincia = result.data.sede_legale.provincia;

// Loop through sedi operative
result.data.sedi_operative.forEach(sede => {
    console.log(`${sede.comune} (${sede.provincia})`);
});
```

### Database Views

Use pre-built views for common queries:

```sql
-- Get all tenants with their sede legale
SELECT * FROM v_tenants_with_sede_legale;

-- Get all active locations with tenant info
SELECT * FROM v_tenant_locations_active;

-- Get location counts per tenant
SELECT * FROM v_tenant_location_counts;
```

### Performance Tips

1. **Use indexes:** Queries on `tenant_id`, `location_type`, `comune`, `provincia` are indexed
2. **Cached counts:** Use `tenants.total_locations` for quick counts (auto-updated by triggers)
3. **Limit results:** Use `LIMIT` when fetching locations for display
4. **Batch operations:** Use transactions for multiple location inserts/updates

### Common Pitfalls

1. **Forgetting `deleted_at IS NULL`** - Always filter soft-deleted records
2. **Not validating CAP format** - Must be exactly 5 digits
3. **Not uppercasing provincia** - Database trigger does it, but validate first
4. **Not using transactions** - Always wrap location operations in transactions
5. **Hardcoding location limits** - Check business rules for max sedi operative

### Testing Checklist

- [ ] Create tenant with sede legale only
- [ ] Create tenant with sede legale + 1 sede operativa
- [ ] Create tenant with sede legale + 5 sedi operative
- [ ] Update tenant locations (add/remove sedi operative)
- [ ] Delete tenant (verify cascade to locations)
- [ ] List tenants (verify location counts)
- [ ] Get single tenant (verify structured location data)
- [ ] Validate CAP format (5 digits)
- [ ] Validate provincia format (2 uppercase chars)
- [ ] Test soft-delete (locations should be excluded)

### Troubleshooting

#### Issue: "CAP must be exactly 5 digits"
**Solution:** Validate CAP format before insert: `/^\d{5}$/`

#### Issue: "Duplicate entry for primary sede legale"
**Solution:** Database trigger automatically unmarks old primary. Check `is_primary` flag.

#### Issue: Locations not showing in list
**Solution:** Check `deleted_at IS NULL` in query and verify JOIN condition

#### Issue: Transaction rollback on location insert
**Solution:** Validate all required fields (indirizzo, civico, cap, comune, provincia)

### Migration Status

- [x] Database schema created
- [x] Existing data migrated
- [x] Create API updated
- [x] Update API updated
- [x] List API updated
- [x] Get API updated
- [x] Delete API updated
- [x] Test script created
- [x] Documentation completed

### Related Files

- Database schema: `/database/migrations/tenant_locations_schema.sql`
- Test script: `/test_tenant_locations_api.php`
- Full documentation: `/TENANT_LOCATIONS_MIGRATION.md`
- API endpoints: `/api/tenants/*.php`

---

**Quick Start:**
1. Run migration: `database/migrations/tenant_locations_schema.sql`
2. Test APIs: `php test_tenant_locations_api.php`
3. Use examples above in your code
