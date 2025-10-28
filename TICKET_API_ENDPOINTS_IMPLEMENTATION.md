# Ticket API Endpoints - Implementation Summary

**Data:** 2025-10-26
**Stato:** ✅ Completato
**Sviluppatore:** Claude Code - Senior Full Stack Engineer

## Overview

Implementati e aggiornati 3 endpoint API essenziali per il sistema di gestione ticket di CollaboraNexio:
1. **POST /api/tickets/respond.php** - Aggiunta risposte a ticket
2. **POST /api/tickets/update_status.php** - Cambio stato ticket
3. **POST /api/tickets/assign.php** - Assegnazione ticket a utenti

## 1. POST /api/tickets/respond.php

### Descrizione
Permette di aggiungere risposte a un ticket esistente, con supporto per note interne (solo admin+).

### Input JSON
```json
{
  "ticket_id": 123,
  "message": "Testo della risposta",
  "is_internal_note": false
}
```

**Nota:** L'endpoint supporta anche `response_text` come campo alternativo a `message` per backward compatibility.

### RBAC Implementation
- **super_admin:** Può rispondere a QUALSIASI ticket cross-tenant
- **admin:** Può rispondere a tutti i ticket del proprio tenant
- **user:** Può rispondere SOLO ai propri ticket

### Note Interne
- Solo admin+ possono creare note interne (`is_internal_note: true`)
- Le note interne NON inviano email notifications
- Visibili solo ad admin+ nella UI

### Features Implementate
✅ Tenant isolation con RBAC multi-livello
✅ Support cross-tenant per super_admin
✅ Validation lunghezza messaggio (max 10.000 caratteri)
✅ Transaction safety con rollback automatico
✅ Audit trail in `ticket_history`
✅ Auto-update `first_response_at` per admin
✅ Email notification al creatore ticket (escluse note interne)
✅ Check ticket chiuso (non permette risposte)

### Response Format
```json
{
  "success": true,
  "data": {
    "response": {
      "id": 456,
      "ticket_id": 123,
      "user_id": 789,
      "response_text": "Testo della risposta",
      "is_internal_note": 0,
      "user_name": "Nome Utente",
      "user_email": "utente@example.com",
      "created_at": "2025-10-26 10:00:00"
    },
    "response_id": 456,
    "ticket_updated": {
      "updated_at": "2025-10-26 10:00:00",
      "first_response_at": "2025-10-26 10:00:00",
      "first_response_time": 15.5
    }
  },
  "message": "Risposta aggiunta con successo"
}
```

### Security
- BUG-011 compliant (auth check IMMEDIATELY dopo initializeApiEnvironment)
- CSRF token validation su tutte le POST
- SQL injection prevention con prepared statements
- Tenant isolation enforcement

---

## 2. POST /api/tickets/update_status.php

### Descrizione
Aggiorna lo stato di un ticket esistente con audit trail completo.

### Input JSON
```json
{
  "ticket_id": 123,
  "status": "in_progress"
}
```

### Status Validi
- `open` - Aperto
- `in_progress` - In Lavorazione
- `waiting_response` - In Attesa di Risposta
- `resolved` - Risolto
- `closed` - Chiuso

### RBAC Implementation
- **super_admin:** Può modificare lo stato di QUALSIASI ticket cross-tenant
- **admin:** Può modificare lo stato dei ticket del proprio tenant
- **user:** ❌ NON può modificare lo stato

### Features Implementate
✅ Validation status (solo valori permessi)
✅ Check status unchanged (evita aggiornamenti duplicati)
✅ Auto-set `closed_by` e `closed_at` quando status = 'closed'
✅ Calcolo automatico `resolution_time` in ore quando chiuso
✅ Transaction safety con rollback automatico
✅ Audit trail in `ticket_history` con old_value/new_value
✅ Email notification al creatore per cambio stato
✅ Email specifica quando ticket chiuso (`sendTicketClosedNotification`)

### Response Format
```json
{
  "success": true,
  "data": {
    "ticket": {
      "id": 123,
      "ticket_number": "TICK-2025-0001",
      "subject": "Problema tecnico",
      "status": "closed",
      "closed_by": 789,
      "closed_at": "2025-10-26 10:00:00",
      "resolution_time": 24.5,
      "assigned_to_name": "Admin User",
      "created_by_name": "Customer User",
      "closed_by_name": "Admin User"
    },
    "old_status": "in_progress",
    "new_status": "closed"
  },
  "message": "Stato aggiornato con successo"
}
```

