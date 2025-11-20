-- Ripristino dati test per dashboard CollaboraNexio
-- Data: 2025-11-16
-- Scopo: Rimuovere deleted_at da progetti e task, aggiungere dati test

-- =====================================================
-- 1. RIPRISTINA PROGETTI TEST
-- =====================================================
UPDATE projects
SET deleted_at = NULL
WHERE id IN (1, 2, 3, 4, 5)
  AND tenant_id = 1;

-- =====================================================
-- 2. RIPRISTINA TASK TEST
-- =====================================================
UPDATE tasks
SET deleted_at = NULL
WHERE id IN (1, 2, 3, 4)
  AND tenant_id = 1;

-- =====================================================
-- 3. AGGIUNGI completed_at AL TASK DONE
-- =====================================================
UPDATE tasks
SET completed_at = DATE_SUB(NOW(), INTERVAL 5 DAY)
WHERE id = 3
  AND status = 'done'
  AND tenant_id = 1;

-- =====================================================
-- 4. AGGIUNGI FILE TEST PER "ULTIMI DOCUMENTI"
-- =====================================================
INSERT INTO files (tenant_id, name, file_path, file_size, mime_type, uploaded_by, folder_id, created_at, updated_at)
VALUES
(1, 'Contratto_Fornitore.pdf', '/uploads/1/contratto_691234abcd.pdf', 524288, 'application/pdf', 19, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'Report_Vendite_Q4.xlsx', '/uploads/1/report_691235bcde.xlsx', 102400, 'application/vnd.ms-excel', 19, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 'Presentazione_Progetto.pptx', '/uploads/1/presentazione_691236cdef.pptx', 2097152, 'application/vnd.ms-powerpoint', 19, NULL, DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 3 HOUR));

-- =====================================================
-- 5. AGGIUNGI EVENTI TEST PER "PROSSIMI EVENTI"
-- =====================================================
INSERT INTO calendar_events (tenant_id, title, description, start_datetime, end_datetime, all_day, organizer_id, created_at, updated_at)
VALUES
(1, 'Riunione Team Mensile', 'Review progresso progetti Q4 e pianificazione obiettivi', DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_ADD(DATE_ADD(NOW(), INTERVAL 2 DAY), INTERVAL 2 HOUR), 0, 19, NOW(), NOW()),
(1, 'Deadline Progetto Marketing', 'Consegna finale materiali campagna pubblicitaria', DATE_ADD(NOW(), INTERVAL 5 DAY), DATE_ADD(NOW(), INTERVAL 5 DAY), 1, 19, NOW(), NOW()),
(1, 'Workshop Formazione Team', 'Sessione formativa su nuove metodologie agili', DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(DATE_ADD(NOW(), INTERVAL 7 DAY), INTERVAL 4 HOUR), 0, 19, NOW(), NOW());

-- =====================================================
-- 6. AGGIUNGI TICKET TEST PER "ULTIMI TICKET"
-- =====================================================
INSERT INTO tickets (tenant_id, subject, description, status, urgency, created_by, assigned_to, created_at, updated_at)
VALUES
(1, 'Problema accesso File Manager', 'Alcuni utenti non riescono ad accedere al file manager. Errore 403 Forbidden.', 'open', 'high', 19, NULL, DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(1, 'Richiesta nuova funzionalità dashboard', 'Aggiungere grafici statistiche con andamento mensile dei progetti', 'open', 'medium', 19, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 'Bug calcolo percentuale task', 'La percentuale di progresso dei progetti non viene calcolata correttamente quando ci sono task senza completamento', 'in_progress', 'high', 19, 19, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1, 'Supporto configurazione email SMTP', 'Necessario configurare server SMTP per invio notifiche workflow', 'open', 'low', 19, NULL, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY));

-- =====================================================
-- VERIFICA DATI RIPRISTINATI
-- =====================================================
SELECT 'Projects restored' as operation, COUNT(*) as count FROM projects WHERE tenant_id = 1 AND deleted_at IS NULL
UNION ALL
SELECT 'Tasks restored', COUNT(*) FROM tasks WHERE tenant_id = 1 AND deleted_at IS NULL
UNION ALL
SELECT 'Files added', COUNT(*) FROM files WHERE tenant_id = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
UNION ALL
SELECT 'Events added', COUNT(*) FROM calendar_events WHERE tenant_id = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
UNION ALL
SELECT 'Tickets added', COUNT(*) FROM tickets WHERE tenant_id = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
