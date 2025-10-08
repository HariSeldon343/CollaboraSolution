# User Schema Mismatch Fix - Summary

## Problem Statement

Critical schema mismatch between frontend forms/APIs and database schema for user management:

**Database Reality:**
```sql
users.name VARCHAR(255) NOT NULL  -- Single column for full name
```

**Frontend/API Before Fix:**
- Forms used `first_name` and `last_name` input fields
- JavaScript sent `first_name` and `last_name` to APIs
- APIs expected and processed `first_name` and `last_name`
- **Result:** User creation and updates FAILED

## Solution Implemented

Fixed all layers of the application to align with the single `name` column schema:

### 1. Frontend Forms (utenti.php)

#### Add User Modal (Lines 905-908)
**Before:**
```html
<div class="form-group">
    <label for="addFirstName">Nome</label>
    <input type="text" id="addFirstName" name="first_name" required />
</div>
<div class="form-group">
    <label for="addLastName">Cognome</label>
    <input type="text" id="addLastName" name="last_name" required />
</div>
```

**After:**
```html
<div class="form-group">
    <label for="addName">Nome Completo *</label>
    <input type="text" id="addName" name="name" required placeholder="es. Mario Rossi" />
</div>
```

#### Edit User Modal (Lines 949-951)
**Before:**
```html
<div class="form-group">
    <label for="editFirstName">Nome</label>
    <input type="text" id="editFirstName" name="first_name" required />
</div>
<div class="form-group">
    <label for="editLastName">Cognome</label>
    <input type="text" id="editLastName" name="last_name" required />
</div>
```

**After:**
```html
<div class="form-group">
    <label for="editName">Nome Completo *</label>
    <input type="text" id="editName" name="name" required />
</div>
```

### 2. JavaScript Functions (utenti.php)

#### addUser() Function (Lines 1409-1444)
**Before:**
```javascript
const firstName = form.first_name.value.trim();
const lastName = form.last_name.value.trim();

if (!firstName) {
    this.showToast('Inserisci il nome', 'error');
    document.getElementById('addFirstName').focus();
    return;
}

if (!lastName) {
    this.showToast('Inserisci il cognome', 'error');
    document.getElementById('addLastName').focus();
    return;
}

formData.append('first_name', firstName);
formData.append('last_name', lastName);
```

**After:**
```javascript
const name = form.name.value.trim();

if (!name || name.length < 2) {
    this.showToast('Inserisci il nome completo (almeno 2 caratteri)', 'error');
    document.getElementById('addName').focus();
    return;
}

formData.append('name', name);
```

#### updateUser() Function (Lines 1649-1667)
**Before:**
```javascript
formData.append('user_id', form.user_id.value);
formData.append('first_name', form.first_name.value);
formData.append('last_name', form.last_name.value);
```

**After:**
```javascript
const name = form.name.value.trim();

if (!name || name.length < 2) {
    this.showToast('Inserisci il nome completo (almeno 2 caratteri)', 'error');
    document.getElementById('editName').focus();
    return;
}

formData.append('user_id', form.user_id.value);
formData.append('name', name);
```

#### openEditModal() Function (Lines 1553-1564)
**Before:**
```javascript
const nameParts = user.name ? user.name.split(' ') : null;
const firstName = user.first_name || (nameParts ? nameParts[0] : '');
const lastName = user.last_name || (nameParts ? nameParts.slice(1).join(' ') : '');

document.getElementById('editFirstName').value = firstName;
document.getElementById('editLastName').value = lastName;
```

**After:**
```javascript
// Handle both formats: single 'name' field or 'first_name'/'last_name'
const userName = user.name || `${user.first_name || ''} ${user.last_name || ''}`.trim() || '';

document.getElementById('editName').value = userName;
```

### 3. Backend API - create_simple.php

#### Input Validation (Lines 95-111)
**Before:**
```php
$required = ['first_name', 'last_name', 'email', 'role'];

$firstName = trim($input['first_name']);
$lastName = trim($input['last_name']);
```

**After:**
```php
$required = ['name', 'email', 'role'];

$name = trim($input['name']);

// Validazione lunghezza nome
if (strlen($name) < 2) {
    jsonOut(['success' => false, 'error' => 'Il nome completo deve essere almeno 2 caratteri'], 400);
}
```

#### Database Insert (Lines 205-221)
**Before:**
```php
$fullName = $firstName . ' ' . $lastName;

$stmt->execute([
    $defaultTenantId, $fullName, $email, ...
]);
```

**After:**
```php
$stmt->execute([
    $defaultTenantId, $name, $email, ...
]);
```

#### Email Sending (Line 295)
**Before:**
```php
$emailSent = $emailSender->sendWelcomeEmail($email, $fullName, $resetToken, $tenantName);
```

**After:**
```php
$emailSent = $emailSender->sendWelcomeEmail($email, $name, $resetToken, $tenantName);
```

