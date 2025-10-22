-- ============================================================================
-- Test Folder Delete - Query di Verifica
-- ============================================================================
-- Queste query permettono di verificare lo stato delle cartelle di test
-- e l'efficacia del sistema di soft-delete
-- ============================================================================

-- Query 1: Visualizza tutte le cartelle di test (anche eliminate)
-- ============================================================================
SELECT
    id,
    name,
    file_path,
    tenant_id,
    is_folder,
    folder_id AS parent_folder_id,
    uploaded_by,
    created_at,
    updated_at,
    deleted_at,
    CASE
        WHEN deleted_at IS NULL THEN '✓ Attiva'
        ELSE '✗ Eliminata'
    END AS status
FROM files
WHERE id IN (32, 33, 34, 35)
ORDER BY id;

-- Query 2: Conteggio elementi per cartella (per verificare se è vuota)
-- ============================================================================
SELECT
    f.id AS folder_id,
    f.name AS folder_name,
    COUNT(DISTINCT CASE WHEN child.is_folder = 1 AND child.deleted_at IS NULL THEN child.id END) AS subfolder_count,
    COUNT(DISTINCT CASE WHEN child.is_folder = 0 AND child.deleted_at IS NULL THEN child.id END) AS file_count,
    COUNT(DISTINCT CASE WHEN child.deleted_at IS NULL THEN child.id END) AS total_active_items,
    CASE
        WHEN COUNT(DISTINCT CASE WHEN child.deleted_at IS NULL THEN child.id END) = 0 THEN '✓ Vuota - Eliminabile'
        ELSE '✗ Non vuota - Non eliminabile'
    END AS can_delete
FROM files f
LEFT JOIN files child ON child.folder_id = f.id
WHERE f.id IN (32, 33, 34, 35)
    AND f.is_folder = 1
GROUP BY f.id, f.name
ORDER BY f.id;

-- Query 3: Verifica gerarchia cartelle (parent-child)
-- ============================================================================
SELECT
    parent.id AS parent_id,
    parent.name AS parent_name,
    parent.deleted_at AS parent_deleted,
    child.id AS child_id,
    child.name AS child_name,
    child.deleted_at AS child_deleted,
    CASE
        WHEN parent.deleted_at IS NULL AND child.deleted_at IS NULL THEN '✓ Entrambi attivi'
        WHEN parent.deleted_at IS NOT NULL AND child.deleted_at IS NOT NULL THEN '✓ Entrambi eliminati'
        WHEN parent.deleted_at IS NOT NULL AND child.deleted_at IS NULL THEN '⚠ Inconsistenza: parent eliminato, child attivo'
        WHEN parent.deleted_at IS NULL AND child.deleted_at IS NOT NULL THEN 'Child eliminato, parent attivo'
    END AS consistency_check
FROM files parent
LEFT JOIN files child ON child.folder_id = parent.id
WHERE parent.id IN (32, 33, 34, 35) AND parent.is_folder = 1
ORDER BY parent.id, child.id;

-- Query 4: Lista tutte le cartelle vuote eliminabili del tenant 1
-- ============================================================================
SELECT
    f.id,
    f.name,
    f.file_path,
    f.folder_id AS parent_folder_id,
    COUNT(child.id) AS child_count,
    '✓ Eliminabile' AS status
FROM files f
LEFT JOIN files child ON child.folder_id = f.id AND child.deleted_at IS NULL
WHERE f.tenant_id = 1
    AND f.is_folder = 1
    AND f.deleted_at IS NULL
GROUP BY f.id, f.name, f.file_path, f.folder_id
HAVING COUNT(child.id) = 0
ORDER BY f.name;

-- Query 5: Audit log delle eliminazioni
-- ============================================================================
SELECT
    al.id AS audit_id,
    al.action,
    al.entity_type,
    al.entity_id,
    al.details,
    al.created_at AS deleted_at,
    u.username AS deleted_by,
    al.ip_address
FROM audit_logs al
LEFT JOIN users u ON al.user_id = u.id
WHERE al.action IN ('delete_file', 'delete_folder')
    AND al.entity_id IN (32, 33, 34, 35)
ORDER BY al.created_at DESC
LIMIT 20;

-- Query 6: Ripristina una cartella eliminata (soft-delete undo)
-- ============================================================================
-- ATTENZIONE: Usa questa query solo se necessario ripristinare una cartella
/*
UPDATE files
SET deleted_at = NULL
WHERE id = 34; -- Cambia con l'ID della cartella da ripristinare
*/

-- Query 7: Verifica integrità referenziale (foreign keys)
-- ============================================================================
SELECT
    f.id,
    f.name,
    f.folder_id,
    parent.name AS parent_name,
    parent.deleted_at AS parent_deleted,
    CASE
        WHEN f.folder_id IS NULL THEN '✓ Root folder'
        WHEN parent.id IS NULL THEN '✗ ERRORE: Parent non trovato'
        WHEN parent.deleted_at IS NOT NULL THEN '⚠ Parent eliminato'
        ELSE '✓ OK'
    END AS integrity_status
FROM files f
LEFT JOIN files parent ON f.folder_id = parent.id
WHERE f.id IN (32, 33, 34, 35) AND f.is_folder = 1
ORDER BY f.id;

-- Query 8: Statistiche eliminazioni per tenant
-- ============================================================================
SELECT
    tenant_id,
    COUNT(CASE WHEN is_folder = 1 AND deleted_at IS NULL THEN 1 END) AS active_folders,
    COUNT(CASE WHEN is_folder = 1 AND deleted_at IS NOT NULL THEN 1 END) AS deleted_folders,
    COUNT(CASE WHEN is_folder = 0 AND deleted_at IS NULL THEN 1 END) AS active_files,
    COUNT(CASE WHEN is_folder = 0 AND deleted_at IS NOT NULL THEN 1 END) AS deleted_files
FROM files
WHERE tenant_id = 1
GROUP BY tenant_id;

-- Query 9: Trova cartelle orfane (senza parent valido)
-- ============================================================================
SELECT
    f.id,
    f.name,
    f.folder_id AS expected_parent_id,
    'Cartella orfana - parent non esiste' AS issue
FROM files f
WHERE f.folder_id IS NOT NULL
    AND f.is_folder = 1
    AND f.deleted_at IS NULL
    AND NOT EXISTS (
        SELECT 1
        FROM files parent
        WHERE parent.id = f.folder_id
            AND parent.is_folder = 1
            AND parent.deleted_at IS NULL
    );

-- Query 10: Pulisci cartelle di test (SOLO PER SVILUPPO)
-- ============================================================================
-- ATTENZIONE: Questa query elimina permanentemente le cartelle di test
-- Usa solo in ambiente di sviluppo!
/*
DELETE FROM files
WHERE id IN (32, 33, 34, 35);

-- Resetta auto-increment se necessario
ALTER TABLE files AUTO_INCREMENT = 32;
*/

-- ============================================================================
-- Query di supporto per debugging
-- ============================================================================

-- Visualizza schema della tabella files
DESCRIBE files;

-- Visualizza tutti gli indici
SHOW INDEX FROM files;

-- Verifica foreign key constraints
SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'collaboranexio' -- Cambia con il nome del tuo database
    AND TABLE_NAME = 'files'
    AND REFERENCED_TABLE_NAME IS NOT NULL;
