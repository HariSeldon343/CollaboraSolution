# Frontend Testing Guide - File Assignment & Workflow System

## Overview

This guide provides testing instructions for the newly implemented frontend UI components:
1. **File/Folder Assignment System** - Manage file/folder access permissions
2. **Document Workflow Management** - Multi-stage document approval process
3. **Dashboard Widget** - Workflow statistics and quick actions

## Files Created

### JavaScript Files
- `/assets/js/file_assignment.js` (665 lines) - File assignment management
- `/assets/js/document_workflow.js` (862 lines) - Workflow management system
- `/assets/js/workflow_dashboard_widget.js` (502 lines) - Dashboard widget

### CSS Files
- `/assets/css/workflow.css` (774 lines) - Complete styling for workflow components

### Modified Files
- `/files.php` - Added new JS/CSS includes, CSRF token meta tag, hidden fields

## Prerequisites

1. **Backend APIs Must Be Working**: All 14 API endpoints from progression.md must be functional
2. **User Roles**: Test with different user roles (user, manager, admin, super_admin)
3. **Browser**: Use modern browser with JavaScript console open for debugging
4. **Clear Cache**: Clear browser cache before testing (CTRL+SHIFT+Delete)

## Test Cases

### 1. File Assignment System

#### Test 1.1: Assignment Creation (Manager/Admin only)
1. Login as manager or admin
2. Navigate to `/CollaboraNexio/files.php`
3. Right-click on any file or folder
4. Look for "👤 Assegna" option in context menu
5. Click it to open assignment modal
6. Select a user from dropdown
7. Optionally add reason and expiration date
8. Click "Assegna"
9. **Expected**: Success toast, assignment indicator appears on file

