-- CollaboraNexio - DB Integrity Audit (diagnostics)
-- Generated: 2025-12-28
--
-- Goals:
-- - Schema inventory: tables, columns, PK/UK/IDX, declared FKs
-- - Deterministic integrity checks (full scans via COUNT(*))
-- - Tenant consistency checks (tenant_id alignment across related tables)
--
-- Usage (MariaDB/MySQL):
--   USE collaboranexio;
--   SOURCE database/diagnostics/db_integrity_checks.sql;

/* =========================================================
 * 0) Environment
 * ========================================================= */
SELECT
  DATABASE()             AS db_name,
  @@version              AS db_version,
  @@version_comment      AS version_comment,
  NOW()                  AS run_at;

/* =========================================================
 * 1) Tables inventory
 * ========================================================= */
SELECT
  t.TABLE_NAME,
  t.ENGINE,
  t.TABLE_ROWS,
  ROUND((t.DATA_LENGTH + t.INDEX_LENGTH) / 1024 / 1024, 2) AS approx_size_mb
FROM information_schema.TABLES t
WHERE t.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_TYPE = 'BASE TABLE'
ORDER BY (t.DATA_LENGTH + t.INDEX_LENGTH) DESC, t.TABLE_NAME ASC;

/* =========================================================
 * 2) Exact row counts (full scan)
 * =========================================================
 * Note: This section is intentionally verbose: it performs COUNT(*)
 * on key tables. Add/remove tables as needed.
 */
SELECT 'activity_logs' AS table_name, COUNT(*) AS row_count FROM activity_logs;
SELECT 'approval_notifications' AS table_name, COUNT(*) AS row_count FROM approval_notifications;
SELECT 'audit_logs' AS table_name, COUNT(*) AS row_count FROM audit_logs;
SELECT 'audit_log_deletions' AS table_name, COUNT(*) AS row_count FROM audit_log_deletions;
SELECT 'calendars' AS table_name, COUNT(*) AS row_count FROM calendars;
SELECT 'calendar_permissions' AS table_name, COUNT(*) AS row_count FROM calendar_permissions;
SELECT 'calendar_shares' AS table_name, COUNT(*) AS row_count FROM calendar_shares;
SELECT 'chat_channels' AS table_name, COUNT(*) AS row_count FROM chat_channels;
SELECT 'chat_channel_members' AS table_name, COUNT(*) AS row_count FROM chat_channel_members;
SELECT 'chat_messages' AS table_name, COUNT(*) AS row_count FROM chat_messages;
SELECT 'document_approvals' AS table_name, COUNT(*) AS row_count FROM document_approvals;
SELECT 'document_editor_config' AS table_name, COUNT(*) AS row_count FROM document_editor_config;
SELECT 'document_editor_sessions' AS table_name, COUNT(*) AS row_count FROM document_editor_sessions;
SELECT 'document_workflow' AS table_name, COUNT(*) AS row_count FROM document_workflow;
SELECT 'document_workflow_history' AS table_name, COUNT(*) AS row_count FROM document_workflow_history;
SELECT 'events' AS table_name, COUNT(*) AS row_count FROM events;
SELECT 'event_attendees' AS table_name, COUNT(*) AS row_count FROM event_attendees;
SELECT 'event_participants' AS table_name, COUNT(*) AS row_count FROM event_participants;
SELECT 'event_reminders' AS table_name, COUNT(*) AS row_count FROM event_reminders;
SELECT 'files' AS table_name, COUNT(*) AS row_count FROM files;
SELECT 'file_versions' AS table_name, COUNT(*) AS row_count FROM file_versions;
SELECT 'folders' AS table_name, COUNT(*) AS row_count FROM folders;
SELECT 'folder_zip_download_requests' AS table_name, COUNT(*) AS row_count FROM folder_zip_download_requests;
SELECT 'italian_municipalities' AS table_name, COUNT(*) AS row_count FROM italian_municipalities;
SELECT 'italian_provinces' AS table_name, COUNT(*) AS row_count FROM italian_provinces;
SELECT 'migration_history' AS table_name, COUNT(*) AS row_count FROM migration_history;
SELECT 'notifications' AS table_name, COUNT(*) AS row_count FROM notifications;
SELECT 'page_visibility_settings' AS table_name, COUNT(*) AS row_count FROM page_visibility_settings;
SELECT 'projects' AS table_name, COUNT(*) AS row_count FROM projects;
SELECT 'project_members' AS table_name, COUNT(*) AS row_count FROM project_members;
SELECT 'project_milestones' AS table_name, COUNT(*) AS row_count FROM project_milestones;
SELECT 'rate_limits' AS table_name, COUNT(*) AS row_count FROM rate_limits;
SELECT 'sessions' AS table_name, COUNT(*) AS row_count FROM sessions;
SELECT 'shift_change_requests' AS table_name, COUNT(*) AS row_count FROM shift_change_requests;
SELECT 'shift_types' AS table_name, COUNT(*) AS row_count FROM shift_types;
SELECT 'system_settings' AS table_name, COUNT(*) AS row_count FROM system_settings;
SELECT 'tasks' AS table_name, COUNT(*) AS row_count FROM tasks;
SELECT 'task_assignments' AS table_name, COUNT(*) AS row_count FROM task_assignments;
SELECT 'task_history' AS table_name, COUNT(*) AS row_count FROM task_history;
SELECT 'task_notifications' AS table_name, COUNT(*) AS row_count FROM task_notifications;
SELECT 'tenants' AS table_name, COUNT(*) AS row_count FROM tenants;
SELECT 'tenant_locations' AS table_name, COUNT(*) AS row_count FROM tenant_locations;
SELECT 'tenant_roles' AS table_name, COUNT(*) AS row_count FROM tenant_roles;
SELECT 'tickets' AS table_name, COUNT(*) AS row_count FROM tickets;
SELECT 'ticket_attachments' AS table_name, COUNT(*) AS row_count FROM ticket_attachments;
SELECT 'ticket_history' AS table_name, COUNT(*) AS row_count FROM ticket_history;
SELECT 'users' AS table_name, COUNT(*) AS row_count FROM users;
SELECT 'user_notification_preferences' AS table_name, COUNT(*) AS row_count FROM user_notification_preferences;
SELECT 'user_tenant_access' AS table_name, COUNT(*) AS row_count FROM user_tenant_access;
SELECT 'workflow_roles' AS table_name, COUNT(*) AS row_count FROM workflow_roles;
SELECT 'workflow_settings' AS table_name, COUNT(*) AS row_count FROM workflow_settings;
SELECT 'work_shifts' AS table_name, COUNT(*) AS row_count FROM work_shifts;

