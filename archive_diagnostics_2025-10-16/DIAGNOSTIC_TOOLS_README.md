# 🔧 Tenant Folder Creation - Diagnostic Tools

## Quick Start (30 seconds)

**Problem:** "Cartella Tenant" button not working?

**Solution:** Run these tests in order:

### 1️⃣ Browser Test (Visual - 2 minutes)
```
Open: http://localhost/CollaboraNexio/test_tenant_folder_browser.html
```
- ✅ GREEN = Working
- ❌ RED = Broken (see fix instructions)

### 2️⃣ API Test (Backend - 1 minute)
```
Open: http://localhost/CollaboraNexio/test_tenant_folder_api.php
```
- Check: `"overall_status": "PASS"`

### 3️⃣ Database Fix (If needed - 2 minutes)
```
Run in MySQL: fix_tenant_folder_issues.sql
```
- Fixes schema issues automatically

### 4️⃣ Console Commands (Advanced - 5 minutes)
```
Open files.php → Press F12 → Copy from DIAGNOSTIC_COMMANDS.txt
```
- Step-by-step debugging

---

## 📦 Files Included

| File | Purpose | When to Use |
|------|---------|-------------|
| `test_tenant_folder_browser.html` | Visual browser test with color-coded results | First step - quick visual check |
| `test_tenant_folder_api.php` | Backend API and database verification | If browser test passes but still broken |
| `DIAGNOSTIC_COMMANDS.txt` | Manual console commands for debugging | Advanced troubleshooting |
| `fix_tenant_folder_issues.sql` | Database schema fixes | When API test shows schema errors |
| `TENANT_FOLDER_DIAGNOSTIC_GUIDE.md` | Complete documentation | Full reference guide |

---

## 🚀 Fastest Diagnosis (3 steps)

```bash
# Step 1: Open browser test
http://localhost/CollaboraNexio/test_tenant_folder_browser.html

# Step 2: If any test fails with "database" or "schema" error
# → Run fix_tenant_folder_issues.sql in MySQL

# Step 3: Refresh and re-test
# → Problem should be fixed
```

---

## 📊 What Gets Tested

### Frontend (Browser Test)
- ✅ Button exists and visible
- ✅ Modal exists with all elements
- ✅ Event listener attached
- ✅ CSRF token present
- ✅ FileManager instance created
- ✅ Modal opens/closes correctly
- ✅ Tenant list loads
- ✅ API endpoints reachable

### Backend (API Test)
- ✅ Session and authentication
- ✅ Required files exist
- ✅ Database connection
- ✅ Table schema correct
- ✅ User permissions
- ✅ Tenant data available
- ✅ API configuration
- ✅ JavaScript files intact

### Database (SQL Script)
- ✅ Adds missing columns
- ✅ Creates foreign keys
- ✅ Adds performance indexes
- ✅ Verifies data integrity
- ✅ Shows existing folders
- ✅ Lists admin users

---

## 🎯 Common Issues & Quick Fixes

| Issue | Quick Fix |
|-------|-----------|
| Button not visible | Check user role (must be admin/super_admin) |
| Modal doesn't open | Run browser test → check event listener |
| No tenants in dropdown | Run API test → check tenant data |
| "Schema error" message | Run `fix_tenant_folder_issues.sql` |
| "Permission denied" | Check user role and tenant access |
| "CSRF token invalid" | Refresh page |

---

## 📱 Usage by Role

### For System Administrators
1. Run browser test first
2. If fails, run API test
3. If schema issues, run SQL fix
4. Document results

### For Developers
1. Use console commands for debugging
2. Check JavaScript console for errors
3. Review API test details
4. Modify code as needed

### For End Users
1. Open browser test
2. Share results with admin
3. Don't modify database directly

---

## ✨ Test Results Interpretation

### All Tests PASS ✅
```
✓ Feature is working correctly
→ Issue may be user-specific
→ Check browser compatibility
→ Verify user has admin role
```

### Some Tests FAIL ❌
```
✗ Follow fix instructions shown in test
→ Most common: Database schema
→ Run SQL fix script
→ Re-test after fixes
```

### Tests Error Out 🔴
```
⚠ Configuration issue
→ Check file paths
→ Verify web server running
→ Check PHP/MySQL active
```

---

## 🔍 Diagnostic Flow Chart

```
User reports issue
    ↓
Run browser test (2 min)
    ↓
┌─────────────┬─────────────┐
│   ALL PASS  │  SOME FAIL  │
↓             ↓             ↓
Check user    Run API test  Check specific
role/perms    (1 min)       failure details
    ↓             ↓             ↓
If admin,     Schema error? Run console
deeper        → Run SQL     commands for
investigation   fix (2 min)  more details
                    ↓
                Re-test
                    ↓
                Problem
                solved! ✓
```

---

## 📋 Pre-Deployment Checklist