#### Response (Lines 312-314)
**Before:**
```php
'data' => [
    'id' => $userId,
    'name' => $fullName,
    ...
]
```

**After:**
```php
'data' => [
    'id' => $userId,
    'name' => $name,
    ...
]
```

### 4. Backend API - update_v2.php

#### Input Validation (Lines 37-55)
**Before:**
```php
$first_name = htmlspecialchars(trim($input['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$last_name = htmlspecialchars(trim($input['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');

if (empty($first_name)) {
    $errors[] = 'Nome richiesto';
}
if (empty($last_name)) {
    $errors[] = 'Cognome richiesto';
}
```

**After:**
```php
$name = htmlspecialchars(trim($input['name'] ?? ''), ENT_QUOTES, 'UTF-8');

if (empty($name)) {
    $errors[] = 'Nome completo richiesto';
}
if (strlen($name) < 2) {
    $errors[] = 'Il nome completo deve essere almeno 2 caratteri';
}
```

#### Database Update (Lines 157-163)
**Before:**
```php
$update_data = [
    'first_name' => $first_name,
    'last_name' => $last_name,
    'email' => $email,
    ...
];
```

**After:**
```php
$update_data = [
    'name' => $name,
    'email' => $email,
    ...
];
```

## Validation Rules

### Frontend (JavaScript)
- Name must not be empty
- Name must be at least 2 characters
- Email format validation with regex
- Provides user-friendly error messages in Italian

### Backend (PHP APIs)
- Name is required field
- Name must be at least 2 characters long
- Email validation with `filter_var()`
- XSS protection with `htmlspecialchars()`
- CSRF token validation
- Role-based authorization checks

## Backward Compatibility

The `openEditModal()` function maintains backward compatibility:
```javascript
const userName = user.name || `${user.first_name || ''} ${user.last_name || ''}`.trim() || '';
```

This ensures that:
1. If `name` exists in API response, it uses it directly
2. If old format (`first_name`, `last_name`) exists, it concatenates them
3. If neither exists, defaults to empty string

Similarly, the `renderUsers()` function handles both formats:
```javascript
const userName = user.name || `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Unknown';
```

## Testing Checklist

### User Creation
- [x] Open utenti.php in browser
- [x] Click "Nuovo Utente" button
- [x] Verify single "Nome Completo *" field exists
- [x] Fill in name as "Test User"
- [x] Fill in valid email
- [x] Select role and tenant
- [x] Submit form
- [x] Verify success toast message
- [x] Verify user appears in table
- [x] Check database: `SELECT id, name, email FROM users WHERE email = 'test@example.com'`

### User Update
- [x] Click edit button on existing user
- [x] Verify single "Nome Completo *" field with current name
- [x] Change name to "Updated Name"
- [x] Save changes
- [x] Verify success toast message
- [x] Verify updated name in table
- [x] Check database for updated value

### Validation
- [x] Try to create user with 1-character name → Should fail with error
- [x] Try to create user with empty name → Should fail with error
- [x] Try to update user with invalid name → Should fail with error
- [x] All error messages should be in Italian and user-friendly

## Files Modified

1. `/mnt/c/xampp/htdocs/CollaboraNexio/utenti.php`
   - Add user form HTML (lines 905-908)
   - Edit user form HTML (lines 949-951)
   - `addUser()` JavaScript function (lines 1409-1444)
   - `updateUser()` JavaScript function (lines 1649-1667)
   - `openEditModal()` JavaScript function (lines 1553-1564)

2. `/mnt/c/xampp/htdocs/CollaboraNexio/api/users/create_simple.php`
   - Input validation (lines 95-111)
   - Database insert (lines 205-221)
   - Email sending (line 295)
   - Response data (lines 312-314)

3. `/mnt/c/xampp/htdocs/CollaboraNexio/api/users/update_v2.php`
   - Input validation (lines 37-55)
   - Database update (lines 157-163)

## Expected Outcome

After these fixes:

1. ✅ User creation form has single "Nome Completo" field
2. ✅ User edit form has single "Nome Completo" field
3. ✅ JavaScript sends `name` field to APIs (not `first_name`/`last_name`)
4. ✅ APIs correctly process `name` field
5. ✅ APIs correctly insert/update `name` column in database
6. ✅ All user displays across platform work correctly
7. ✅ Backward compatibility maintained for existing data
8. ✅ Proper validation on both frontend and backend
9. ✅ User-friendly error messages in Italian

## Database Schema Reference

Current `users` table schema (relevant fields):
```sql
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED,
  name VARCHAR(255) NOT NULL,              -- Single column for full name
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user', 'manager', 'admin', 'super_admin') DEFAULT 'user',
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Notes

- All changes maintain existing security measures (CSRF, XSS protection, SQL injection prevention)
- All changes maintain tenant isolation
- All changes maintain soft-delete functionality
- User experience improved with single field instead of two separate fields
- Italian language maintained throughout
- Validation consistent between frontend and backend
