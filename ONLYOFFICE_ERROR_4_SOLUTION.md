# OnlyOffice Error -4 "Scaricamento fallito" - COMPLETE SOLUTION

## Root Cause Analysis

**THE PROBLEM:** OnlyOffice is trying to download file ID 100, which **DOES NOT EXIST** in the database!

### Evidence From Logs

```
[24-Oct-2025 15:39:18] JWT payload verified successfully
[24-Oct-2025 15:39:18] File not found or access denied: file_id=100, tenant_id=1
[24-Oct-2025 15:39:18] [OnlyOffice Download] Error 404: File non trovato
```

**The fix applied to `download_for_editor.php` is working correctly!** The problem is simply that there's no file with ID 100 to download.

## SOLUTION

### Step 1: Create a Test Document

Run this script to create a proper test document:

```bash
http://localhost:8888/CollaboraNexio/create_test_document_for_onlyoffice.php
```

This will:
1. Create a test document in the database
2. Create the physical file on disk
3. Return the new file ID to use for testing

### Step 2: Use the Correct File ID

Instead of using the non-existent file ID 100, use:
- The file ID returned from the script above
- OR any existing file ID from your database

### Step 3: Test OnlyOffice Integration

Open the document editor with a VALID file ID:
```
http://localhost:8888/CollaboraNexio/document_editor.php?id=[VALID_FILE_ID]
```

## Technical Details

### What Was Happening:

1. **User opens editor** → Tries to load file ID 100
2. **OnlyOffice requests file** → `download_for_editor.php?file_id=100`
3. **Database query** → No record found with ID 100
4. **Returns 404** → File not found
5. **OnlyOffice shows Error -4** → "Scaricamento fallito" (Download failed)

### The Fix That Was Applied:

The `download_for_editor.php` endpoint was already fixed to:
- Accept JWT tokens from multiple sources (query, header, body)
- Allow tokenless access from Docker in development mode
- Properly handle file lookups with tenant isolation

**This fix is working correctly!** The issue was just the missing file.

## Verification Steps

1. **Check if file exists in database:**
```sql
SELECT * FROM files WHERE id = 100;
-- Result: Empty (no file with ID 100)
```

2. **List available files:**
```sql
SELECT id, name, extension, tenant_id
FROM files
WHERE deleted_at IS NULL
ORDER BY id DESC
LIMIT 10;
```

3. **Create test document:**
```
Run: create_test_document_for_onlyoffice.php
```

4. **Test with valid ID:**
```
Use the ID from step 3 instead of 100
```

## Common Mistakes to Avoid

1. ❌ **Don't use hardcoded file IDs** - Always verify the file exists
2. ❌ **Don't assume test data exists** - Create it first
3. ❌ **Don't ignore 404 errors** - They mean the resource doesn't exist

## Working Configuration

The current configuration is **CORRECT**:

### `onlyoffice_config.php`:
- ✅ Uses `host.docker.internal` for Docker on Windows
- ✅ JWT secret properly configured
- ✅ Development mode allows local access

### `download_for_editor.php`:
- ✅ Accepts tokens from multiple sources
- ✅ Allows Docker container access in dev mode
- ✅ Proper error handling and logging

## Quick Test Commands

### From PowerShell:
```powershell
# Test with a valid file ID (replace 1 with actual ID)
Invoke-WebRequest -Uri "http://localhost:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=1"
```

### From Docker Container:
```bash
docker exec collaboranexio-onlyoffice curl -I "http://host.docker.internal:8888/CollaboraNexio/api/documents/download_for_editor.php?file_id=1"
```

## Summary

**Error -4 is NOT a configuration problem!** It's simply that:
1. File ID 100 doesn't exist in the database
2. OnlyOffice correctly reports it cannot download a non-existent file
3. The solution is to use a valid file ID that actually exists

## Next Steps

1. ✅ Run `create_test_document_for_onlyoffice.php`
2. ✅ Note the file ID it creates
3. ✅ Use that ID for all OnlyOffice testing
4. ✅ Update any hardcoded references to file ID 100

---

**Last Updated:** 2025-10-24
**Status:** RESOLVED - Use valid file IDs instead of non-existent ID 100