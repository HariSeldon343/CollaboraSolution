# Document Creation 500 Error - Diagnostic Report

## EXECUTIVE SUMMARY

**Root Cause Identified:** Fatal PHP error in `FileHelper::formatFileSize()` method
**Error Type:** `Call to undefined function string()`
**Location:** `/includes/file_helper.php` line 198
**Severity:** CRITICAL - Causes immediate PHP fatal error and empty response

## EXACT ERROR FROM LOGS

```
[12-Oct-2025 15:23:20 Europe/Rome] PHP Fatal error:  Uncaught Error: Call to undefined function string() in C:\xampp\htdocs\CollaboraNexio\includes\file_helper.php:198
Stack trace:
#0 C:\xampp\htdocs\CollaboraNexio\api\files\create_document.php(196): FileHelper::formatFileSize(1200)
#1 C:\xampp\htdocs\CollaboraNexio\api\files\create_document.php(95): createDocument('docx', 'trse.docx', 1, 32, 19, Object(Database))
#2 {main}
  thrown in C:\xampp\htdocs\CollaboraNexio\includes\file_helper.php on line 198
```

## DETAILED ANALYSIS

### 1. Error Origin

The error occurs in the `formatFileSize()` method at line 198:

```php
// INCORRECT CODE:
$factor = floor((strlen(string($bytes)) - 1) / 3);
```

**Problem:** `string()` is NOT a valid PHP function. This appears to be a typo or incorrect type casting attempt.

### 2. Execution Flow Leading to Error

1. User clicks "Crea Documento" button
2. Frontend sends POST request to `/api/files/create_document.php`
3. API validates input and calls `createDocument()` function
4. Document is successfully created (DOCX/XLSX/PPTX ZIP file)
5. Database record is inserted successfully
6. Return array is built, calling `FileHelper::formatFileSize($fileSize)` at line 196
7. **FATAL ERROR** occurs when trying to execute `string($bytes)`
8. PHP crashes before sending JSON response
9. Frontend receives empty response body
10. JavaScript throws "Unexpected end of JSON input" error

### 3. Why Previous Fixes Didn't Work

- **Database column fix**: Was correct but not the root cause
- **tempnam() unlink() fix**: Was correct but not the root cause
- The actual error happens AFTER file creation succeeds
- The file and database record are created successfully
- The crash happens when formatting the response data

### 4. Impact Assessment

**Severity:** CRITICAL
- Complete failure of document creation feature
- Empty response confuses frontend
- No user feedback on what went wrong
- File is created on disk but user never sees it
- Database record exists but UI doesn't refresh

## THE FIX

### Corrected Code

Replace line 198 in `/includes/file_helper.php`:

```php
// BEFORE (INCORRECT):
$factor = floor((strlen(string($bytes)) - 1) / 3);

// AFTER (CORRECT):
$factor = floor((strlen((string)$bytes) - 1) / 3);
```

**Explanation:** In PHP, type casting is done with `(string)$variable` NOT `string($variable)`.

### Alternative (More Robust) Implementation

For better maintainability, use `log()` instead of string length:

```php
public static function formatFileSize(int $bytes): string {
    if ($bytes == 0) return '0 B';

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor(log($bytes, 1024));
    $factor = min($factor, count($units) - 1); // Prevent array overflow

    return sprintf("%.2f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
}
```

**Benefits of alternative approach:**
- More mathematically correct
- No string conversion needed
- Handles edge cases better
- Standard approach in file size formatting

## VERIFICATION STEPS

### 1. Check Current Error Logs

```bash
tail -50 /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log
```

### 2. Apply the Fix

Edit `/includes/file_helper.php` line 198

### 3. Test Document Creation

```bash
php /mnt/c/xampp/htdocs/CollaboraNexio/test_create_document_direct.php
```

### 4. Monitor Error Logs

```bash
tail -f /mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log
```

### 5. Test in Browser

- Open Files page
- Click "Crea Documento"
- Select document type (DOCX/XLSX/PPTX)
- Enter name
- Submit
- Verify success message appears
- Verify file appears in file list
- Verify file can be downloaded

## ADDITIONAL FINDINGS

### Secondary Issue: Audit Logs

```
[12-Oct-2025 15:23:20 Europe/Rome] Audit log failed: Errore durante l'inserimento del record
```

**Status:** Non-blocking - handled gracefully with try-catch
**Impact:** Low - audit logs are optional
**Action:** No immediate fix needed, but should be investigated separately

### File Creation Success

**Good News:** The core document creation logic works:
- ZIP archive creation succeeds (DOCX/XLSX/PPTX)
- File write to disk succeeds
- Database insertion succeeds
- Only the response formatting fails

## RECOMMENDED ACTIONS

### Immediate (CRITICAL)

1. ✅ Apply the fix to line 198 of `/includes/file_helper.php`
2. ✅ Test document creation for all types (DOCX, XLSX, PPTX, TXT)
3. ✅ Verify error logs show no more fatal errors

### Short-term (HIGH)

1. Review entire `FileHelper` class for similar issues
2. Add unit tests for `formatFileSize()` method
3. Add integration tests for document creation API

### Long-term (MEDIUM)

1. Investigate audit_logs table issues
2. Add PHP linting to CI/CD pipeline to catch such errors
3. Add automated API testing before deployment

## TEST SCRIPTS PROVIDED

### 1. Direct API Test (`test_create_document_direct.php`)

See attached test script that simulates API call directly.

### 2. CURL Test

```bash
curl -X POST http://localhost:8888/api/files/create_document.php \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION" \
  -d '{"type":"docx","name":"Test Document","folder_id":null}'
```

## CONCLUSION

**The 500 error is caused by a simple typo in the `formatFileSize()` function.**

The function uses `string($bytes)` which is not valid PHP syntax. It should be `(string)$bytes` for type casting.

This is a one-line fix that will immediately resolve the document creation 500 error.

---

**Report Generated:** 2025-10-12 15:30:00
**PHP Version:** 8.3
**System:** CollaboraNexio Document Management
**Status:** FIX READY FOR DEPLOYMENT
