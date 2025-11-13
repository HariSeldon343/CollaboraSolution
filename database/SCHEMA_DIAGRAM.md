# Document Editor Schema Diagram

## Entity Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                     COLLABORANEXIO DATABASE SCHEMA                       │
│                    Document Editor Integration (v006)                    │
└─────────────────────────────────────────────────────────────────────────┘

┌──────────────────┐
│     tenants      │
│==================│
│ id (PK)          │◄───────────────────────┐
│ name             │                        │
│ domain           │                        │
│ status           │                        │
│ created_at       │                        │
│ updated_at       │                        │
└──────────────────┘                        │
        ▲                                   │ ON DELETE CASCADE
        │                                   │
        │                                   │
┌───────┴──────────┐                        │
│      users       │                        │
│==================│                        │
│ id (PK)          │◄───────────────┐       │
│ tenant_id (FK)   │                │       │
│ email            │                │       │
│ first_name       │                │       │
│ last_name        │                │       │
│ role             │                │       │
│ deleted_at       │                │       │
│ created_at       │                │       │
│ updated_at       │                │       │
└──────────────────┘                │       │
        ▲                           │       │
        │                           │       │
        │                           │       │
┌───────┴──────────┐                │       │
│      files       │                │       │
│==================│                │       │
│ id (PK)          │◄───────────────┼───────┼───────────┐
│ tenant_id (FK)   │                │       │           │
│ name             │                │       │           │
│ mime_type        │                │       │           │
│ uploaded_by (FK) │────────────────┘       │           │
│                  │                        │           │
│ *** NEW ***      │                        │           │
│ is_editable      │  ← New column          │           │
│ editor_format    │  ← New column          │           │
│ last_edited_by   │  ← New column (FK)     │           │
│ last_edited_at   │  ← New column          │           │
│ editor_version   │  ← New column          │           │
│ is_locked        │  ← New column          │           │
│ checksum         │  ← New column          │           │
│                  │                        │           │
│ deleted_at       │                        │           │
│ created_at       │                        │           │
│ updated_at       │                        │           │
└──────────────────┘                        │           │
        ▲                                   │           │
        │                                   │           │
        │ file_id (FK)                      │           │
        │                                   │           │
┌───────┴────────────────────┐              │           │
│ document_editor_sessions   │ ← NEW TABLE  │           │
│============================│              │           │
│ id (PK)                    │              │           │
│ tenant_id (FK) ────────────┼──────────────┘           │
│ file_id (FK) ──────────────┘                          │
│ user_id (FK) ─────────────────────────────────────────┘
│ session_token (UNIQUE)     │
│ editor_key                 │
│ opened_at                  │
│ last_activity              │
│ closed_at                  │
│ changes_saved              │
│ is_collaborative           │
│ ip_address                 │
│ user_agent                 │
│ document_version           │
│ initial_checksum           │
│ deleted_at                 │
│ created_at                 │
│ updated_at                 │
└────────────────────────────┘
        ▲
        │
        │ session_id (FK)
        │
┌───────┴───────────────────┐
│ document_editor_locks     │ ← NEW TABLE
│===========================│
│ id (PK)                   │
│ tenant_id (FK) ───────────┼──────────────┐
│ file_id (FK) ─────────────┼──────┐       │
│ locked_by (FK) ───────────┼──┐   │       │
│ session_id (FK) ──────────┘  │   │       │
│ lock_token (UNIQUE)          │   │       │
│ lock_type                    │   │       │
│ locked_at                    │   │       │
│ expires_at                   │   │       │
│ lock_reason                  │   │       │
│ created_at                   │   │       │
└──────────────────────────────┘   │       │
        ▲                           │       │
        │                           │       │
        │                           │       │
┌───────┴───────────────────┐       │       │
│ document_editor_changes   │ ← NEW TABLE  │
│===========================│       │       │
│ id (PK)                   │       │       │
│ tenant_id (FK) ───────────┼───────┼───────┘
│ session_id (FK) ──────────┤       │
│ file_id (FK) ─────────────┼───────┘
│ user_id (FK) ─────────────┘
│ callback_status           │
│ document_url              │
│ changes_url               │
│ version_number            │
│ previous_version          │
│ change_summary            │
│ save_status               │
│ save_error                │
│ saved_at                  │
│ new_file_size             │
│ new_checksum              │
│ deleted_at                │
│ created_at                │
│ updated_at                │
└───────────────────────────┘
```

## Foreign Key Relationships

### CASCADE Rules

```
tenants (ON DELETE CASCADE)
  ├─→ users
  ├─→ files
  ├─→ document_editor_sessions
  ├─→ document_editor_locks
  └─→ document_editor_changes

files (ON DELETE CASCADE)
  ├─→ document_editor_sessions
  ├─→ document_editor_locks
  └─→ document_editor_changes

users (ON DELETE CASCADE/SET NULL)
  ├─→ document_editor_sessions (CASCADE)
  ├─→ document_editor_locks (CASCADE)
  ├─→ document_editor_changes (CASCADE)
  └─→ files.last_edited_by (SET NULL)

