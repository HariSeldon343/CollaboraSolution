# Email Notifications Testing Guide

## Overview

This guide provides comprehensive testing procedures for the Document Approval Workflow and File Assignment email notification system integrated into CollaboraNexio.

---

## Prerequisites

### 1. Email Configuration

Ensure email configuration is properly set up in `/includes/config_email.php`:

```php
define('EMAIL_SMTP_HOST', 'smtp.gmail.com');  // Or your SMTP server
define('EMAIL_SMTP_PORT', 587);
define('EMAIL_SMTP_USERNAME', 'your-email@example.com');
define('EMAIL_SMTP_PASSWORD', 'your-app-password');
define('EMAIL_FROM_EMAIL', 'noreply@collaboranexio.com');
define('EMAIL_FROM_NAME', 'CollaboraNexio');
define('EMAIL_DEBUG_MODE', true);  // Enable for testing
```

### 2. Database Migration

Run the migration to add expiration warning columns:

```bash
mysql -u root -p collaboranexio < database/migrations/add_assignment_expiration_warning_flag.sql
```

### 3. Configure Workflow Roles

Ensure validators and approvers are configured for your tenant:

```sql
-- Add test validator
INSERT INTO workflow_roles (tenant_id, user_id, workflow_role, created_at)
VALUES (1, 2, 'validator', NOW());

-- Add test approver
INSERT INTO workflow_roles (tenant_id, user_id, workflow_role, created_at)
VALUES (1, 3, 'approver', NOW());
```

---

## Email Notification Types

### Document Workflow Emails (5 types)

1. **Document Submitted for Validation**
   - Trigger: Document state changes from `bozza` to `in_validazione`
   - Recipients: All validators

2. **Document Validated**
   - Trigger: Document state changes from `in_validazione` to `in_approvazione`
   - Recipients: All approvers + creator (FYI)

3. **Document Approved**
   - Trigger: Document state changes to `approvato`
   - Recipients: Creator + all validators + all approvers

4. **Document Rejected (Validation)**
   - Trigger: Document rejected from `in_validazione` state
   - Recipients: Creator only

5. **Document Rejected (Approval)**
   - Trigger: Document rejected from `in_approvazione` state
   - Recipients: Creator + all validators (FYI)

### Assignment Emails (2 types)

6. **File/Folder Assigned**
   - Trigger: New assignment created
   - Recipients: Assigned user

7. **Assignment Expiring Soon**
   - Trigger: Cron job (7 days before expiration)
   - Recipients: Assigned user + assigner

---

## Testing Procedures

### Test 1: Document Submission Email

**Steps:**
1. Login as a document creator
2. Upload a new document (.docx, .pdf, etc.)
3. Click "Invia per Validazione" button
4. Select validator and approver
5. Add notes and submit

**Expected Result:**
- Email sent to all validators
- Subject: "Nuovo documento da validare: [filename]"
- Content includes document name, creator, submission date, link

**Verification:**
```bash
# Check email logs
tail -f logs/mailer_error.log | grep "workflow_document_submitted"
```

### Test 2: Document Validation Email

**Steps:**
1. Login as a validator
2. Navigate to document in validation
3. Click "Valida" button
4. Add optional comment
5. Submit validation

**Expected Result:**
- Email sent to all approvers + creator
- Subject: "Documento validato e in attesa di approvazione: [filename]"
- Content includes validator name, validation date, comment (if any)

### Test 3: Document Approval Email

**Steps:**
1. Login as an approver
2. Navigate to validated document
3. Click "Approva" button
4. Add optional comment
5. Submit approval

**Expected Result:**
- Email sent to creator + validators + approvers
- Subject: "Documento approvato: [filename]"
- Success message with green badge

### Test 4: Document Rejection Emails

**Test 4a: Rejection by Validator**

**Steps:**
1. Login as validator
2. Navigate to document in validation
3. Click "Rifiuta" button
4. Enter rejection reason (minimum 20 characters)
5. Submit rejection

**Expected Result:**
- Email sent to creator only
- Subject: "Documento rifiutato: [filename]"
- Red rejection badge and reason displayed

**Test 4b: Rejection by Approver**

**Steps:**
1. Login as approver
2. Navigate to document in approval
3. Click "Rifiuta" button
4. Enter rejection reason
5. Submit rejection

**Expected Result:**
- Email sent to creator + all validators
- Subject: "Documento rifiutato in fase di approvazione: [filename]"

### Test 5: File Assignment Email

**Steps:**
1. Login as manager/admin
2. Navigate to Files page
3. Right-click on a file/folder
4. Select "Assegna"
5. Select user, add reason, set expiration date
6. Submit assignment

**Expected Result:**
- Email sent to assigned user
- Subject: "Ti è stato assegnato un file/cartella: [name]"
- Orange lock badge appears on file

### Test 6: Assignment Expiration Warning

**Manual Test (without waiting 7 days):**

