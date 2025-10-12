# Location APIs - Quick Reference

> Fast reference guide for Italian location validation APIs

## 🚀 Quick Start

### Base URL
```
/api/locations/
```

### No Auth Required
All endpoints are public. No session, no CSRF token needed.

---

## 📍 Endpoints

### 1. Validate Municipality

**Check if comune belongs to provincia**

```http
GET /api/locations/validate_municipality.php?municipality=Roma&province=RM
```

**Response:**
```json
{
  "success": true,
  "data": {
    "valid": true,
    "municipality": "Roma",
    "province": "RM",
    "province_name": "Roma"
  }
}
```

**Parameters:**
- `municipality` (required) - Comune name
- `province` (required) - Province code (2 letters)

---

### 2. List Municipalities

**Get list of comuni (optionally filtered)**

```http
GET /api/locations/list_municipalities.php?province=RM
```

**Response:**
```json
{
  "success": true,
  "data": {
    "municipalities": [
      {"name": "Roma", "province": "RM", "province_name": "Roma"},
      {"name": "Fiumicino", "province": "RM", "province_name": "Roma"}
    ],
    "total": 2
  }
}
```

**Parameters:**
- `province` (optional) - Filter by province code
- `search` (optional) - Search by name (partial match)
- `limit` (optional) - Max results (default: 100, max: 500)

---

### 3. List Provinces

**Get all 110 Italian provinces**

```http
GET /api/locations/list_provinces.php
```

**Response:**
```json
{
  "success": true,
  "data": {
    "provinces": [
      {"code": "AG", "name": "Agrigento"},
      {"code": "AL", "name": "Alessandria"}
    ],
    "total": 110
  }
}
```

**Parameters:**
- `format` (optional) - `full` (default) or `simple`

---

## 💡 Common Use Cases

### Use Case 1: Form Validation

```javascript
// When user submits company form
async function validateCompanyLocation() {
  const comune = document.getElementById('comune').value;
  const provincia = document.getElementById('provincia').value;

  const response = await fetch(
    `/api/locations/validate_municipality.php?municipality=${comune}&province=${provincia}`
  );
  const data = await response.json();

  if (!data.success || !data.data.valid) {
    alert('Comune non valido per questa provincia');
    return false;
  }

  return true;
}
```

### Use Case 2: Dynamic Dropdown

```javascript
// When user selects a province, populate comuni dropdown
async function onProvinceChange(provinciaCode) {
  const response = await fetch(
    `/api/locations/list_municipalities.php?province=${provinciaCode}`
  );
  const data = await response.json();

  const comuneSelect = document.getElementById('comune');
  comuneSelect.innerHTML = '<option value="">Seleziona comune</option>';

  data.data.municipalities.forEach(mun => {
    comuneSelect.innerHTML += `<option value="${mun.name}">${mun.name}</option>`;
  });
}
```

### Use Case 3: Autocomplete

```javascript
// Search-as-you-type for comuni
let debounceTimer;

function onComuneInput(value) {
  clearTimeout(debounceTimer);

  debounceTimer = setTimeout(async () => {
    if (value.length < 2) return;

    const response = await fetch(
      `/api/locations/list_municipalities.php?search=${value}&limit=20`
    );
    const data = await response.json();

    // Display autocomplete suggestions
    showSuggestions(data.data.municipalities);
  }, 300);
}
```

### Use Case 4: Province Dropdown

```javascript
// Load all provinces on page load
async function loadProvinces() {
  const response = await fetch('/api/locations/list_provinces.php');
  const data = await response.json();

  const select = document.getElementById('provincia');

  data.data.provinces.forEach(prov => {
    select.innerHTML += `<option value="${prov.code}">${prov.name} (${prov.code})</option>`;
  });
}
```

---

## ⚡ Quick Examples

### jQuery

```javascript
// Validate
$.get('/api/locations/validate_municipality.php', {
  municipality: 'Roma',
  province: 'RM'
}, function(data) {
  console.log(data.data.valid); // true
});

// List municipalities
$.get('/api/locations/list_municipalities.php', {
  province: 'MI'
}, function(data) {
  console.log(data.data.municipalities);
});

// List provinces
$.get('/api/locations/list_provinces.php', function(data) {
  console.log(data.data.provinces);
});
```