document_editor_sessions (ON DELETE CASCADE)
  ├─→ document_editor_locks
  └─→ document_editor_changes
```

## Index Strategy

### Multi-Tenant Indexes (Required Pattern)
```sql
-- All tables have these:
INDEX idx_[table]_tenant_created (tenant_id, created_at)
INDEX idx_[table]_tenant_deleted (tenant_id, deleted_at)
```

### Functional Indexes

#### document_editor_sessions
```
PRIMARY KEY (id)
UNIQUE INDEX uk_session_token (session_token)
INDEX idx_editor_key (editor_key)
INDEX idx_session_tenant_activity (tenant_id, last_activity)
INDEX idx_session_file (file_id, opened_at)
INDEX idx_session_user (user_id, opened_at)
INDEX idx_session_active (tenant_id, closed_at, deleted_at)
INDEX idx_session_concurrent (file_id, closed_at, deleted_at)
```

#### document_editor_locks
```
PRIMARY KEY (id)
UNIQUE INDEX uk_file_exclusive_lock (tenant_id, file_id, lock_type)
INDEX idx_lock_expires (expires_at)
INDEX idx_lock_token (lock_token)
INDEX idx_lock_user (locked_by)
```

#### document_editor_changes
```
PRIMARY KEY (id)
INDEX idx_change_tenant_created (tenant_id, created_at)
INDEX idx_change_tenant_deleted (tenant_id, deleted_at)
INDEX idx_change_session (session_id)
INDEX idx_change_file_version (file_id, version_number)
INDEX idx_change_status (save_status)
INDEX idx_change_callback (callback_status)
```

#### files (new indexes)
```
INDEX idx_files_editable (tenant_id, is_editable, mime_type, deleted_at)
INDEX idx_files_locked (tenant_id, is_locked, deleted_at)
```

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    DOCUMENT EDITING WORKFLOW                     │
└─────────────────────────────────────────────────────────────────┘

1. USER CLICKS "EDIT DOCUMENT"
   │
   ├─→ Check: is_file_editable(file_id, tenant_id)
   │    ├─ file exists? ✓
   │    ├─ not deleted? ✓
   │    ├─ not locked? ✓
   │    └─ mime_type supported? ✓
   │
2. OPEN EDITOR SESSION
   │
   ├─→ CALL open_editor_session(...)
   │    ├─ Generate session_token
   │    ├─ Generate editor_key
   │    ├─ Insert into document_editor_sessions
   │    ├─ Create exclusive lock in document_editor_locks
   │    └─ Set files.is_locked = TRUE
   │
3. USER EDITS IN ONLYOFFICE
   │
   ├─→ OnlyOffice loads document using editor_key
   ├─→ User makes changes
   └─→ OnlyOffice sends periodic callbacks
   │
4. ONLYOFFICE CALLBACK (Status 2 = Ready to Save)
   │
   ├─→ CALL record_document_change(...)
   │    ├─ Insert into document_editor_changes
   │    ├─ Set save_status = 'processing'
   │    └─ Update session.last_activity
   │
   ├─→ Download modified file from OnlyOffice
   ├─→ Save to storage
   ├─→ Update files table
   │    ├─ Increment editor_version
   │    ├─ Update checksum
   │    ├─ Set last_edited_by
   │    └─ Set last_edited_at
   │
   └─→ TRIGGER: update_file_editor_version
        └─ Automatically updates files.editor_version
   │
5. USER CLOSES EDITOR
   │
   └─→ CALL close_editor_session(session_token)
        ├─ Set closed_at = NOW()
        ├─ Delete from document_editor_locks
        ├─ Set files.is_locked = FALSE (if no other sessions)
        └─ Set changes_saved = TRUE/FALSE

┌─────────────────────────────────────────────────────────────────┐
│                    CONCURRENT EDITING FLOW                       │
└─────────────────────────────────────────────────────────────────┘

User A opens document
   ├─→ Create session A (is_collaborative = FALSE)
   └─→ Create exclusive lock

User B tries to open same document
   ├─→ Check for existing sessions
   ├─→ Found session A (active)
   ├─→ Set is_collaborative = TRUE for both sessions
   ├─→ Remove exclusive lock
   └─→ Both users can edit simultaneously

OnlyOffice handles conflict resolution
   └─→ Changes merged in real-time
```

## Stored Procedures Flow