```sql
-- Create test assignment expiring in 7 days
INSERT INTO file_assignments (
    tenant_id, file_id, user_id, assigned_by,
    reason, expires_at, created_at
) VALUES (
    1, 1, 2, 1,
    'Test expiration warning',
    DATE_ADD(NOW(), INTERVAL 7 DAY),
    NOW()
);

-- Run cron job manually
php cron/check_assignment_expirations.php
```

**Expected Result:**
- Email sent to assignee + assigner
- Subject: "Assegnazione in scadenza: [name]"
- Warning shows "7 giorni rimanenti"
- `expiration_warning_sent` flag updated to 1

---

## Email Template Verification

### Visual Testing

All email templates should be tested for:

1. **Desktop Rendering**
   - Open email in desktop client (Outlook, Gmail, Thunderbird)
   - Verify layout, colors, buttons

2. **Mobile Rendering**
   - Open email on mobile device
   - Verify responsive design
   - Test button click areas

3. **Content Accuracy**
   - All placeholders replaced correctly
   - Italian language correct
   - No broken variables ({{VARIABLE}})
   - Links work correctly

### Template Files Location

```
/includes/email_templates/workflow/
├── document_submitted.html
├── document_validated.html
├── document_approved.html
├── document_rejected_validation.html
├── document_rejected_approval.html
├── file_assigned.html
└── assignment_expiring.html
```

---

## Debugging Email Issues

### 1. Enable Debug Mode

In `/includes/config_email.php`:
```php
define('EMAIL_DEBUG_MODE', true);
```

### 2. Check Logs

```bash
# Email logs
tail -f logs/mailer_error.log

# PHP errors
tail -f logs/php_errors.log

# Workflow specific
grep "WORKFLOW_" logs/php_errors.log
```

### 3. Test SMTP Connection

Create test script `test_smtp.php`:

```php
<?php
require_once 'includes/mailer.php';

$result = sendEmail(
    'test@example.com',
    'Test Subject',
    '<h1>Test Email</h1><p>This is a test.</p>',
    'This is a test.',
    ['context' => ['action' => 'test']]
);

echo $result ? "Email sent successfully!" : "Email failed!";
```

### 4. Common Issues

**Issue: Emails not sending**
- Check SMTP credentials
- Verify firewall allows port 587/465
- Check PHP mail() function enabled
- Verify sender email is allowed by SMTP server

**Issue: Templates showing placeholders**
- Check variable names in WorkflowEmailNotifier.php
- Verify template has all required placeholders
- Check for typos in placeholder names

**Issue: Wrong recipients**
- Verify workflow_roles table has correct users
- Check user email addresses are valid
- Verify tenant_id filtering

---

## Cron Job Setup

### Linux/Unix

Add to crontab (`crontab -e`):

```bash
# Run daily at 8:00 AM
0 8 * * * /usr/bin/php /path/to/CollaboraNexio/cron/check_assignment_expirations.php >> /var/log/collaboranexio_cron.log 2>&1
```

### Windows (XAMPP)

Create batch file `assignment_expiration.bat`:

```batch
@echo off
C:\xampp\php\php.exe C:\xampp\htdocs\CollaboraNexio\cron\check_assignment_expirations.php
```

Add to Windows Task Scheduler:
1. Open Task Scheduler
2. Create Basic Task
3. Set trigger: Daily at 8:00 AM
4. Set action: Start program (assignment_expiration.bat)

---

## Production Checklist

Before going to production:

- [ ] Disable EMAIL_DEBUG_MODE
- [ ] Configure production SMTP server
- [ ] Test all 7 email types
- [ ] Verify cron job scheduled
- [ ] Check email logs for errors
- [ ] Verify spam folder settings
- [ ] Test with real user email addresses
- [ ] Verify multi-tenant isolation
- [ ] Check email rate limits
- [ ] Configure email bounce handling

---

## Monitoring

### Daily Checks
- Review cron job execution logs
- Check for failed email sends
- Monitor bounce rates

### Weekly Checks
- Review assignment expiration patterns
- Check workflow completion rates
- Audit email delivery statistics

### SQL Queries for Monitoring

```sql
-- Check pending expirations
SELECT COUNT(*) as pending_warnings
FROM file_assignments
WHERE expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
  AND expiration_warning_sent = 0
  AND deleted_at IS NULL;

-- Check email send rate (requires audit_logs)
SELECT DATE(created_at) as date, COUNT(*) as emails_sent
FROM audit_logs
WHERE action = 'email_sent'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at);

-- Check workflow email statistics
SELECT
    entity_type,
    COUNT(*) as count
FROM audit_logs
WHERE action = 'email_sent'
  AND entity_type = 'notification'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY entity_type;
```

---

## Support

For issues or questions:
1. Check logs in `/logs/` directory
2. Review error messages in browser console
3. Verify database migrations applied
4. Test SMTP connectivity
5. Contact system administrator

---

**Last Updated:** 2025-10-29
**Version:** 1.0.0
**Author:** CollaboraNexio Development Team