/* =========================================================
 * 3) Primary keys
 * ========================================================= */
SELECT
  k.TABLE_NAME,
  k.COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE k
WHERE k.TABLE_SCHEMA = DATABASE()
  AND k.CONSTRAINT_NAME = 'PRIMARY'
ORDER BY k.TABLE_NAME, k.ORDINAL_POSITION;

/* =========================================================
 * 4) Indexes (PK/UK/IDX)
 * ========================================================= */
SELECT
  s.TABLE_NAME,
  s.INDEX_NAME,
  s.NON_UNIQUE,
  GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX SEPARATOR ', ') AS columns
FROM information_schema.STATISTICS s
WHERE s.TABLE_SCHEMA = DATABASE()
GROUP BY s.TABLE_NAME, s.INDEX_NAME, s.NON_UNIQUE
ORDER BY s.TABLE_NAME, s.NON_UNIQUE ASC, s.INDEX_NAME ASC;

/* =========================================================
 * 5) Declared foreign keys
 * ========================================================= */
SELECT
  k.TABLE_NAME,
  k.CONSTRAINT_NAME,
  k.COLUMN_NAME,
  k.REFERENCED_TABLE_NAME,
  k.REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE k
WHERE k.TABLE_SCHEMA = DATABASE()
  AND k.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME;

/* =========================================================
 * 6) FK type mismatches (should be 0 rows)
 * ========================================================= */
SELECT
  k.TABLE_NAME,
  k.COLUMN_NAME,
  c.COLUMN_TYPE AS child_type,
  k.REFERENCED_TABLE_NAME,
  k.REFERENCED_COLUMN_NAME,
  rc.COLUMN_TYPE AS parent_type