### Email Notifications
1. **Status Changed:** Inviata sempre al creatore con dettagli cambio
2. **Ticket Closed:** Inviata specificamente quando status = 'closed' con resolution time

---

## 3. POST /api/tickets/assign.php

### Descrizione
Assegna un ticket a un utente specifico o rimuove l'assegnazione.

### Input JSON
```json
{
  "ticket_id": 123,
  "assigned_to": 456
}
```

**Per de-assegnare:**
```json
{
  "ticket_id": 123,
  "assigned_to": 0
}
```

### RBAC Implementation
- **super_admin:** Può assegnare QUALSIASI ticket a QUALSIASI utente cross-tenant
- **admin:** Può assegnare ticket del proprio tenant a utenti del proprio tenant
- **user:** ❌ NON può assegnare ticket

### Features Implementate
✅ Cross-tenant assignment per super_admin
✅ Validation utente assegnato esiste e accessibile
✅ Check assignment unchanged (evita duplicati)
✅ Auto-set `first_response_at` al primo assignment
✅ Transaction safety con rollback automatico
✅ Logging in `ticket_assignments` per assignment history
✅ Audit trail in `ticket_history` con old/new value
✅ Email notification all'utente assegnato
✅ Check ticket chiuso (non permette assegnazione)

### Assignment History
Ogni assegnazione viene registrata in `ticket_assignments`:
```sql
{
  "ticket_id": 123,
  "assigned_to": 456,
  "assigned_by": 789,
  "assigned_at": "2025-10-26 10:00:00"
}
```

Questo permette di tracciare la cronologia completa delle assegnazioni.

### Response Format
```json
{
  "success": true,
  "data": {
    "ticket": {
      "id": 123,
      "ticket_number": "TICK-2025-0001",
      "assigned_to": 456,
      "assigned_to_name": "Tech Support User",
      "assigned_to_email": "tech@example.com",
      "created_by_name": "Customer User"
    },
    "assignment_id": 789,
    "assigned_to": {
      "id": 456,
      "name": "Tech Support User",
      "email": "tech@example.com",
      "tenant_id": 1
    }
  },
  "message": "Ticket assegnato con successo"
}
```

### Email Notification
Quando un ticket viene assegnato:
- Email inviata all'utente assegnato con `sendTicketAssignedNotification()`
- Include dettagli ticket (numero, subject, urgency, category)
- Include link diretto al ticket

---

## Database Schema Affected

### Tables Modified
1. **tickets** - Updated (`updated_at`, `assigned_to`, `status`, `closed_by`, `closed_at`, `resolution_time`, `first_response_at`, `first_response_time`)
2. **ticket_responses** - Inserted (nuova risposta)
3. **ticket_assignments** - Inserted (history assegnazioni)
4. **ticket_history** - Inserted (audit trail)
5. **ticket_notifications** - Inserted (tracking email notifications)

### Foreign Keys Respected
- ✅ `tickets.tenant_id` → `tenants.id` (CASCADE)
- ✅ `tickets.created_by` → `users.id` (RESTRICT)
- ✅ `tickets.assigned_to` → `users.id` (SET NULL)
- ✅ `ticket_responses.ticket_id` → `tickets.id` (CASCADE)
- ✅ `ticket_assignments.ticket_id` → `tickets.id` (CASCADE)

---

## Testing Notes

### Test Suite Raccomandato

**1. respond.php - Test Cases:**
```bash
# Test 1: User risponde al proprio ticket
POST /api/tickets/respond.php
{
  "ticket_id": <user_ticket_id>,
  "message": "Risposta test utente normale"
}
Expected: 200 OK, response created

# Test 2: User prova a rispondere a ticket di altri
POST /api/tickets/respond.php
{
  "ticket_id": <other_user_ticket_id>,
  "message": "Tentativo accesso non autorizzato"
}
Expected: 404 Not Found

# Test 3: Admin crea nota interna
POST /api/tickets/respond.php
{
  "ticket_id": <ticket_id>,
  "message": "Nota interna admin",
  "is_internal_note": true
}
Expected: 200 OK, no email sent

# Test 4: User prova a creare nota interna
POST /api/tickets/respond.php
{
  "ticket_id": <user_ticket_id>,
  "message": "Tentativo nota interna",
  "is_internal_note": true
}
Expected: 403 Forbidden

# Test 5: Super admin risponde cross-tenant
POST /api/tickets/respond.php (as super_admin)
{
  "ticket_id": <tenant_2_ticket_id>,
  "message": "Risposta cross-tenant"
}
Expected: 200 OK, tenant_id del ticket preservato

# Test 6: Risposta a ticket chiuso
POST /api/tickets/respond.php
{
  "ticket_id": <closed_ticket_id>,
  "message": "Risposta a ticket chiuso"
}
Expected: 403 Forbidden
```

