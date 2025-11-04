# CollaboraNexio API Documentation
## File Assignment & Document Workflow System

**Base URL:** `https://app.nexiosolution.it/CollaboraNexio`
**Development:** `http://localhost:8888/CollaboraNexio`

**Authentication:** All endpoints require:
- Session cookie (login first)
- CSRF token in `X-CSRF-Token` header
- Multi-tenant isolation (automatic by tenant_id)

---

## Table of Contents

1. [File Assignment APIs](#file-assignment-apis)
   - [Create Assignment](#1-create-assignment)
   - [List Assignments](#2-list-assignments)
   - [Revoke Assignment](#3-revoke-assignment)
   - [Check Access](#4-check-access)

2. [Workflow Roles APIs](#workflow-roles-apis)
   - [Configure Role](#5-configure-workflow-role)
   - [List Roles](#6-list-workflow-roles)

3. [Document Workflow APIs](#document-workflow-apis)
   - [Submit for Validation](#7-submit-document-for-validation)
   - [Validate Document](#8-validate-document)
   - [Reject Document](#9-reject-document)
   - [Approve Document](#10-approve-document-final)
   - [Recall Document](#11-recall-document)
   - [Workflow History](#12-get-workflow-history)
   - [Workflow Status](#13-get-workflow-status)
   - [Workflow Dashboard](#14-workflow-dashboard)

---

## File Assignment APIs

### 1. Create Assignment
**POST** `/api/files/assign.php`

Assigns a file or folder to a user with optional expiration.

**Authorization:** Manager, Admin, Super Admin

**Request Body:**
```json
{
  "file_id": 123,                // OR folder_id (one required)
  "assigned_to_user_id": 45,
  "assignment_reason": "Review required",  // optional
  "expires_at": "2025-12-31 23:59:59"     // optional
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "assignment": {
      "id": 1,
      "entity_type": "file",
      "entity_id": 123,
      "entity_name": "contract.pdf",
      "assigned_to": {
        "id": 45,
        "name": "Mario Rossi",
        "email": "mario@example.com"
      },
      "assigned_by": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com"
      },
      "assignment_reason": "Review required",
      "expires_at": "2025-12-31 23:59:59",
      "created_at": "2025-10-29 10:00:00"
    }
  },
  "message": "File assegnata con successo a Mario Rossi."
}
```

**cURL Example:**
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/api/files/assign.php" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID" \
  -d '{
    "file_id": 123,
    "assigned_to_user_id": 45,
    "assignment_reason": "Please review this contract",
    "expires_at": "2025-12-31 23:59:59"
  }'
```

---

### 2. List Assignments
**GET** `/api/files/assignments.php`

Lists file/folder assignments with filtering options.

**Query Parameters:**
- `file_id` (optional): Filter by file
- `folder_id` (optional): Filter by folder
- `user_id` (optional): Filter by user (managers only)
- `include_expired` (optional): Include expired assignments
- `page` (optional): Page number (default: 1)
- `per_page` (optional): Items per page (default: 20, max: 100)

**Response:**
```json
{
  "success": true,
  "data": {
    "assignments": [
      {
        "id": 1,
        "entity_type": "file",
        "entity": {
          "id": 123,
          "name": "contract.pdf",
          "size": 2048576,
          "mime_type": "application/pdf",
          "creator_id": 1
        },
        "assigned_to": {
          "id": 45,
          "name": "Mario Rossi",
          "email": "mario@example.com"
        },
        "assigned_by": {
          "id": 1,
          "name": "Admin User",
          "email": "admin@example.com"
        },
        "assignment_reason": "Review required",
        "expires_at": "2025-12-31 23:59:59",
        "is_expired": false,
        "is_expiring_soon": false,
        "created_at": "2025-10-29 10:00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total_records": 15,
      "total_pages": 1,
      "has_next": false,
      "has_prev": false
    },
    "statistics": {
      "active": 12,
      "expired": 3,
      "expiring_soon": 2,
      "unique_files": 10,
      "unique_folders": 2,
      "unique_users": 5
    }
  },
  "message": "Lista assegnazioni caricata con successo."
}
```

**cURL Example:**
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/files/assignments.php?file_id=123&include_expired=false" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID"
```

---

### 3. Revoke Assignment
**DELETE** `/api/files/assign.php`

Revokes a file/folder assignment (soft delete).

**Authorization:** Manager, Admin, Super Admin

**Request Body:**
```json
{
  "assignment_id": 1
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "assignment_id": 1
  },
  "message": "Assegnazione revocata con successo."
}
```

**cURL Example:**
```bash
curl -X DELETE "http://localhost:8888/CollaboraNexio/api/files/assign.php" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID" \
  -d '{"assignment_id": 1}'
```

---

### 4. Check Access
**GET** `/api/files/check-access.php`

Checks if a user has access to a file or folder.

**Query Parameters:**
- `file_id` OR `folder_id` (one required)
- `user_id` (optional): Defaults to current user

**Response:**
```json
{
  "success": true,
  "data": {
    "has_access": true,
    "reason": "Assegnazione diretta",
    "entity": {
      "type": "file",
      "id": 123,
      "name": "contract.pdf"
    },
    "user": {
      "id": 45,
      "name": "Mario Rossi",
      "email": "mario@example.com",
      "role": "user"
    },
    "details": {
      "access_type": "assigned",
      "assignment": {
        "id": 1,
        "reason": "Review required",
        "expires_at": "2025-12-31 23:59:59",
        "assigned_by": 1,
        "expiring_soon": false
      }
    }
  },
  "message": "Verifica accesso completata."
}
```

**cURL Example:**
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/files/check-access.php?file_id=123&user_id=45" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID"
```

---

## Workflow Roles APIs

### 5. Configure Workflow Role
**POST** `/api/workflow/roles/create.php`

Configures a user as validator or approver.

**Authorization:** Manager, Admin, Super Admin

**Request Body:**
```json
{
  "user_id": 45,
  "workflow_role": "validator",  // or "approver"
  "is_active": true
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "workflow_role": {
      "id": 1,
      "user": {
        "id": 45,
        "name": "Mario Rossi",
        "email": "mario@example.com",
        "system_role": "manager"
      },
      "workflow_role": "validator",
      "workflow_role_label": "Validatore",
      "is_active": true,
      "active_roles": ["validator"],
      "operation": "created",
      "configured_by": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com"
      }
    }
  },
  "message": "Mario Rossi configurato come Validatore con successo."
}
```

**cURL Example:**
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/api/workflow/roles/create.php" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID" \
  -d '{
    "user_id": 45,
    "workflow_role": "validator",
    "is_active": true
  }'
```

---

### 6. List Workflow Roles
**GET** `/api/workflow/roles/list.php`

Lists users with workflow roles.

**Query Parameters:**
- `role_filter` (optional): "validator" or "approver"
- `active_only` (optional): true/false (default: true)
- `include_system_role` (optional): Include user system role

**Response:**
```json
{
  "success": true,
  "data": {
    "users": [
      {
        "id": 45,
        "name": "Mario Rossi",
        "email": "mario@example.com",
        "profile_image": null,
        "workflow_roles": [
          {
            "role": "validator",
            "label": "Validatore",
            "is_active": true
          }
        ],
        "has_validator_role": true,
        "has_approver_role": false,
        "has_both_roles": false,
        "system_role": "manager"
      }
    ],
    "statistics": {
      "total_users": 5,
      "validators": 3,
      "approvers": 2,
      "both_roles": 1,
      "active_validators": 3,
      "active_approvers": 2
    },
    "available_users": [
      {
        "id": 67,
        "name": "Luigi Verdi",
        "email": "luigi@example.com",
        "system_role": "user"
      }
    ],
    "filters": {
      "role_filter": null,
      "active_only": true
    }
  },
  "message": "Lista ruoli workflow caricata con successo."
}
```

**cURL Example:**
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/workflow/roles/list.php?active_only=true" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID"
```

---

## Document Workflow APIs

### 7. Submit Document for Validation
**POST** `/api/documents/workflow/submit.php`

Starts or resubmits a document in the workflow.

**Request Body:**
```json
{
  "file_id": 123,
  "validator_id": 45,      // optional - uses first available
  "approver_id": 67,       // optional - uses first available
  "notes": "Please review urgently"  // optional
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "workflow": {
      "id": 1,
      "file_id": 123,
      "file_name": "contract.pdf",
      "state": "in_validazione",
      "state_label": "In Validazione",
      "state_color": "#ffc107",
      "validator": {
        "id": 45,
        "name": "Mario Rossi",
        "email": "mario@example.com"
      },
      "approver": {
        "id": 67,
        "name": "Luigi Verdi",
        "email": "luigi@example.com"
      },
      "creator": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com"
      },
      "submitted_at": "2025-10-29 10:00:00",
      "notes": "Please review urgently",
      "operation": "submitted"
    }
  },
  "message": "Documento \"contract.pdf\" inviato per validazione con successo."
}
```

**cURL Example:**
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/api/documents/workflow/submit.php" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID" \
  -d '{
    "file_id": 123,
    "notes": "First submission for review"
  }'
```

---

### 8. Validate Document
**POST** `/api/documents/workflow/validate.php`

Validator approves a document.

**Authorization:** Assigned validator or Admin

**Request Body:**
```json
{
  "file_id": 123,
  "comment": "Document looks good, approved for final review"  // optional
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "workflow": {
      "id": 1,
      "file_id": 123,
      "file_name": "contract.pdf",
      "state": "in_approvazione",
      "state_label": "In Approvazione",
      "state_color": "#fd7e14",
      "validator": {
        "id": 45,
        "name": "Mario Rossi",
        "email": "mario@example.com"
      },
      "approver": {
        "id": 67,
        "name": "Luigi Verdi",
        "email": "luigi@example.com"
      },
      "validated_at": "2025-10-29 11:00:00",
      "validated_by": {
        "id": 45,
        "name": "Mario Rossi",
        "email": "mario@example.com"
      },
      "comment": "Document looks good, approved for final review"
    }
  },
  "message": "Documento \"contract.pdf\" validato con successo. Ora in attesa di approvazione."
}
```

**cURL Example:**
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/api/documents/workflow/validate.php" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID" \
  -d '{
    "file_id": 123,
    "comment": "All checks passed"
  }'
```

---

### 9. Reject Document
**POST** `/api/documents/workflow/reject.php`

Rejects a document at any stage.

**Authorization:** Assigned validator/approver or Admin

**Request Body:**
```json
{
  "file_id": 123,
  "comment": "Missing required signature on page 3"  // REQUIRED (min 10 chars)
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "workflow": {
      "id": 1,
      "file_id": 123,
      "file_name": "contract.pdf",
      "state": "rifiutato",
      "state_label": "Rifiutato",
      "state_color": "#dc3545",
      "rejection": {
        "reason": "Missing required signature on page 3",
        "rejected_by": {
          "id": 45,
          "name": "Mario Rossi",
          "email": "mario@example.com",
          "role": "Validatore"
        },
        "rejected_at": "2025-10-29 11:30:00",
        "rejection_count": 1,
        "rejected_from_state": "in_validazione"
      },
      "creator": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com"
      },
      "next_action": "Il creatore può modificare il documento e reinviarlo per validazione"
    }
  },
  "message": "Documento \"contract.pdf\" rifiutato. Il creatore è stato notificato."
}
```

**cURL Example:**
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/api/documents/workflow/reject.php" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID" \
  -d '{
    "file_id": 123,
    "comment": "Please fix the issues mentioned in the email"
  }'
```

---

### 10. Approve Document (Final)
**POST** `/api/documents/workflow/approve.php`

Final approval of a document.

**Authorization:** Assigned approver or Admin

**Request Body:**
```json
{
  "file_id": 123,
  "comment": "Approved for publication"  // optional
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "workflow": {
      "id": 1,
      "file_id": 123,
      "file_name": "contract.pdf",
      "state": "approvato",
      "state_label": "Approvato",
      "state_color": "#28a745",
      "approval": {
        "approved_by": {
          "id": 67,
          "name": "Luigi Verdi",
          "email": "luigi@example.com"
        },
        "approved_at": "2025-10-29 12:00:00",
        "comment": "Approved for publication"
      },
      "validator": {
        "id": 45,
        "name": "Mario Rossi",
        "validated_at": "2025-10-29 11:00:00"
      },
      "workflow_duration": {
        "seconds": 7200,
        "days": 0,
        "hours": 2
      },
      "rejection_count": 0,
      "completion_message": "Workflow completato con successo. Il documento è ora approvato e disponibile."
    }
  },
  "message": "Documento \"contract.pdf\" approvato con successo. Workflow completato."
}
```

**cURL Example:**
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/api/documents/workflow/approve.php" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID" \
  -d '{
    "file_id": 123,
    "comment": "All good, approved!"
  }'
```

---

### 11. Recall Document
**POST** `/api/documents/workflow/recall.php`

Creator recalls document from workflow.

**Authorization:** Document creator or Admin

**Request Body:**
```json
{
  "file_id": 123,
  "reason": "Need to update section 2"  // optional
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "workflow": {
      "id": 1,
      "file_id": 123,
      "file_name": "contract.pdf",
      "state": "bozza",
      "state_label": "Bozza",
      "state_color": "#6c757d",
      "recall": {
        "recalled_from": "in_validazione",
        "recalled_from_label": "In Validazione",
        "recalled_by": {
          "id": 1,
          "name": "Admin User",
          "email": "admin@example.com"
        },
        "recalled_at": "2025-10-29 10:30:00",
        "reason": "Need to update section 2"
      },
      "notified": {
        "validator": "Mario Rossi"
      },
      "next_action": "Il documento può essere modificato e reinviato per validazione"
    }
  },
  "message": "Documento \"contract.pdf\" richiamato con successo. Ora in stato bozza."
}
```

**cURL Example:**
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/api/documents/workflow/recall.php" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID" \
  -d '{
    "file_id": 123,
    "reason": "Found an error that needs fixing"
  }'
```

---

### 12. Get Workflow History
**GET** `/api/documents/workflow/history.php`

Gets complete workflow history for a document.

**Query Parameters:**
- `file_id` (required)

**Response:**
```json
{
  "success": true,
  "data": {
    "history": [
      {
        "id": 1,
        "from_state": "bozza",
        "from_state_label": "Bozza",
        "from_state_color": "#6c757d",
        "to_state": "in_validazione",
        "to_state_label": "In Validazione",
        "to_state_color": "#ffc107",
        "transition_type": "submit",
        "transition_label": "Inviato per Validazione",
        "performed_by": {
          "id": 1,
          "name": "Admin User",
          "email": "admin@example.com",
          "role": "creator"
        },
        "comment": null,
        "created_at": "2025-10-29 10:00:00",
        "duration": "2 ore, 15 minuti",
        "duration_seconds": 8100
      }
    ],
    "timeline": [
      {
        "timestamp": "2025-10-29 10:00:00",
        "event": "Admin User ha inviato il documento per validazione",
        "type": "submit"
      }
    ],
    "statistics": {
      "total_transitions": 3,
      "total_rejections": 0,
      "total_duration": {
        "days": 0,
        "hours": 2,
        "minutes": 30
      },
      "average_transition_time": 2700,
      "current_state": "approvato",
      "current_state_label": "Approvato",
      "completion_percentage": 100
    },
    "file": {
      "id": 123,
      "name": "contract.pdf"
    },
    "current_workflow": {
      "id": 1,
      "state": "approvato",
      "state_label": "Approvato",
      "state_color": "#28a745",
      "validator": "Mario Rossi",
      "approver": "Luigi Verdi",
      "creator": "Admin User",
      "submitted_at": "2025-10-29 10:00:00",
      "validated_at": "2025-10-29 11:00:00",
      "approved_at": "2025-10-29 12:30:00"
    }
  },
  "message": "Storia workflow caricata con successo."
}
```

**cURL Example:**
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/documents/workflow/history.php?file_id=123" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID"
```

---

### 13. Get Workflow Status
**GET** `/api/documents/workflow/status.php`

Gets current workflow status and available actions.

**Query Parameters:**
- `file_id` (required)

**Response:**
```json
{
  "success": true,
  "data": {
    "file": {
      "id": 123,
      "name": "contract.pdf",
      "size": 2048576,
      "mime_type": "application/pdf",
      "creator_id": 1,
      "is_creator": true
    },
    "workflow_exists": true,
    "workflow": {
      "id": 1,
      "state": "in_validazione",
      "state_label": "In Validazione",
      "state_color": "#ffc107",
      "state_description": "Il documento è in attesa di validazione",
      "progress_percentage": 25,
      "participants": {
        "creator": {
          "id": 1,
          "name": "Admin User",
          "email": "admin@example.com"
        },
        "validator": {
          "id": 45,
          "name": "Mario Rossi",
          "status": "pending"
        },
        "approver": {
          "id": 67,
          "name": "Luigi Verdi",
          "status": "waiting"
        }
      },
      "dates": {
        "submitted_at": "2025-10-29 10:00:00"
      }
    },
    "available_actions": [
      {
        "action": "recall",
        "label": "Richiama Documento",
        "description": "Richiama il documento dal workflow",
        "endpoint": "/api/documents/workflow/recall.php",
        "method": "POST"
      },
      {
        "action": "view_history",
        "label": "Visualizza Storia",
        "description": "Visualizza la storia completa del workflow",
        "endpoint": "/api/documents/workflow/history.php",
        "method": "GET"
      }
    ],
    "user_role_in_workflow": "creator",
    "next_step": "Il documento è in revisione. Attendi il feedback."
  },
  "message": "Stato workflow recuperato con successo."
}
```

**cURL Example:**
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/documents/workflow/status.php?file_id=123" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID"
```

---

### 14. Workflow Dashboard
**GET** `/api/documents/workflow/dashboard.php`

Gets workflow dashboard statistics and pending actions.

**Query Parameters:**
- `user_id` (optional): Filter by user (admin only)
- `state` (optional): Filter by workflow state
- `date_from` (optional): Start date filter
- `date_to` (optional): End date filter
- `include_details` (optional): Include recent activity

**Response:**
```json
{
  "success": true,
  "data": {
    "statistics": {
      "total_workflows": 150,
      "by_state": {
        "draft": 10,
        "in_validation": 25,
        "validated": 5,
        "in_approval": 15,
        "approved": 90,
        "rejected": 5
      },
      "average_rejections": 0.3,
      "average_completion_hours": 48.5
    },
    "pending_actions": [
      {
        "type": "validation_required",
        "workflow_id": 123,
        "file_id": 456,
        "file_name": "report.pdf",
        "creator": "John Doe",
        "waiting_since": "2025-10-28 09:00:00",
        "hours_waiting": 25,
        "priority": "medium",
        "action_label": "Richiede Validazione",
        "action_url": "/workflow.php?file_id=456"
      }
    ],
    "pending_count": {
      "validation": 3,
      "approval": 2,
      "rejected": 1
    },
    "performance": {
      "avg_validation_hours": 24.5,
      "avg_approval_hours": 12.3,
      "completed_workflows": 90,
      "workflows_with_rejections": 15,
      "max_rejection_count": 3
    },
    "workflow_roles": {
      "active_validators": 5,
      "active_approvers": 3
    },
    "current_user": {
      "id": 45,
      "name": "Mario Rossi",
      "role": "manager",
      "is_validator": true,
      "is_approver": false
    }
  },
  "message": "Dashboard workflow caricata con successo."
}
```

**cURL Example:**
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/documents/workflow/dashboard.php?include_details=true" \
  -H "X-CSRF-Token: YOUR_CSRF_TOKEN" \
  -b "PHPSESSID=YOUR_SESSION_ID"
```

---

## Common Error Responses

All APIs follow the standard error format:

```json
{
  "success": false,
  "error": "Error message",
  "message": "User-friendly error description"
}
```

### HTTP Status Codes

- **200** OK - Request successful
- **400** Bad Request - Invalid input parameters
- **401** Unauthorized - Not authenticated
- **403** Forbidden - Not authorized for this action
- **404** Not Found - Resource not found
- **405** Method Not Allowed - Wrong HTTP method
- **500** Internal Server Error - Server error

---

## Testing Notes

### Prerequisites

1. **Login First:**
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/index.php" \
  -c cookies.txt \
  -d "email=admin@demo.local&password=Admin123!"
```

2. **Get CSRF Token:**
Look for `<meta name="csrf-token" content="TOKEN_HERE">` in any page response.

3. **Use Session Cookie:**
Include `-b cookies.txt` in all requests.

### Test Workflow

1. **Configure Roles:**
   - Create validator user
   - Create approver user

2. **Submit Document:**
   - Upload a file first
   - Submit to workflow with validator/approver IDs

3. **Validate:**
   - Login as validator
   - Call validate endpoint

4. **Approve:**
   - Login as approver
   - Call approve endpoint

5. **Check History:**
   - View complete workflow history

---

## Security Considerations

1. **Multi-Tenant Isolation:**
   - All queries filtered by `tenant_id`
   - Cross-tenant access prevented

2. **Role-Based Access:**
   - File assignments: Manager+ only
   - Workflow roles: Manager+ only
   - Document actions: Role-specific

3. **CSRF Protection:**
   - All POST/PUT/DELETE require token
   - Token in `X-CSRF-Token` header

4. **Audit Logging:**
   - All actions logged
   - Non-blocking pattern (BUG-029)

5. **Soft Delete:**
   - No hard deletes (except GDPR)
   - `deleted_at` timestamp pattern

---

## Support

For issues or questions:
- Email: support@nexiosolution.it
- Documentation: https://docs.nexiosolution.it

---

**Version:** 1.0.0
**Last Updated:** 2025-10-29
**Author:** CollaboraNexio Development Team