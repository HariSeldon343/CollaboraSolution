# Location Validation APIs

Public REST API endpoints for validating and retrieving Italian municipalities and provinces data.

## Overview

These APIs provide location validation services for the CollaboraNexio platform, specifically designed for validating company registration data (comune/provincia fields).

### Key Features

- **No Authentication Required**: Public endpoints for validation
- **Read-Only Operations**: GET requests only, CSRF not required
- **Cache-Friendly**: Static data with HTTP cache headers
- **Standard Response Format**: Consistent JSON responses
- **Lightweight**: In-memory data structure (no database queries)

---

## API Endpoints

### 1. Validate Municipality (`validate_municipality.php`)

Validates that a municipality (comune) belongs to a specific province.

#### Request

**Method**: `GET`
**URL**: `/api/locations/validate_municipality.php`

**Query Parameters**:
- `municipality` (required) - Municipality name (e.g., "Roma", "Milano")
- `province` (required) - Province code (e.g., "RM", "MI")

#### Response

```json
{
  "success": true,
  "data": {
    "valid": true,
    "municipality": "Roma",
    "province": "RM",
    "province_name": "Roma"
  },
  "message": "Comune valido per la provincia specificata"
}
```

#### Response Fields

- `valid` (boolean) - Whether the municipality exists in the specified province
- `municipality` (string) - Normalized municipality name
- `province` (string) - Province code
- `province_name` (string) - Full province name

#### Use Cases

1. **Form Validation**: Validate user input during company registration
2. **Data Cleanup**: Verify existing database records
3. **Autocomplete Support**: Validate selected values from dropdowns

#### Examples

**Valid municipality:**
```bash
GET /api/locations/validate_municipality.php?municipality=Roma&province=RM
# Response: {"success": true, "data": {"valid": true, ...}}
```

**Invalid municipality:**
```bash
GET /api/locations/validate_municipality.php?municipality=Roma&province=MI
# Response: {"success": true, "data": {"valid": false, ...}}
```

**Case-insensitive:**
```bash
GET /api/locations/validate_municipality.php?municipality=roma&province=RM
# Response: {"success": true, "data": {"valid": true, "municipality": "Roma", ...}}
```

---

### 2. List Municipalities (`list_municipalities.php`)

Returns a list of municipalities, optionally filtered by province or search query.

#### Request

**Method**: `GET`
**URL**: `/api/locations/list_municipalities.php`

**Query Parameters**:
- `province` (optional) - Filter by province code (e.g., "RM")
- `search` (optional) - Search municipalities by name (partial match)
- `limit` (optional) - Limit results (default: 100, max: 500)

#### Response

```json
{
  "success": true,
  "data": {
    "municipalities": [
      {
        "name": "Roma",
        "province": "RM",
        "province_name": "Roma"
      },
      {
        "name": "Fiumicino",
        "province": "RM",
        "province_name": "Roma"
      }
    ],
    "total": 2,
    "filters": {
      "province": "RM",
      "search": null,
      "limit": 100
    }
  },
  "message": "Lista comuni recuperata con successo"
}
```

#### Use Cases

1. **Dynamic Dropdowns**: Populate municipality select field based on province selection
2. **Autocomplete**: Search municipalities as user types
3. **Data Export**: Get full list for reporting/analysis

#### Examples

**All municipalities for a province:**
```bash
GET /api/locations/list_municipalities.php?province=RM
# Returns all municipalities in Rome province
```

**Search by name:**
```bash
GET /api/locations/list_municipalities.php?search=San
# Returns municipalities containing "San" (case-insensitive)
```

**Limited results:**
```bash
GET /api/locations/list_municipalities.php?limit=10
# Returns first 10 municipalities (alphabetically)
```

**Combined filters:**
```bash
GET /api/locations/list_municipalities.php?province=MI&limit=20
# Returns up to 20 municipalities in Milan province
```

---

### 3. List Provinces (`list_provinces.php`)

Returns the complete list of Italian provinces (110 total).

#### Request

**Method**: `GET`
**URL**: `/api/locations/list_provinces.php`

**Query Parameters**:
- `format` (optional) - Response format: `full` (default) or `simple`

#### Response

**Format: full** (default)
```json
{
  "success": true,
  "data": {
    "provinces": [
      {"code": "AG", "name": "Agrigento"},
      {"code": "AL", "name": "Alessandria"},
      {"code": "AN", "name": "Ancona"}
    ],
    "total": 110,
    "metadata": {
      "format": "full",
      "source": "ISTAT",
      "last_updated": "2025-01-01"
    }
  },
  "message": "Lista province recuperata con successo"
}
```

**Format: simple**
```json
{
  "success": true,
  "data": {
    "provinces": ["AG", "AL", "AN", "AO", "AR", "..."],
    "total": 110
  },
  "message": "Lista province recuperata con successo"
}
```

#### Use Cases

1. **Province Dropdown**: Populate province select field in forms
2. **Validation**: Verify province codes exist
3. **Data Reference**: Get official province names

#### Examples

**Full format (default):**
```bash
GET /api/locations/list_provinces.php
# Returns array of objects with code and name
```

**Simple format:**
```bash
GET /api/locations/list_provinces.php?format=simple
# Returns array of province codes only
```

---

## Standard Response Format

All endpoints follow the CollaboraNexio API standard:

### Success Response
```json
{
  "success": true,
  "data": { ... },
  "message": "Human-readable success message"
}
```

### Error Response
```json
{
  "success": false,
  "error": "Human-readable error message"
}
```