**2. update_status.php - Test Cases:**
```bash
# Test 1: Admin cambia status in_progress → resolved
POST /api/tickets/update_status.php
{
  "ticket_id": <ticket_id>,
  "status": "resolved"
}
Expected: 200 OK, status changed, email sent

# Test 2: Admin chiude ticket
POST /api/tickets/update_status.php
{
  "ticket_id": <ticket_id>,
  "status": "closed"
}
Expected: 200 OK, closed_by set, closed_at set, resolution_time calculated

# Test 3: User prova a cambiare status
POST /api/tickets/update_status.php (as user)
{
  "ticket_id": <user_ticket_id>,
  "status": "closed"
}
Expected: 403 Forbidden

# Test 4: Status non valido
POST /api/tickets/update_status.php
{
  "ticket_id": <ticket_id>,
  "status": "invalid_status"
}
Expected: 400 Bad Request

# Test 5: Status unchanged
POST /api/tickets/update_status.php
{
  "ticket_id": <ticket_id>,
  "status": "in_progress"
}
(assumendo ticket già in_progress)
Expected: 400 Bad Request

# Test 6: Super admin cross-tenant
POST /api/tickets/update_status.php (as super_admin)
{
  "ticket_id": <tenant_2_ticket_id>,
  "status": "resolved"
}
Expected: 200 OK, cross-tenant access granted
```

**3. assign.php - Test Cases:**
```bash
# Test 1: Admin assegna ticket
POST /api/tickets/assign.php
{
  "ticket_id": <ticket_id>,
  "assigned_to": <user_id>
}
Expected: 200 OK, assignment created, email sent

# Test 2: Admin de-assegna ticket
POST /api/tickets/assign.php
{
  "ticket_id": <ticket_id>,
  "assigned_to": 0
}
Expected: 200 OK, assignment removed

# Test 3: User prova ad assegnare
POST /api/tickets/assign.php (as user)
{
  "ticket_id": <ticket_id>,
  "assigned_to": <user_id>
}
Expected: 403 Forbidden (requireApiRole blocca)

# Test 4: Assegnazione a utente inesistente
POST /api/tickets/assign.php
{
  "ticket_id": <ticket_id>,
  "assigned_to": 99999
}
Expected: 404 Not Found

# Test 5: Super admin assegna cross-tenant
POST /api/tickets/assign.php (as super_admin)
{
  "ticket_id": <tenant_1_ticket_id>,
  "assigned_to": <tenant_2_user_id>
}
Expected: 200 OK, cross-tenant assignment

# Test 6: Assegnazione ticket chiuso
POST /api/tickets/assign.php
{
  "ticket_id": <closed_ticket_id>,
  "assigned_to": <user_id>
}
Expected: 403 Forbidden

# Test 7: Assignment unchanged
POST /api/tickets/assign.php
{
  "ticket_id": <ticket_id>,
  "assigned_to": <already_assigned_user_id>
}
Expected: 400 Bad Request
```

### Test con cURL
```bash
# Setup session cookie
COOKIE_FILE=$(mktemp)

# Login (ottieni session cookie)
curl -c $COOKIE_FILE -X POST \
  http://localhost:8888/CollaboraNexio/api/auth/login.php \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@demo.local","password":"Admin123!"}'

# Ottieni CSRF token
CSRF_TOKEN=$(curl -b $COOKIE_FILE \
  http://localhost:8888/CollaboraNexio/api/auth/csrf-token.php \
  | jq -r '.data.csrf_token')

# Test respond endpoint
curl -b $COOKIE_FILE -X POST \
  http://localhost:8888/CollaboraNexio/api/tickets/respond.php \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF_TOKEN" \
  -d '{"ticket_id":1,"message":"Test response"}'

# Test update_status endpoint
curl -b $COOKIE_FILE -X POST \
  http://localhost:8888/CollaboraNexio/api/tickets/update_status.php \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF_TOKEN" \
  -d '{"ticket_id":1,"status":"in_progress"}'

# Test assign endpoint
curl -b $COOKIE_FILE -X POST \
  http://localhost:8888/CollaboraNexio/api/tickets/assign.php \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF_TOKEN" \
  -d '{"ticket_id":1,"assigned_to":2}'
```

