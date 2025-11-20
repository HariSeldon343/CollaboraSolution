-- ============================================
-- WORKFLOW CONFIGURATION CHECK AND SETUP
-- ============================================
-- This script checks the workflow configuration and sets up test data
-- Execute with: mysql -u root -p collaboranexio < database/workflow_configuration_check.sql
-- ============================================

USE collaboranexio;

-- ============================================
-- 1. CHECK WORKFLOW TABLES EXIST
-- ============================================
SELECT '=== WORKFLOW TABLES CHECK ===' as section;

SELECT 
    'workflow_settings' as table_name,
    COUNT(*) as record_count,
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'EMPTY' END as status
FROM workflow_settings
UNION ALL
SELECT 
    'workflow_roles' as table_name,
    COUNT(*) as record_count,
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'EMPTY' END as status
FROM workflow_roles
UNION ALL
SELECT 
    'document_workflow' as table_name,
    COUNT(*) as record_count,
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'EMPTY' END as status
FROM document_workflow
UNION ALL
SELECT 
    'document_workflow_history' as table_name,
    COUNT(*) as record_count,
    CASE WHEN COUNT(*) > 0 THEN 'OK' ELSE 'EMPTY' END as status
FROM document_workflow_history;

-- ============================================
-- 2. CHECK WORKFLOW FUNCTION EXISTS
-- ============================================
SELECT '=== WORKFLOW FUNCTION CHECK ===' as section;

SELECT 
    ROUTINE_NAME,
    ROUTINE_TYPE,
    CREATED,
    LAST_ALTERED
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio'
  AND ROUTINE_NAME = 'get_workflow_enabled_for_folder';

-- ============================================
-- 3. CHECK CURRENT WORKFLOW SETTINGS
-- ============================================
SELECT '=== CURRENT WORKFLOW SETTINGS ===' as section;

SELECT 
    ws.id,
    ws.tenant_id,
    t.ragione_sociale as tenant_name,
    ws.scope_type,
    ws.folder_id,
    f.name as folder_name,
    ws.is_enabled,
    ws.override_parent,
    ws.created_at
FROM workflow_settings ws
LEFT JOIN tenants t ON ws.tenant_id = t.id
LEFT JOIN folders f ON ws.folder_id = f.id
WHERE ws.deleted_at IS NULL
ORDER BY ws.tenant_id, ws.scope_type, ws.folder_id;

-- ============================================
-- 4. CHECK WORKFLOW ROLES
-- ============================================
SELECT '=== WORKFLOW ROLES ===' as section;

SELECT 
    wr.tenant_id,
    t.ragione_sociale as tenant_name,
    wr.workflow_role,
    COUNT(DISTINCT wr.user_id) as user_count,
    GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') as users
FROM workflow_roles wr
JOIN users u ON wr.user_id = u.id
JOIN tenants t ON wr.tenant_id = t.id
WHERE wr.deleted_at IS NULL
  AND wr.is_active = 1
  AND u.deleted_at IS NULL
GROUP BY wr.tenant_id, t.ragione_sociale, wr.workflow_role
ORDER BY wr.tenant_id, wr.workflow_role;

-- ============================================
-- 5. CHECK FILES WITH WORKFLOW
-- ============================================
SELECT '=== FILES WITH ACTIVE WORKFLOW ===' as section;

SELECT 
    f.tenant_id,
    COUNT(*) as file_count,
    COUNT(DISTINCT dw.id) as workflow_count,
    SUM(CASE WHEN dw.current_state = 'bozza' THEN 1 ELSE 0 END) as bozza_count,
    SUM(CASE WHEN dw.current_state = 'in_validazione' THEN 1 ELSE 0 END) as in_validazione_count,
    SUM(CASE WHEN dw.current_state = 'validato' THEN 1 ELSE 0 END) as validato_count,
    SUM(CASE WHEN dw.current_state = 'in_approvazione' THEN 1 ELSE 0 END) as in_approvazione_count,
    SUM(CASE WHEN dw.current_state = 'approvato' THEN 1 ELSE 0 END) as approvato_count,
    SUM(CASE WHEN dw.current_state = 'rifiutato' THEN 1 ELSE 0 END) as rifiutato_count
FROM files f
LEFT JOIN document_workflow dw ON f.id = dw.file_id AND dw.deleted_at IS NULL
WHERE f.deleted_at IS NULL
  AND f.is_folder = 0