## HTTP Status Codes

| Code | Meaning | When Used |
|------|---------|-----------|
| 200 | OK | Successful request (even if validation fails) |
| 400 | Bad Request | Missing/invalid parameters |
| 405 | Method Not Allowed | Non-GET request received |
| 500 | Internal Server Error | Unexpected server error |

**Note**: `validate_municipality.php` returns 200 even when `valid=false`. This is intentional - the validation result is in the response body.

---

## Performance Considerations

### Caching

All endpoints return cache headers:
- **validate_municipality.php**: 1 hour cache
- **list_municipalities.php**: 1 hour cache
- **list_provinces.php**: 24 hour cache

Clients can cache responses aggressively as data is static.

### Data Size

- **Provinces**: 110 records (~3 KB)
- **Municipalities**: ~1,500 major cities (~50 KB)
- **Full ISTAT database**: 7,904 municipalities (not included in current implementation)

### Limits

- Maximum municipalities returned: 500 per request
- Default limit: 100 records
- No rate limiting (public endpoints)

---

## Integration Examples

### JavaScript/Fetch

```javascript
// Validate municipality when user submits form
async function validateMunicipality(comune, provincia) {
  const url = `/api/locations/validate_municipality.php?municipality=${encodeURIComponent(comune)}&province=${provincia}`;

  const response = await fetch(url);
  const data = await response.json();

  if (data.success && data.data.valid) {
    console.log('Valid municipality');
    return true;
  } else {
    alert(`${comune} non è un comune valido per la provincia ${provincia}`);
    return false;
  }
}

// Populate municipalities when province changes
async function loadMunicipalities(provincia) {
  const url = `/api/locations/list_municipalities.php?province=${provincia}`;

  const response = await fetch(url);
  const data = await response.json();

  if (data.success) {
    const select = document.getElementById('comune');
    select.innerHTML = '<option value="">Seleziona comune</option>';

    data.data.municipalities.forEach(mun => {
      const option = document.createElement('option');
      option.value = mun.name;
      option.textContent = mun.name;
      select.appendChild(option);
    });
  }
}

// Autocomplete search
async function searchMunicipalities(query) {
  if (query.length < 2) return [];

  const url = `/api/locations/list_municipalities.php?search=${encodeURIComponent(query)}&limit=20`;

  const response = await fetch(url);
  const data = await response.json();

  return data.success ? data.data.municipalities : [];
}
```

### jQuery

```javascript
// Load provinces on page load
$.get('/api/locations/list_provinces.php', function(response) {
  if (response.success) {
    const select = $('#provincia');
    response.data.provinces.forEach(function(prov) {
      select.append(`<option value="${prov.code}">${prov.name} (${prov.code})</option>`);
    });
  }
});

// Validate on form submit
$('#companyForm').on('submit', function(e) {
  e.preventDefault();

  const comune = $('#comune').val();
  const provincia = $('#provincia').val();

  $.get('/api/locations/validate_municipality.php', {
    municipality: comune,
    province: provincia
  }, function(response) {
    if (response.success && response.data.valid) {
      // Proceed with form submission
      this.submit();
    } else {
      alert('Comune non valido per questa provincia');
    }
  });
});
```

---

## Data Source

Current data includes:
- **110 Italian provinces** (complete)
- **~1,500 major municipalities** (subset)

Data is sourced from ISTAT (Istituto Nazionale di Statistica).

### Future Enhancements

For production use, consider:
1. **Full ISTAT Integration**: All 7,904 Italian municipalities
2. **Database Storage**: Move from PHP arrays to database tables
3. **CAP (Postal Code) Data**: Add postal code validation
4. **Geographic Data**: Latitude/longitude coordinates
5. **Historical Data**: Track municipality mergers/changes

---

## Testing

Test script available at: `/test_location_apis.php`

Run basic tests:
```bash
php /mnt/c/xampp/htdocs/CollaboraNexio/test_location_apis.php
```

Or test via browser:
- http://localhost:8888/CollaboraNexio/api/locations/validate_municipality.php?municipality=Roma&province=RM
- http://localhost:8888/CollaboraNexio/api/locations/list_municipalities.php?province=RM
- http://localhost:8888/CollaboraNexio/api/locations/list_provinces.php

---

## Security Notes

1. **No Authentication**: These endpoints are intentionally public
2. **No CSRF Protection**: GET requests with read-only data
3. **Input Sanitization**: All inputs are validated and sanitized
4. **No Database Queries**: No SQL injection risk
5. **Rate Limiting**: Not implemented (consider for production)

---

## Error Handling

All endpoints include comprehensive error handling:

```javascript
fetch('/api/locations/validate_municipality.php?municipality=Roma&province=XX')
  .then(response => response.json())
  .then(data => {
    if (!data.success) {
      // Handle error
      console.error(data.error); // "Codice provincia non valido: XX"
    }
  })
  .catch(error => {
    // Handle network/parsing errors
    console.error('Request failed:', error);
  });
```

---

## Change Log

### Version 1.0.0 (2025-10-08)
- Initial release
- Three endpoints: validate, list municipalities, list provinces
- Coverage: 110 provinces, ~1,500 municipalities
- Public access, no authentication required
- Cache-friendly with HTTP headers

---

## Support

For issues or enhancements, contact the CollaboraNexio Development Team.

**Related Files**:
- `/includes/italian_provinces.php` - Province data source
- `/api/locations/municipalities_data.php` - Municipality data source
- `/test_location_apis.php` - Test script