```
┌────────────────────────────────────────────────────────────────┐
│                 STORED PROCEDURES & FUNCTIONS                   │
└────────────────────────────────────────────────────────────────┘

FUNCTIONS (Read-only operations):
┌────────────────────────────────────────────────────────────┐
│ generate_document_key(file_id, version, timestamp)        │
│   Returns: "file_123_v5_20251012143022"                   │
├────────────────────────────────────────────────────────────┤
│ is_file_editable(file_id, tenant_id)                      │
│   Returns: BOOLEAN                                         │
│   Checks: existence, lock status, mime type support       │
├────────────────────────────────────────────────────────────┤
│ get_document_type(mime_type)                              │
│   Returns: 'word' | 'cell' | 'slide' | NULL               │
│   Maps MIME types to OnlyOffice document types            │
├────────────────────────────────────────────────────────────┤
│ generate_session_token()                                   │
│   Returns: Unique session token                           │
│   Format: "sess_{MD5}_{UNIX_TIMESTAMP}"                   │
└────────────────────────────────────────────────────────────┘

PROCEDURES (Write operations):
┌────────────────────────────────────────────────────────────┐
│ open_editor_session(...)                                   │
│   Creates new editing session with lock                   │
│   Returns: session_id, session_token, editor_key          │
├────────────────────────────────────────────────────────────┤
│ close_editor_session(session_token, changes_saved)        │
│   Closes session and releases locks                       │
│   Returns: session summary                                │
├────────────────────────────────────────────────────────────┤
│ record_document_change(...)                               │
│   Records OnlyOffice callback data                        │
│   Returns: change_id                                      │
├────────────────────────────────────────────────────────────┤
│ get_concurrent_editors(file_id, tenant_id)               │
│   Lists all users editing a document                      │
│   Returns: user list with session info                    │
├────────────────────────────────────────────────────────────┤
│ extend_editor_lock(session_token, minutes)               │
│   Extends lock expiration for active session             │
│   Returns: new expiration time                            │
├────────────────────────────────────────────────────────────┤
│ cleanup_expired_editor_sessions(hours_old)               │
│   Soft deletes old inactive sessions                      │
│   Returns: cleanup summary                                │
├────────────────────────────────────────────────────────────┤
│ get_active_editor_sessions(tenant_id)                     │
│   Lists all active sessions for tenant                    │
│   Returns: session list with file and user info           │
└────────────────────────────────────────────────────────────┘

TRIGGERS:
┌────────────────────────────────────────────────────────────┐
│ update_file_editor_version                                 │
│   AFTER INSERT ON document_editor_changes                  │
│   When: save_status = 'completed'                         │
│   Action: Increment files.editor_version                   │
│           Update files.last_edited_by                      │
│           Update files.last_edited_at                      │
└────────────────────────────────────────────────────────────┘

VIEWS:
┌────────────────────────────────────────────────────────────┐
│ v_editor_statistics                                        │
│   Aggregated usage stats per tenant                       │
│   Columns: total_sessions, active_sessions,               │
│            unique_files_edited, unique_editors,            │
│            avg_session_minutes, last_activity              │
└────────────────────────────────────────────────────────────┘
```

## Security Model

```
┌─────────────────────────────────────────────────────────────────┐
│                      SECURITY ARCHITECTURE                       │
└─────────────────────────────────────────────────────────────────┘

TENANT ISOLATION (Row-Level Security):
┌────────────────────────────────────────────────────────────┐
│ All queries MUST include:                                  │
│   WHERE tenant_id = :current_tenant_id                     │
│         AND deleted_at IS NULL                             │
│                                                             │
│ Enforced by:                                               │
│   ✓ Foreign key constraints                                │
│   ✓ Composite indexes (tenant_id first)                    │
│   ✓ Application-level tenant context                       │
│   ✓ Stored procedures validate tenant_id                   │
└────────────────────────────────────────────────────────────┘

SESSION SECURITY:
┌────────────────────────────────────────────────────────────┐
│ session_token:                                             │
│   • Cryptographically random (MD5 + UUID + RAND)          │
│   • Unique constraint enforced                             │
│   • Time-limited (2 hour default)                          │
│   • IP address tracked                                     │
│   • User agent logged                                      │
│                                                             │
│ editor_key:                                                │
│   • Format: file_{id}_v{version}_{timestamp}              │
│   • Changes on each document modification                  │
│   • Prevents replay attacks                                │
└────────────────────────────────────────────────────────────┘

LOCK MANAGEMENT:
┌────────────────────────────────────────────────────────────┐
│ Exclusive Lock:                                            │
│   • Only one user can edit                                 │
│   • Prevents concurrent modifications                      │
│   • Auto-expires (2 hours default)                         │
│   • Extensible via extend_editor_lock()                    │
│                                                             │
│ Shared Lock (Collaborative):                               │
│   • Multiple users can edit                                │
│   • OnlyOffice handles conflict resolution                 │
│   • Changes merged in real-time                            │
└────────────────────────────────────────────────────────────┘

AUDIT TRAIL:
┌────────────────────────────────────────────────────────────┐
│ Every action logged:                                       │
│   ✓ Who (user_id)                                          │
│   ✓ What (action/change)                                   │
│   ✓ When (timestamps)                                      │
│   ✓ Where (ip_address)                                     │
│   ✓ How (user_agent)                                       │
│                                                             │
│ Retention:                                                 │
│   • Sessions: Soft delete (preserved indefinitely)         │
│   • Changes: Soft delete (preserved indefinitely)          │
│   • Locks: Hard delete (transient, auto-cleanup)           │
└────────────────────────────────────────────────────────────┘
```

---

**Legend:**
- `(PK)` = Primary Key
- `(FK)` = Foreign Key
- `(UNIQUE)` = Unique Constraint
- `←` = New/Modified
- `→` = References/Foreign Key
- `▲/│` = Relationship lines

**Last Updated:** 2025-10-12
**Version:** 1.0.0