-- CollaboraNexio Migration 27
-- Purpose:
-- 1) Extend document_workflow_history.transition_type ENUM to support:
--    - create (workflow auto-created in bozza)
--    - auto_transition (validated -> in_approvazione automatic transition)
-- 2) Backfill missing history for existing document_workflow rows (append-only, idempotent)
--
-- Safety:
-- - Uses INFORMATION_SCHEMA to detect current enum values and only attempts safe ALTER.
-- - Backfill uses NOT EXISTS to avoid duplicates.
--
-- Date: 2025-12-28

SET @db := DATABASE();

-- ============================================================
-- 1) Ensure ENUM contains create + auto_transition
-- ============================================================

-- Read existing enum definition (COLUMN_TYPE like: enum('a','b',...))
SELECT COLUMN_TYPE
INTO @existing_enum
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'document_workflow_history'
  AND COLUMN_NAME = 'transition_type'
LIMIT 1;

-- If table/column missing, do nothing (migration is no-op)
SET @has_column := IF(@existing_enum IS NULL, 0, 1);

-- Detect missing values (we search for raw tokens to keep the script portable)
SET @needs_create := IF(@has_column = 1 AND LOCATE('create', @existing_enum) = 0, 1, 0);
SET @needs_auto := IF(@has_column = 1 AND LOCATE('auto_transition', @existing_enum) = 0, 1, 0);

-- Build a new enum definition by appending missing values at the end (preserves existing values/order)
-- We keep the original enum string as-is and inject additional values before the closing parenthesis.
SET @new_enum := @existing_enum;

-- Append 'create' if missing
SET @new_enum := IF(
  @needs_create = 1,
  REPLACE(@new_enum, ')', ',''create'')'),
  @new_enum
);

-- Append 'auto_transition' if missing
SET @new_enum := IF(
  @needs_auto = 1,
  REPLACE(@new_enum, ')', ',''auto_transition'')'),
  @new_enum
);

-- Execute ALTER only if needed. MySQL scripts can't use IF/THEN blocks outside routines,
-- so we always execute a prepared statement that is either the ALTER or a safe no-op.
SET @alter_sql := IF(
  @has_column = 1 AND (@needs_create = 1 OR @needs_auto = 1),
  CONCAT('ALTER TABLE document_workflow_history MODIFY COLUMN transition_type ', @new_enum, ' NOT NULL'),
  'SELECT 1'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2) Backfill: add a first history entry for workflows without history
-- ============================================================
-- Note: document_workflow_history is immutable; we append only when no rows exist.

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
  JSON_OBJECT('source', 'migration_27_backfill') AS metadata,
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


