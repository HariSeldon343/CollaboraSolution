-- CollaboraNexio Migration 28
-- Purpose:
-- - Ensure every (non-folder) file has a document_workflow row in state 'bozza'
-- - Ensure every workflow has at least one immutable history row (transition_type='create')
--
-- Notes:
-- - This change is intentional: workflow objects become the canonical "file lifecycle" record,
--   even when approvals are not used.
-- - Inserts are idempotent via NOT EXISTS.
--
-- Date: 2025-12-28

SET @db := DATABASE();

-- ============================================================
-- 1) Create missing document_workflow rows for ALL files (not folders)
-- ============================================================
INSERT INTO document_workflow (
  tenant_id,
  file_id,
  current_state,
  created_by_user_id,
  current_handler_user_id,
  submitted_at,
  validated_at,
  approved_at,
  rejected_at,
  rejection_reason,
  rejection_count,
  deleted_at,
  created_at,
  updated_at
)
SELECT
  f.tenant_id,
  f.id AS file_id,
  'bozza' AS current_state,
  f.uploaded_by AS created_by_user_id,
  NULL AS current_handler_user_id,
  NULL AS submitted_at,
  NULL AS validated_at,
  NULL AS approved_at,
  NULL AS rejected_at,
  NULL AS rejection_reason,
  0 AS rejection_count,
  NULL AS deleted_at,
  COALESCE(f.created_at, NOW()) AS created_at,
  COALESCE(f.updated_at, COALESCE(f.created_at, NOW())) AS updated_at
FROM files f
WHERE (f.deleted_at IS NULL OR f.deleted_at = '')
  AND (f.is_folder IS NULL OR f.is_folder = 0)
  AND NOT EXISTS (
    SELECT 1
    FROM document_workflow dw
    WHERE dw.file_id = f.id
      AND dw.tenant_id = f.tenant_id
      AND (dw.deleted_at IS NULL OR dw.deleted_at = '')
    LIMIT 1
  );

-- ============================================================
-- 2) Backfill missing history 'create' for workflows without any history
-- ============================================================
INSERT INTO document_workflow_history (
  tenant_id,
  workflow_id,
  file_id,
  from_state,
  to_state,
  transition_type,
  performed_by_user_id,
  user_role_at_time,
  comment,
  metadata,
  ip_address,
  user_agent,
  created_at
)
SELECT
  dw.tenant_id,
  dw.id AS workflow_id,
  dw.file_id,
  NULL AS from_state,
  'bozza' AS to_state,
  'create' AS transition_type,
  dw.created_by_user_id AS performed_by_user_id,
  'creator' AS user_role_at_time,
  'Backfill: workflow creato (stato iniziale bozza)' AS comment,
  JSON_OBJECT('source', 'migration_28_backfill_all_files') AS metadata,
  NULL AS ip_address,
  NULL AS user_agent,
  dw.created_at AS created_at
FROM document_workflow dw
WHERE (dw.deleted_at IS NULL OR dw.deleted_at = '')
  AND NOT EXISTS (
    SELECT 1
    FROM document_workflow_history h
    WHERE h.workflow_id = dw.id
      AND h.tenant_id = dw.tenant_id
      AND h.file_id = dw.file_id
    LIMIT 1
  );