GROUP BY f.tenant_id;

-- ============================================
-- 6. SETUP DEFAULT WORKFLOW FOR TENANT 1
-- ============================================
SELECT '=== SETUP DEFAULT WORKFLOW FOR TENANT 1 ===' as section;

-- Enable workflow for entire tenant 1 (if not already enabled)
INSERT INTO workflow_settings (
    tenant_id, 
    scope_type, 
    folder_id, 
    is_enabled, 
    override_parent, 
    configured_by, 
    created_at
)
SELECT 
    1, 
    'tenant', 
    NULL, 
    1, 
    0, 
    1, 
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_settings 
    WHERE tenant_id = 1 
      AND scope_type = 'tenant' 
      AND deleted_at IS NULL
);

-- Add validators for tenant 1 (if none exist)
INSERT INTO workflow_roles (
    tenant_id,
    user_id,
    workflow_role,
    assigned_by_user_id,
    is_active,
    created_at
)
SELECT 
    1,
    u.id,
    'validator',
    1,
    1,
    NOW()
FROM users u
WHERE u.deleted_at IS NULL
  AND u.is_active = 1
  AND u.role IN ('manager', 'admin', 'super_admin')
  AND NOT EXISTS (
    SELECT 1 FROM workflow_roles wr
    WHERE wr.tenant_id = 1
      AND wr.user_id = u.id
      AND wr.workflow_role = 'validator'
      AND wr.deleted_at IS NULL
  )
LIMIT 2;

-- Add approvers for tenant 1 (if none exist)
INSERT INTO workflow_roles (
    tenant_id,
    user_id,
    workflow_role,
    assigned_by_user_id,
    is_active,
    created_at
)
SELECT 
    1,
    u.id,
    'approver',
    1,
    1,
    NOW()
FROM users u
WHERE u.deleted_at IS NULL
  AND u.is_active = 1
  AND u.role IN ('admin', 'super_admin')
  AND NOT EXISTS (
    SELECT 1 FROM workflow_roles wr
    WHERE wr.tenant_id = 1
      AND wr.user_id = u.id
      AND wr.workflow_role = 'approver'
      AND wr.deleted_at IS NULL
  )
LIMIT 2;

-- ============================================
-- 7. VERIFY CONFIGURATION
-- ============================================
SELECT '=== FINAL VERIFICATION ===' as section;

-- Check if workflow is enabled for tenant 1
SELECT 
    'Tenant 1 Workflow Status' as check_name,
    CASE 
        WHEN EXISTS (
            SELECT 1 FROM workflow_settings 
            WHERE tenant_id = 1 
              AND scope_type = 'tenant' 
              AND is_enabled = 1
              AND deleted_at IS NULL
        ) THEN 'ENABLED'
        ELSE 'DISABLED'
    END as status;

-- Check validators
SELECT 
    'Validators for Tenant 1' as role_type,
    COUNT(*) as count,
    GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') as users
FROM workflow_roles wr
JOIN users u ON wr.user_id = u.id
WHERE wr.tenant_id = 1
  AND wr.workflow_role = 'validator'
  AND wr.is_active = 1
  AND wr.deleted_at IS NULL
  AND u.deleted_at IS NULL;

-- Check approvers
SELECT 
    'Approvers for Tenant 1' as role_type,
    COUNT(*) as count,
    GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') as users
FROM workflow_roles wr
JOIN users u ON wr.user_id = u.id
WHERE wr.tenant_id = 1
  AND wr.workflow_role = 'approver'
  AND wr.is_active = 1
  AND wr.deleted_at IS NULL
  AND u.deleted_at IS NULL;

-- Test workflow function
SELECT 
    'Workflow Function Test' as test_name,
    get_workflow_enabled_for_folder(1, NULL) as result,
    'Expected: 1 (enabled)' as expected;

-- ============================================
-- 8. EMAIL CONFIGURATION CHECK
-- ============================================
SELECT '=== EMAIL CONFIGURATION CHECK ===' as section;

-- Check if email settings exist in system_settings
SELECT 
    setting_key,
    setting_value,
    updated_at
FROM system_settings
WHERE setting_key LIKE 'email_%'
ORDER BY setting_key;

-- ============================================
-- END OF SCRIPT
-- ============================================
SELECT '=== WORKFLOW CONFIGURATION COMPLETE ===' as status;
