# Schema Drift Fixes - Automated Test Suite

## Overview

This comprehensive test suite validates all critical functionalities affected by the recent schema drift fixes in CollaboraNexio. The tests ensure that all APIs and database queries correctly use the updated column names.

## Schema Changes Fixed

The following column naming inconsistencies were corrected across the application:

| Old Column Name | Correct Column Name | Table | Usage |
|----------------|-------------------|-------|-------|
| `size_bytes` | `file_size` | `files` | File size in bytes |
| `storage_path` | `file_path` | `files` | Physical file path |
| `owner_id` | `uploaded_by` | `files` | User who uploaded the file |

**Note:** The `file_versions` table intentionally uses different column names (`size_bytes`, `storage_path`) for archival semantics. This is by design and NOT a schema drift issue.

## Files Updated

The following API files were updated to use the correct schema:

1. `/api/files_complete.php` - Complete file management API
2. `/api/documents/pending.php` - Pending documents listing
3. `/api/documents/approve.php` - Document approval endpoint
4. `/api/documents/reject.php` - Document rejection endpoint
5. `/api/router.php` - Main API router with dashboard stats
6. `/api/files_tenant.php` - Tenant-specific file operations
7. `/api/files_tenant_fixed.php` - Fixed tenant file operations

## Test Suite Location

**Test Script:** `/mnt/c/xampp/htdocs/CollaboraNexio/test_schema_drift_fixes.php`

**Browser URL:** `http://localhost:8888/CollaboraNexio/test_schema_drift_fixes.php`

## Test Categories

### Category A: File Listing Tests (4 tests)

1. **Files table schema verification** - Verifies the `files` table has correct column names
2. **File listing query with correct columns** - Tests that SELECT queries use correct columns
3. **File listing with folder filter** - Tests folder-based filtering
4. **File listing with search filter** - Tests search functionality

### Category B: File Upload Tests (2 tests)

1. **INSERT query structure verification** - Validates INSERT statements use correct columns
2. **uploaded_by foreign key constraint** - Verifies foreign key relationships are correct

### Category C: Document Approval Tests (4 tests)

1. **Pending documents listing with JOIN** - Tests JOIN queries use `uploaded_by` correctly
2. **Approve action UPDATE query structure** - Validates approval UPDATE statements
3. **Reject action UPDATE query structure** - Validates rejection UPDATE statements
4. **Approval history tracking** - Tests approval metadata is correctly stored

### Category D: Dashboard Stats Tests (4 tests)

1. **File size statistics with SUM(file_size)** - Tests aggregate queries use `file_size`
2. **Storage by tenant statistics** - Tests per-tenant storage calculations
3. **Files by status statistics** - Tests status-based grouping
4. **Recent activity with uploader information** - Tests recent files query with `uploaded_by` JOIN

### Category E: Database Integrity Tests (4 tests)

1. **Valid uploaded_by references** - Ensures all files reference valid users
2. **Valid file_size values** - Checks all file sizes are numeric and non-negative
3. **Valid file_path values** - Verifies no empty file paths exist
4. **Tenant isolation integrity** - Ensures files match uploader's tenant

### Category F: API Response Format Tests (3 tests)

1. **File listing response structure** - Validates JSON response format
2. **Error response structure** - Validates error response format
3. **Approval response structure** - Validates approval response format

## Running the Tests

### Method 1: Browser (Recommended)

1. Ensure XAMPP is running (Apache and MySQL)
2. Open your browser
3. Navigate to: `http://localhost:8888/CollaboraNexio/test_schema_drift_fixes.php`
4. View the comprehensive HTML report with:
   - Summary statistics (Total, Passed, Failed, Execution Time)
   - Detailed test results by category
   - Color-coded pass/fail indicators
   - JSON details for each test

### Method 2: Command Line (If PHP CLI available)

```bash
cd /mnt/c/xampp/htdocs/CollaboraNexio
php test_schema_drift_fixes.php > test_results.html
```

Then open `test_results.html` in your browser.

## Understanding Test Results

### Visual Indicators