Before going live, verify:
- [ ] Browser test shows all PASS
- [ ] API test shows all PASS
- [ ] SQL fix script completed without errors
- [ ] At least one admin user exists
- [ ] At least one tenant exists in database
- [ ] User can actually create a test folder
- [ ] Created folder appears in file list
- [ ] No JavaScript errors in console
- [ ] No PHP errors in logs

---

## 🆘 Emergency Quick Fix

**Need it working NOW?**

1. Run this SQL (30 seconds):
   ```sql
   USE collabonexio;
   -- Copy entire fix_tenant_folder_issues.sql
   ```

2. Clear browser cache (Ctrl+Shift+Del)

3. Refresh files.php

4. Click "Cartella Tenant" button

**Total time: ~2 minutes**

If still broken → Run browser test to see specific issue

---

## 📞 Support

### Self-Service Diagnostics
1. Run browser test → Get visual results
2. Run API test → Get technical details
3. Check guide → Find solution

### If Tests Don't Help
- Check system logs
- Review recent changes
- Verify server configuration
- Contact technical support with test results

---

## 🔄 Regular Maintenance

**Run these tests:**
- ✅ After code updates
- ✅ After database changes
- ✅ Before production deployment
- ✅ When bugs reported
- ✅ Monthly health check

**Keep diagnostic tools updated:**
- Add new test cases as features evolve
- Update fix scripts for new schema
- Document new common issues

---

## 📚 Documentation Files

| File | Size | Purpose |
|------|------|---------|
| `DIAGNOSTIC_TOOLS_README.md` | Quick reference | This file - start here |
| `TENANT_FOLDER_DIAGNOSTIC_GUIDE.md` | Complete guide | Full documentation |
| `test_tenant_folder_browser.html` | Interactive test | Run in browser |
| `test_tenant_folder_api.php` | Backend test | JSON output |
| `DIAGNOSTIC_COMMANDS.txt` | Command reference | Console debugging |
| `fix_tenant_folder_issues.sql` | Schema fixes | Database repair |

---

## 🎓 Learning Path

**Beginner:**
1. Read this README
2. Run browser test
3. Run API test
4. Check results

**Intermediate:**
5. Read full guide
6. Use console commands
7. Understand test logic

**Advanced:**
8. Modify test cases
9. Add new diagnostics
10. Extend functionality

---

## ✅ Success Indicators

You know it's working when:
1. Browser test: All tests show ✓ PASS
2. API test: `"overall_status": "PASS"`
3. Button visible to admin users
4. Modal opens on click
5. Tenants populate dropdown
6. Folder creation succeeds
7. New folder appears in list
8. No console errors

---

## 🔐 Security Notes

**Safe to run:**
- ✅ Browser test (read-only, client-side)
- ✅ API test (read-only checks)
- ✅ SQL fix script (only adds missing items)
- ✅ Console commands (most are read-only)

**Be careful with:**
- ⚠️ Test #11 in console commands (dry run only)
- ⚠️ Running SQL as production user
- ⚠️ Sharing CSRF tokens

**Never:**
- ❌ Run tests on production without backup
- ❌ Share test results containing sensitive data
- ❌ Modify database without understanding changes

---

## 🎯 Target Audience

| User Type | Recommended Tools | Complexity |
|-----------|------------------|------------|
| End User | Browser test only | Easy ⭐ |
| Admin | Browser + API test | Medium ⭐⭐ |
| Developer | All tools + console | Advanced ⭐⭐⭐ |
| DBA | SQL fix + API test | Technical ⭐⭐⭐ |

---

## 📈 Version History

**v1.0.0** (2025-10-16)
- Initial release
- 4 diagnostic tools
- Complete documentation
- Automated testing

**Components:**
- Frontend: filemanager_enhanced.js
- Backend: files_tenant.php
- Database: files table schema
- Feature: Tenant folder creation

---

## 💡 Pro Tips

1. **Run browser test first** - It's visual and fast
2. **Save test results** - Screenshot for documentation
3. **Run tests in order** - Browser → API → SQL
4. **Keep tools updated** - As features change
5. **Document fixes** - Help future troubleshooting

---

## 🚨 Known Limitations

- Browser test requires JavaScript enabled
- API test requires active PHP session
- SQL fix requires database access
- Console commands need developer tools

---

## 🤝 Contributing

Found a bug in diagnostic tools?
1. Document the issue
2. Note which test failed
3. Include error messages
4. Suggest improvements

---

## 📄 License

These diagnostic tools are part of CollaboraNexio
For internal use and troubleshooting

---

**Last Updated:** 2025-10-16
**Compatible With:** CollaboraNexio v1.0+
**Requires:** PHP 7.4+, MySQL 5.7+, Modern Browser

---

## 🎉 Quick Win

**Most common fix (90% of issues):**

```sql
-- Just run this in MySQL
USE collabonexio;
SOURCE fix_tenant_folder_issues.sql;
```

Then refresh browser. Done! ✓

---

**Questions? Check the full guide: `TENANT_FOLDER_DIAGNOSTIC_GUIDE.md`**