#### Test 1.2: Assignment Indicators
1. Files/folders with assignments should show "🔒 Assegnato" badge
2. Badge should be orange color (#f59e0b)
3. Hovering over badge should show tooltip

#### Test 1.3: View Assignments
1. Right-click on assigned file/folder
2. Select "📋 Visualizza Assegnazioni"
3. **Expected**: Modal opens showing all assignments
4. Verify table shows: User, Assigned By, Date, Expiration, Reason, Actions

#### Test 1.4: Revoke Assignment
1. In assignments list modal, click "Revoca" button
2. Confirm the action
3. **Expected**: Assignment removed, success toast shown

#### Test 1.5: Access Control
1. Login as user without assignment
2. Try to access assigned file
3. **Expected**: Access denied message

### 2. Document Workflow System

#### Test 2.1: Workflow Status Badge
1. Navigate to files page
2. Documents in workflow should show colored badges:
   - 🟦 Bozza (Blue #3b82f6)
   - 🟨 In Validazione (Yellow #eab308)
   - 🟩 Validato (Light Green #22c55e)
   - 🟧 In Approvazione (Orange #f97316)
   - ✅ Approvato (Dark Green #10b981)
   - ❌ Rifiutato (Red #ef4444)
3. Click on badge to open history modal

#### Test 2.2: Submit for Validation (Creator)
1. Right-click on document in "bozza" state
2. Select "📤 Invia in Validazione"
3. Add optional comment
4. Click "Invia in Validazione"
5. **Expected**: Document moves to "in_validazione" state

#### Test 2.3: Validate Document (Validator)
1. Login as validator role
2. Find document in "in_validazione" state
3. Right-click and select "✓ Valida"
4. Add optional comment
5. **Expected**: Document moves to "in_approvazione" state

#### Test 2.4: Reject Document (Validator/Approver)
1. Find document in validation/approval state
2. Right-click and select "❌ Rifiuta"
3. Enter mandatory comment (min 20 chars)
4. **Expected**: Document returns to "bozza" state

#### Test 2.5: Approve Document (Approver)
1. Login as approver role
2. Find document in "in_approvazione" state
3. Right-click and select "✅ Approva"
4. **Expected**: Document moves to "approvato" state

#### Test 2.6: Recall Document (Creator)
1. As document creator, right-click on document
2. Select "↩️ Richiama"
3. **Expected**: Document returns to "bozza" state

#### Test 2.7: Workflow History
1. Click on any workflow badge or select "📋 Storico Workflow"
2. **Expected**: Timeline modal opens showing:
   - All state transitions
   - User names and roles
   - Timestamps
   - Comments
   - IP addresses

#### Test 2.8: Role Configuration (Manager/Admin)
1. Look for "⚙️ Configura Workflow" button in toolbar
2. Click to open configuration modal
3. Select users for validator roles
4. Select users for approver roles
5. Save changes
6. **Expected**: Roles updated, success toast

### 3. Dashboard Widget

#### Test 3.1: Widget Display
1. Navigate to dashboard page
2. Look for "📋 Workflow Documenti" widget
3. **Expected**: Widget shows three statistics:
   - In attesa di validazione
   - In attesa di approvazione
   - I miei documenti in workflow

#### Test 3.2: Click Statistics
1. Click on any statistic card
2. **Expected**: Redirects to files page with appropriate filter

#### Test 3.3: Refresh Button
1. Click 🔄 refresh button in widget header
2. **Expected**: Button rotates, data refreshes

#### Test 3.4: Recent Activity
1. If there's recent workflow activity
2. **Expected**: Shows last 5 activities with time ago

### 4. Error Handling

#### Test 4.1: Network Error
1. Disable network/API
2. Try any workflow action
3. **Expected**: Error toast with friendly message

#### Test 4.2: Permission Denied
1. Login as regular user
2. Try to access manager-only features
3. **Expected**: Features hidden or disabled

#### Test 4.3: Validation Errors
1. Try to reject without comment
2. **Expected**: Error message about minimum 20 chars
3. Try to set past expiration date
4. **Expected**: Date picker prevents selection

### 5. CSRF Token Verification

#### Test 5.1: Check Headers
1. Open browser DevTools → Network tab
2. Perform any action (assign, workflow action, etc.)
3. Check request headers
4. **Expected**: `X-CSRF-Token` header present in all requests

### 6. Responsive Design

#### Test 6.1: Mobile View
1. Open browser responsive mode (F12)
2. Set to mobile dimensions (375px width)
3. Test all modals and UI components
4. **Expected**: All modals responsive, buttons accessible

#### Test 6.2: Tablet View
1. Set to tablet dimensions (768px width)
2. Test workflow timeline and assignment table
3. **Expected**: Proper layout adjustments

## Console Commands for Testing

Open browser console and run:

```javascript
// Check if managers are loaded
console.log('File Assignment Manager:', window.fileAssignmentManager);
console.log('Users loaded:', window.fileAssignmentManager?.state.users);

// Check workflow manager
console.log('Workflow Manager:', window.workflowManager);
console.log('Validators:', window.workflowManager?.state.validators);
console.log('Approvers:', window.workflowManager?.state.approvers);

// Check CSRF token
console.log('CSRF Token:', document.querySelector('meta[name="csrf-token"]')?.content);

// Trigger assignment modal manually
window.fileAssignmentManager?.showAssignmentModal(1, null, 'Test File');

// Trigger workflow action modal
window.workflowManager?.showActionModal('submit', 1, 'Test Document');

// Check dashboard widget
console.log('Dashboard Widget:', window.workflowWidget);
console.log('Stats:', window.workflowWidget?.state.stats);
```

## Common Issues & Solutions

### Issue 1: Modals Not Opening
- **Solution**: Check console for JavaScript errors
- Ensure fileManager is initialized before assignment/workflow managers
- Clear browser cache

### Issue 2: 403 Forbidden Errors
- **Solution**: Check CSRF token is present in meta tag
- Verify X-CSRF-Token header in requests
- Check user authentication

### Issue 3: Empty Dropdowns
- **Solution**: Verify API endpoints are working
- Check user has proper permissions
- Verify tenant users exist

### Issue 4: Assignments/Workflow Not Visible
- **Solution**: Check user role (manager/admin required for some features)
- Verify database has workflow_roles configured
- Check file has workflow state in database

### Issue 5: Toast Notifications Not Showing
- **Solution**: Check z-index conflicts with other elements
- Verify toast container is appended to body
- Check console for errors

## API Dependencies

The frontend requires these API endpoints to be functional:

### File Assignment APIs
- `POST /api/files/assign.php` - Create/revoke assignments
- `GET /api/files/assignments.php` - List assignments
- `GET /api/files/check-access.php` - Check access permissions

### Workflow APIs
- `POST /api/documents/workflow/submit.php` - Submit for validation
- `POST /api/documents/workflow/validate.php` - Validate document
- `POST /api/documents/workflow/reject.php` - Reject document
- `POST /api/documents/workflow/approve.php` - Approve document
- `POST /api/documents/workflow/recall.php` - Recall document
- `GET /api/documents/workflow/history.php` - Get history
- `GET /api/documents/workflow/status.php` - Get current status
- `GET /api/documents/workflow/dashboard.php` - Dashboard stats

### Role Configuration APIs
- `POST /api/workflow/roles/create.php` - Assign roles
- `GET /api/workflow/roles/list.php` - List validators/approvers

## Performance Metrics

Expected performance benchmarks:
- Modal open: < 100ms
- API calls: < 500ms
- UI updates: < 50ms
- Auto-refresh: Every 30s (workflow), 60s (dashboard)

## Browser Compatibility

Tested and supported on:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

Not supported:
- Internet Explorer (any version)
- Browsers without ES6 support

## Security Considerations

All implementations follow CollaboraNexio security patterns:
- ✅ CSRF token validation (BUG-043)
- ✅ Multi-tenant isolation
- ✅ Role-based access control
- ✅ Input validation and sanitization
- ✅ XSS prevention
- ✅ SQL injection prevention (backend)

## Next Steps

After successful testing:
1. Deploy to staging environment
2. Perform user acceptance testing
3. Create user training materials
4. Configure email notifications (hooks in place)
5. Monitor performance and usage
6. Gather user feedback for improvements

---

**Total Implementation:** ~2,800 lines of JavaScript, ~800 lines of CSS
**Testing Time Estimate:** 2-3 hours for complete test suite
**Production Ready:** After successful testing and backend integration