FROM information_schema.KEY_COLUMN_USAGE k
JOIN information_schema.COLUMNS c
  ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
 AND c.TABLE_NAME = k.TABLE_NAME
 AND c.COLUMN_NAME = k.COLUMN_NAME
JOIN information_schema.COLUMNS rc
  ON rc.TABLE_SCHEMA = k.TABLE_SCHEMA
 AND rc.TABLE_NAME = k.REFERENCED_TABLE_NAME
 AND rc.COLUMN_NAME = k.REFERENCED_COLUMN_NAME
WHERE k.TABLE_SCHEMA = DATABASE()
  AND k.REFERENCED_TABLE_NAME IS NOT NULL
  AND c.COLUMN_TYPE <> rc.COLUMN_TYPE
ORDER BY k.TABLE_NAME, k.COLUMN_NAME;

/* =========================================================
 * 7) Orphan checks (should be 0 rows; FKs may already enforce this)
 * ========================================================= */
SELECT 'orphan_users_tenant' AS check_name, COUNT(*) AS violations
FROM users u
LEFT JOIN tenants t ON t.id = u.tenant_id
WHERE t.id IS NULL;

SELECT 'orphan_tickets_tenant' AS check_name, COUNT(*) AS violations
FROM tickets x
LEFT JOIN tenants t ON t.id = x.tenant_id
WHERE t.id IS NULL;

SELECT 'orphan_tasks_tenant' AS check_name, COUNT(*) AS violations
FROM tasks x
LEFT JOIN tenants t ON t.id = x.tenant_id
WHERE t.id IS NULL;

SELECT 'orphan_events_tenant' AS check_name, COUNT(*) AS violations
FROM events x
LEFT JOIN tenants t ON t.id = x.tenant_id
WHERE t.id IS NULL;

SELECT 'orphan_files_tenant' AS check_name, COUNT(*) AS violations
FROM files x
LEFT JOIN tenants t ON t.id = x.tenant_id
WHERE x.tenant_id IS NOT NULL AND t.id IS NULL;

/* =========================================================
 * 8) Tenant consistency checks (row-level)
 * ========================================================= */
SELECT 'tickets_created_by_tenant_mismatch' AS check_name, COUNT(*) AS violations
FROM tickets t
JOIN users u ON u.id = t.created_by
WHERE u.tenant_id <> t.tenant_id;

SELECT 'tasks_created_by_tenant_mismatch' AS check_name, COUNT(*) AS violations
FROM tasks x
JOIN users u ON u.id = x.created_by
WHERE x.created_by IS NOT NULL AND u.tenant_id <> x.tenant_id;

SELECT 'tasks_assigned_to_tenant_mismatch' AS check_name, COUNT(*) AS violations
FROM tasks x
JOIN users u ON u.id = x.assigned_to
WHERE x.assigned_to IS NOT NULL AND u.tenant_id <> x.tenant_id;

SELECT 'events_organizer_tenant_mismatch' AS check_name, COUNT(*) AS violations
FROM events e
JOIN users u ON u.id = e.organizer_id
WHERE u.tenant_id <> e.tenant_id;

SELECT 'events_calendar_tenant_mismatch' AS check_name, COUNT(*) AS violations
FROM events e
JOIN calendars c ON c.id = e.calendar_id
WHERE e.calendar_id IS NOT NULL AND c.tenant_id <> e.tenant_id;

SELECT 'calendars_owner_tenant_mismatch' AS check_name, COUNT(*) AS violations
FROM calendars c
JOIN users u ON u.id = c.owner_id
WHERE u.tenant_id <> c.tenant_id;

/* =========================================================
 * 9) Page Visibility settings coverage (turni should appear 3x globally)
 * ========================================================= */
SELECT
  page_name,
  COUNT(*) AS rows_per_page
FROM page_visibility_settings
WHERE tenant_id IS NULL AND deleted_at IS NULL
GROUP BY page_name
ORDER BY page_name;

SELECT
  'page_visibility_missing_turni' AS check_name,
  CASE
    WHEN EXISTS (
      SELECT 1
      FROM page_visibility_settings
      WHERE tenant_id IS NULL
        AND deleted_at IS NULL
        AND page_name = 'turni'
      LIMIT 1
    ) THEN 0
    ELSE 1
  END AS violations;