---

## Email Notification Testing

### Template Files Required
Assicurarsi che esistano in `/includes/email_templates/tickets/`:
- ✅ `ticket_created.html` - Già esistente
- ✅ `ticket_created_confirmation.html` - Già esistente
- ✅ `ticket_assigned.html` - Già esistente
- ✅ `ticket_response.html` - Già esistente
- ✅ `status_changed.html` - Già esistente

### Notification Preferences
Le notifiche rispettano le preferenze utente in `user_notification_preferences`:
- `notify_ticket_created` - Notifica creazione ticket (super_admin)
- `notify_ticket_assigned` - Notifica assegnazione
- `notify_ticket_response` - Notifica nuova risposta
- `notify_ticket_status` - Notifica cambio stato
- `notify_ticket_closed` - Notifica chiusura

Default: TRUE (opt-in automatico)

---

## Performance Considerations

### Query Optimization
- Tutti gli endpoint usano prepared statements
- Composite indexes su `(tenant_id, created_at)` per fast filtering
- Transaction safety: COMMIT su successo, ROLLBACK su errore
- Single query per fetch ticket details con LEFT JOINs

### Response Times Target
- respond.php: < 150ms (include email dispatch)
- update_status.php: < 120ms
- assign.php: < 100ms

### Email Performance
- Email dispatch è NON-BLOCKING (try-catch isolato)
- Fallimenti email NON impediscono successo operazione
- Tutti i tentativi email loggati in `ticket_notifications`

---

## Error Handling

### Validation Errors (400)
- Missing required fields
- Invalid status value
- Message too long (>10,000 chars)
- Status unchanged
- Assignment unchanged

### Authorization Errors (403)
- Non-admin prova a cambiare status
- Non-admin prova ad assegnare ticket
- User prova a creare nota interna
- Risposta a ticket chiuso
- Assegnazione a ticket chiuso

### Not Found Errors (404)
- Ticket non esiste
- Ticket non accessibile (RBAC)
- Utente assegnato non esiste

### Server Errors (500)
- Database errors
- Transaction rollback
- Unexpected exceptions

Tutti gli errori loggati in `/logs/php_errors.log` con stack trace completo.

---

## Security Checklist

✅ BUG-011 Compliant - Auth check IMMEDIATELY dopo initializeApiEnvironment
✅ CSRF token validation su tutte le POST
✅ SQL injection prevention (prepared statements)
✅ Tenant isolation enforcement con RBAC multi-livello
✅ Input validation completa
✅ Output sanitization (htmlspecialchars in templates)
✅ Error messages NON leak sensitive data
✅ Transaction safety per data integrity
✅ Audit trail completo in ticket_history
✅ Email notification logging

---

## Files Modified

1. `/api/tickets/respond.php` - Enhanced RBAC cross-tenant support
2. `/api/tickets/assign.php` - Enhanced RBAC cross-tenant support
3. `/api/tickets/update_status.php` - Already had cross-tenant support (no changes)

---

## Backward Compatibility

✅ `respond.php` supporta sia `message` che `response_text` nel JSON
✅ `assign.php` supporta `assigned_to: 0` per de-assegnazione
✅ Nessun breaking change agli endpoint esistenti
✅ Response format consistente con pattern CollaboraNexio

---

## Next Steps (Optional Enhancements)

1. **Batch Operations:**
   - Assign multiple tickets simultaneously
   - Bulk status update

2. **Advanced Notifications:**
   - WebSocket real-time notifications
   - SMS notifications per urgency=critical
   - Slack integration

3. **Escalation Rules:**
   - Auto-assign dopo N ore senza risposta
   - Auto-escalate a super_admin se SLA violated

4. **Analytics:**
   - Average response time per category
   - Ticket resolution trends
   - User performance metrics

---

## Conclusione

I 3 endpoint API sono production-ready con:
- ✅ Complete RBAC implementation con cross-tenant support
- ✅ Transaction safety e data integrity
- ✅ Comprehensive error handling
- ✅ Email notifications con user preferences
- ✅ Complete audit trail
- ✅ Performance optimized
- ✅ Security hardened

Sistema pronto per integrazione frontend e testing end-to-end.

---

**Data Completamento:** 2025-10-26
**Stato:** ✅ COMPLETATO - PRODUCTION READY
**Token Consumption:** ~100,000 / 200,000 (50%)
**Remaining:** ~100,000 (50%)