### Fetch API

```javascript
// Validate
fetch('/api/locations/validate_municipality.php?municipality=Napoli&province=NA')
  .then(r => r.json())
  .then(data => console.log(data.data.valid));

// List municipalities with search
fetch('/api/locations/list_municipalities.php?search=San&limit=10')
  .then(r => r.json())
  .then(data => console.log(data.data.municipalities));

// List provinces (simple format)
fetch('/api/locations/list_provinces.php?format=simple')
  .then(r => r.json())
  .then(data => console.log(data.data.provinces)); // ['AG', 'AL', ...]
```

### cURL (CLI Testing)

```bash
# Validate
curl "http://localhost:8888/CollaboraNexio/api/locations/validate_municipality.php?municipality=Roma&province=RM"

# List municipalities
curl "http://localhost:8888/CollaboraNexio/api/locations/list_municipalities.php?province=RM"

# List provinces
curl "http://localhost:8888/CollaboraNexio/api/locations/list_provinces.php"
```

---

## 🎯 Response Format

### Success Response
```json
{
  "success": true,
  "data": { ... },
  "message": "..."
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error message here"
}
```

### HTTP Status Codes
- `200` - Success (even if validation fails - check `data.valid`)
- `400` - Bad request (missing/invalid parameters)
- `405` - Method not allowed (must use GET)
- `500` - Internal server error

---

## 🔍 Testing

### Browser Test URLs

```
http://localhost:8888/CollaboraNexio/api/locations/validate_municipality.php?municipality=Roma&province=RM
http://localhost:8888/CollaboraNexio/api/locations/list_municipalities.php?province=RM
http://localhost:8888/CollaboraNexio/api/locations/list_provinces.php
```

### CLI Test Script

```bash
php /mnt/c/xampp/htdocs/CollaboraNexio/test_location_apis.php
```

---

## 📊 Data Coverage

| Data Type | Count | Coverage |
|-----------|-------|----------|
| Provinces | 110 | 100% (all Italian provinces) |
| Municipalities | ~1,500 | Major cities only |

**Note**: Full ISTAT database has 7,904 municipalities. Current implementation includes major cities for lightweight validation.

---

## ⚙️ Performance Tips

1. **Cache responses** - Data is static, cache for 1-24 hours
2. **Use province filter** - More efficient than full search
3. **Limit results** - Use `limit` parameter for large queries
4. **Debounce searches** - Wait 300ms before searching on user input
5. **Preload provinces** - Load once on page load, cache in memory

---

## 🚨 Important Notes

### Validation Logic

- Validation is **case-insensitive** ("roma" = "Roma")
- Response always returns `success: true` (check `data.valid` for result)
- Returns HTTP 200 even if municipality not found

### Security

- **No authentication** - Public endpoints
- **No CSRF token** - GET requests only
- **No rate limiting** - Consider adding for production
- **Input sanitization** - All inputs are validated

### Limitations

- Only major municipalities (~1,500 out of 7,904)
- No CAP (postal code) data
- No geographic coordinates
- Data stored in PHP arrays (not database)

---

## 📚 Full Documentation

See `/api/locations/README.md` for complete documentation.

---

## 🛠️ Integration Checklist

- [ ] Load provinces dropdown on page load
- [ ] Populate municipalities when province selected
- [ ] Validate comune-provincia pair on form submit
- [ ] Show error message if validation fails
- [ ] Handle API errors gracefully
- [ ] Cache responses for better performance
- [ ] Debounce search inputs (if using autocomplete)
- [ ] Test with various province codes
- [ ] Test with special characters (L'Aquila, etc.)

---

## 🔗 Related Files

- `/includes/italian_provinces.php` - Province data source
- `/api/locations/municipalities_data.php` - Municipality data
- `/test_location_apis.php` - Test suite
- `/api/locations/README.md` - Full documentation

---

*Last updated: 2025-10-08*
