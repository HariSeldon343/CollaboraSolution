# Workflow Roles Acceptance Tests - Quick Start Guide

**Date:** 2025-11-05
**Status:** Ready for Execution
**Related:** BUG-068

---

## Quick Start (TL;DR)

### Test 1: Automated API Test (5 minutes)

1. **Open in browser:**
   ```
   http://localhost:8888/CollaboraNexio/test_workflow_roles_api.php
   ```

2. **Expected result:**
   ```
   ✅ ALL TESTS PASSED! API is working correctly.
   Status: ✅ PRODUCTION READY
   Pass Rate: 100.0%
   ```

3. **If you see failures:** Screenshot and report which tests failed.

---

### Tests 2-5: Manual UI Tests (15 minutes)

**Full documentation:** `/WORKFLOW_ROLES_ACCEPTANCE_TESTS.md`

**Quick checklist:**

#### Test 2: UI Populated
1. Login as `a.oedoma@gmail.com` (Password: `Admin123!`)
2. Go to "Gestione Documenti"
3. Right-click any file → "Gestisci Ruoli Workflow"
4. ✅ Dropdowns show users? YES/NO
5. ✅ "Ruoli Attuali" section visible? YES/NO

#### Test 3: Save Functionality
1. Select 1 user in "Validatori" dropdown
2. Click "Salva Validatori"
3. ✅ Success toast appears? YES/NO
4. ✅ "Ruoli Attuali" updates? YES/NO
5. ✅ No console errors? (F12) YES/NO

#### Test 4: Persistence
1. Close modal (click "Chiudi")
2. Right-click same file → "Gestisci Ruoli Workflow"
3. ✅ Saved validator still shown? YES/NO

#### Test 5: Multi-Tenant (Super Admin only)
1. Login as `asamodeo@fortibyte.it` (Super Admin)
2. Navigate to Tenant 1 folder
3. Right-click file → "Gestisci Ruoli Workflow"
4. ✅ Shows Tenant 1 users only? YES/NO
5. Navigate to Tenant 11 folder
6. Right-click file → "Gestisci Ruoli Workflow"
7. ✅ Shows Tenant 11 users only? YES/NO

---

## Success Criteria (ALL must be YES)

- [ ] ✅ Test 1: API tests PASS (15+/15+)
- [ ] ✅ Test 2: Dropdowns populated
- [ ] ✅ Test 3: Save works + UI updates
- [ ] ✅ Test 4: Persistence works
- [ ] ✅ Test 5: Multi-tenant isolation works
- [ ] ✅ No console errors
- [ ] ✅ No 404/500 errors

---

## If All Tests PASS

**Cleanup:**
```bash
# Delete test files
rm /mnt/c/xampp/htdocs/CollaboraNexio/test_workflow_roles_api.php
```

**Report:**
- Update BUG-068 in `bug.md` → Status: ✅ COMPLETE
- All 5 tests PASSED ✅

---

## If Any Test FAILS

**Report:**
1. Which test failed? (1, 2, 3, 4, or 5)
2. What did you see? (screenshot if possible)
3. Console errors? (F12 → Console tab)
4. Network errors? (F12 → Network tab)

**Next steps:**
- I will diagnose and fix the issue
- Re-run tests after fix

---

## Files Created

1. **test_workflow_roles_api.php** (500+ lines)
   - Automated API validation
   - Execute in browser
   - Will be deleted after tests complete

2. **WORKFLOW_ROLES_ACCEPTANCE_TESTS.md** (1,100+ lines)
   - Comprehensive manual test documentation
   - Step-by-step instructions
   - Troubleshooting guide

3. **ACCEPTANCE_TESTS_EXECUTION_GUIDE.md** (this file)
   - Quick start guide
   - Checklist format

---

## Prerequisites (Already Verified ✅)

- ✅ Database: user_tenant_access populated (2 records)
- ✅ API: Normalized response structure (BUG-066)
- ✅ Test Users: User 19, User 32 configured
- ✅ Test Tenants: Tenant 1, Tenant 11 ready

---

## Estimated Time

- **Test 1 (Automated):** 5 minutes
- **Test 2-5 (Manual):** 15 minutes
- **Total:** ~20 minutes

---

## Need Help?

**Full documentation:** `/WORKFLOW_ROLES_ACCEPTANCE_TESTS.md`

**Key sections:**
- Prerequisites (page 1)
- Test 1: API Direct Call (page 2)
- Test 2: UI Populated (page 5)
- Test 3: Save Functionality (page 8)
- Test 4: Persistence (page 11)
- Test 5: Multi-Tenant (page 13)
- Troubleshooting Guide (page 16)

---

**Last Updated:** 2025-11-05
**Status:** Ready for Execution