- ✅ **Green checkmark** = Test PASSED
- ❌ **Red X** = Test FAILED

### Summary Cards

- **Total Tests** - Total number of tests executed
- **Passed** - Number of successful tests (green)
- **Failed** - Number of failed tests (red)
- **Execution Time** - Time taken to run all tests (in milliseconds)

### Test Details

Each test shows:
- **Test Name** - Descriptive name of what is being tested
- **Status Badge** - PASS (green) or FAIL (red)
- **Message** - Human-readable description of the result
- **Details** - JSON data showing actual values and query results
- **Timestamp** - When the test was executed

## Expected Results

If all schema drift fixes were applied correctly, you should see:

- **Total Tests:** 21
- **Passed:** 21
- **Failed:** 0

### Common Failure Scenarios

1. **Schema verification failures** - Database still has old column names
   - **Solution:** Run database migration scripts to update schema

2. **Foreign key constraint failures** - Missing or incorrect foreign keys
   - **Solution:** Run `database/03_complete_schema.sql` to recreate constraints

3. **Integrity test failures** - Data inconsistencies in the database
   - **Solution:** Clean up orphaned records or run data validation scripts

4. **Query structure failures** - API files not updated correctly
   - **Solution:** Verify API files use correct column names in all queries

## Test Safety

The test suite is designed to be **safe and non-destructive**:

- ✅ Uses only SELECT queries (read-only)
- ✅ Does not modify production data
- ✅ Does not insert, update, or delete records
- ✅ Can be run multiple times (idempotent)
- ✅ No authentication required (direct database access)

## Troubleshooting

### Test won't load in browser

1. Check XAMPP is running (Apache and MySQL)
2. Verify the URL: `http://localhost:8888/CollaboraNexio/test_schema_drift_fixes.php`
3. Check PHP error logs: `/mnt/c/xampp/htdocs/CollaboraNexio/logs/php_errors.log`

### Database connection errors

1. Verify MySQL is running in XAMPP
2. Check database credentials in `/mnt/c/xampp/htdocs/CollaboraNexio/config.php`
3. Ensure database `collaboranexio` exists

### All tests failing

1. Verify the database schema is up to date:
   ```bash
   php /mnt/c/xampp/htdocs/CollaboraNexio/check_database_structure.php
   ```

2. Run the complete schema migration:
   ```bash
   php /mnt/c/xampp/htdocs/CollaboraNexio/database/manage_database.php full
   ```

### Specific tests failing

1. Review the test details JSON output
2. Check the corresponding API files for correct column usage
3. Verify foreign key constraints exist
4. Check for data integrity issues in the database

## Integration with Development Workflow

### When to Run Tests

- ✅ After applying schema migrations
- ✅ After updating API files
- ✅ Before deploying to production
- ✅ When troubleshooting file-related issues
- ✅ As part of regular system health checks

### Continuous Monitoring

Consider running these tests:
- After database backups/restores
- After major code updates
- Weekly as part of maintenance
- Before and after deployment

## Next Steps After Testing

### If All Tests Pass ✅

1. Document the successful test run
2. Proceed with deployment
3. Monitor production logs for any issues
4. Schedule regular test runs

### If Any Tests Fail ❌

1. **DO NOT DEPLOY** until all tests pass
2. Review failed test details
3. Fix the underlying issues
4. Re-run tests to verify fixes
5. Document the fixes applied

## Additional Test Files

Consider creating additional tests for:
- End-to-end file upload/download workflows
- Multi-tenant file access permissions
- Document approval workflows
- API endpoint integration tests

## Support

For issues or questions about the test suite:
1. Check the test output details for specific error messages
2. Review the schema documentation in `database/03_complete_schema.sql`
3. Check API file implementations for correct column usage
4. Consult the main project documentation in `CLAUDE.md`

## Test Suite Maintenance

This test suite should be updated when:
- New schema changes are introduced
- New API endpoints are added
- File-related features are modified
- Database structure changes

---

**Last Updated:** 2025-10-04
**Version:** 1.0.0
**Compatibility:** PHP 8.3, MySQL 8.0+, CollaboraNexio v1.x
